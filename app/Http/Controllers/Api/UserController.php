<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Gestion des utilisateurs et de leurs rôles (BF02).
 *
 * Règles de correction :
 *   - L'administrateur ne crée que des comptes administrateurs : les
 *     comptes étudiants et enseignants proviennent obligatoirement
 *     de l'inscription autonome.
 *   - L'administrateur n'édite PAS les informations personnelles des
 *     étudiants / enseignants (ils les gèrent depuis « Mon Profil »).
 *     Les actions admises : consulter, autoriser le compte, supprimer.
 *   - L'autorisation de compte (est_autorise) contrôle l'accès aux
 *     espaces protégés, sur le web comme sur le mobile.
 */
class UserController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Liste des utilisateurs avec filtres (rôle, recherche).
     */
    public function index(Request $request)
    {
        $query = User::with(['role', 'etudiant.promotion.parcours', 'etudiant.promotion.formation', 'enseignant'])
            ->orderBy('name');

        if ($request->filled('role')) {
            $query->whereHas('role', fn ($q) => $q->where('name', $request->string('role')->toString()));
        }

        if ($request->filled('recherche')) {
            $terme = '%' . $request->string('recherche')->toString() . '%';
            $query->where(fn ($q) => $q->whereLike('name', $terme)->orWhereLike('email', $terme));
        }

        return response()->json(
            $query->paginate(min(max($request->integer('per_page', 20), 1), 100))
        );
    }

    /**
     * Liste des rôles disponibles.
     */
    public function roles()
    {
        return response()->json(Role::orderBy('id')->get());
    }

    /**
     * Création d'un utilisateur : réservée aux comptes
     * ADMINISTRATEURS. Les étudiants et enseignants s'inscrivent
     * eux-mêmes depuis le formulaire d'inscription.
     */
    public function store(Request $request)
    {
        $roleAdminId = Role::where('name', 'administrateur')->value('id');

        $donnees = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ], [
            'email.unique' => 'Cette adresse e-mail est déjà utilisée. Choisissez une adresse différente.',
        ]);

        $user = User::create([
            'name' => $donnees['name'],
            'email' => $donnees['email'],
            'password' => Hash::make($donnees['password']),
            'role_id' => $roleAdminId,
            'est_autorise' => true, // un compte créé par l'administrateur est directement actif
        ]);

        $this->audit->enregistrer(
            'CREATION_UTILISATEUR',
            'users',
            $user->id,
            "Création du compte administrateur {$user->email}."
        );

        return response()->json([
            'message' => 'Compte administrateur créé.',
            'user' => $user->load('role'),
        ], 201);
    }

    /**
     * Détail d'un utilisateur.
     */
    public function show(User $user)
    {
        return response()->json(
            $user->load(['role', 'etudiant.promotion.parcours.formation', 'enseignant'])
        );
    }

    /**
     * Mise à jour d'un utilisateur : réservée aux comptes
     * ADMINISTRATEURS (nom, e-mail, mot de passe éventuel). Les
     * comptes étudiants et enseignants ne sont PAS modifiables ici :
     * leurs informations personnelles sont gérées depuis
     * « Mon Profil ».
     */
    public function update(Request $request, User $user)
    {
        $roleCible = $user->role?->name;

        if (in_array($roleCible, ['etudiant', 'enseignant'], true)) {
            return response()->json([
                'message' => 'Les informations d\'un étudiant ou d\'un enseignant ne peuvent pas être '
                    .'modifiées par l\'administration. L\'utilisateur les gère lui-même depuis « Mon Profil ».',
            ], 403);
        }

        $donnees = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        if (isset($donnees['password'])) {
            $donnees['password'] = Hash::make($donnees['password']);
        } else {
            unset($donnees['password']);
        }

        $user->update($donnees);

        $this->audit->enregistrer(
            'MODIFICATION_UTILISATEUR',
            'users',
            $user->id,
            "Modification du compte {$user->email}."
        );

        return response()->json([
            'message' => 'Utilisateur mis à jour.',
            'user' => $user->fresh()->load('role'),
        ]);
    }

    /**
     * Suppression d'un utilisateur (prohibée si le profil associé
     * participe déjà au processus).
     */
    public function destroy(User $user)
    {
        if ($user->id === request()->user()->id) {
            return response()->json(['message' => 'Vous ne pouvez pas supprimer votre propre compte.'], 422);
        }

        $participe = ($user->etudiant && $user->etudiant->soutenances()->exists())
            || ($user->enseignant && ($user->enseignant->evaluations()->exists() || $user->enseignant->membresJury()->exists()));

        if ($participe) {
            return response()->json([
                'message' => 'Cet utilisateur participe déjà au processus de soutenance : suppression impossible.',
            ], 409);
        }

        $email = $user->email;
        $user->delete();

        $this->audit->enregistrer(
            'SUPPRESSION_UTILISATEUR',
            'users',
            $user->id,
            "Suppression du compte {$email}."
        );

        return response()->json(['message' => 'Utilisateur supprimé.']);
    }

    /**
     * Autorisation (ou révocation) d'un compte étudiant ou
     * enseignant. L'autorisation est immédiatement prise en compte
     * par le backend : elle vaut sur le web ET sur le mobile, pour un
     * seul et même compte.
     */
    public function autoriser(Request $request, User $user)
    {
        $roleCible = $user->role?->name;

        if (!in_array($roleCible, ['etudiant', 'enseignant'], true)) {
            return response()->json([
                'message' => 'Seuls les comptes étudiants et enseignants nécessitent une autorisation.',
            ], 422);
        }

        $donnees = $request->validate([
            'est_autorise' => ['required', 'boolean'],
        ]);

        $ancien = $user->est_autorise;
        $user->est_autorise = $donnees['est_autorise'];
        $user->save();

        if ($user->est_autorise) {
            $this->notifications->envoyer(
                $user->id,
                'Compte autorisé',
                'Votre compte vient d\'être autorisé par l\'administration. Vous pouvez maintenant '
                    .'vous connecter et accéder à votre espace.',
                'autorisation',
                ['user_id' => $user->id]
            );
        }

        $this->audit->enregistrer(
            $user->est_autorise ? 'AUTORISATION_COMPTE' : 'REVOCATION_AUTORISATION_COMPTE',
            'users',
            $user->id,
            "Autorisation du compte {$user->email} " . ($user->est_autorise ? 'accordée' : 'révoquée') . '.'
        );

        return response()->json([
            'message' => $user->est_autorise
                ? 'Compte autorisé : l\'utilisateur peut se connecter.'
                : 'Autorisation du compte révoquée.',
            'user' => $user->fresh()->load(['role', 'etudiant.promotion.parcours', 'enseignant']),
        ]);
    }
}
