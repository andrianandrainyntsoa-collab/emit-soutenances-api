<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Enseignant;
use App\Models\Etudiant;
use App\Models\Formation;
use App\Models\Parcours;
use App\Models\Promotion;
use App\Services\AuditService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Inscription autonome des étudiants et des enseignants.
 *
 * Chaque utilisateur choisit son propre mot de passe lors de
 * l'inscription : le compte créé apparaît ensuite dans la partie
 * « Utilisateurs » de l'administration.
 *
 * Règles :
 *   - Numéro étudiant au format numero-formation-annee
 *     (ex : 312CM24 ou 312SDIA24).
 *   - L'étudiant nouvellement inscrit n'est PAS autorisé à soutenir
 *     automatiquement (RG01 : l'administrateur autorise ensuite).
 *   - L'enseignant inscrit est immédiatement actif.
 */
class RegisterController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Référentiels publics (formations, parcours, promotions) pour les
     * formulaires d'inscription — accessibles sans authentification.
     */
    public function referentiels()
    {
        return response()->json([
            'formations' => Formation::orderBy('nom')->get(),
            'parcours' => Parcours::with('formation')->orderBy('nom')->get(),
            'promotions' => Promotion::with(['formation', 'parcours'])
                ->orderByDesc('annee_universitaire')
                ->get(),
        ]);
    }

    /**
     * Inscription d'un étudiant (choix de son propre mot de passe).
     *
     * Le compte est créé « en attente d'autorisation » : il ne peut
     * accéder aux fonctionnalités protégées qu'après autorisation par
     * un administrateur (la même autorisation vaut sur le web et sur
     * le mobile puisque le compte est unique).
     */
    public function etudiant(Request $request)
    {
        $donnees = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'numero_etudiant' => [
                'required',
                'string',
                'max:30',
                'unique:etudiants,numero_etudiant',
                'regex:/^\d{1,6}[A-Za-z]{2,10}\d{2}$/',
            ],
            'promotion_id' => ['required', 'integer', 'exists:promotions,id'],
            'telephone' => ['nullable', 'string', 'max:30'],
        ], [
            'numero_etudiant.regex' => 'Le numéro étudiant doit suivre le format '
                .'numero-formation-annee (ex : 312CM24 ou 312SDIA24).',
            'name.required' => 'Le nom complet est obligatoire.',
            'email.required' => 'L’adresse e-mail est obligatoire.',
            'email.email' => 'Saisissez une adresse e-mail valide.',
            'email.unique' => 'Cette adresse e-mail est déjà utilisée. Utilisez une autre adresse ou connectez-vous.',
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
            'numero_etudiant.required' => 'Le numéro étudiant est obligatoire.',
            'numero_etudiant.unique' => 'Ce numéro étudiant est déjà utilisé.',
            'promotion_id.required' => 'Choisissez une promotion.',
            'promotion_id.exists' => 'La promotion choisie n’existe plus. Actualisez la liste et réessayez.',
        ]);

        $etudiant = DB::transaction(function () use ($donnees) {
            $roleEtudiantId = DB::table('roles')->where('name', 'etudiant')->value('id');

            $user = \App\Models\User::create([
                'name' => $donnees['name'],
                'email' => $donnees['email'],
                'password' => \Illuminate\Support\Facades\Hash::make($donnees['password']),
                'role_id' => $roleEtudiantId,
                'est_autorise' => false, // compte en attente d'autorisation
            ]);

            return Etudiant::create([
                'user_id' => $user->id,
                'promotion_id' => $donnees['promotion_id'],
                'numero_etudiant' => $donnees['numero_etudiant'],
                'telephone' => $donnees['telephone'] ?? null,
                'autorise_soutenance' => false, // RG01 : autorisation par l'administrateur
            ]);
        });

        $this->notifications->envoyer(
            $etudiant->user_id,
            'Bienvenue sur la plateforme',
            'Votre compte étudiant a été créé avec succès. Il est en attente d\'autorisation '
                .'par l\'administration. Notez bien votre mot de passe : il vous sera '
                .'demandé à chaque connexion.',
            'compte',
            ['etudiant_id' => $etudiant->id]
        );

        $this->audit->enregistrer(
            'INSCRIPTION_ETUDIANT',
            'etudiants',
            $etudiant->id,
            "Inscription autonome de l'étudiant {$donnees['numero_etudiant']} ({$donnees['email']})."
        );

        return response()->json([
            'message' => 'Inscription réussie. Votre compte étudiant a été créé et est en '
                .'attente d\'autorisation par l\'administration. Vous serez averti(e) dès '
                .'qu\'il sera activé, sur le web comme sur le mobile.',
            'user' => ['id' => $etudiant->user_id, 'email' => $donnees['email']],
        ], 201);
    }

    /**
     * Inscription d'un enseignant (choix de son propre mot de passe).
     *
     * Les champs « matricule » et « grade » ne font plus partie du
     * formulaire d'inscription : le profil enseignant est renseigné
     * par l'enseignant lui-même depuis « Mon Profil » (nom, mot de
     * passe, spécialité, téléphone).
     *
     * Le compte est créé « en attente d'autorisation » : il ne peut
     * accéder aux fonctionnalités protégées qu'après autorisation par
     * un administrateur (la même autorisation vaut sur le web et sur
     * le mobile puisque le compte est unique).
     */
    public function enseignant(Request $request)
    {
        $donnees = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'specialite' => ['nullable', 'string', 'max:150'],
            'telephone' => ['nullable', 'string', 'max:30'],
        ], [
            'name.required' => 'Le nom complet est obligatoire.',
            'email.required' => 'L’adresse e-mail est obligatoire.',
            'email.email' => 'Saisissez une adresse e-mail valide.',
            'email.unique' => 'Cette adresse e-mail est déjà utilisée. Utilisez une autre adresse ou connectez-vous.',
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
            'specialite.max' => 'La spécialité ne peut pas dépasser 150 caractères.',
            'telephone.max' => 'Le numéro de téléphone ne peut pas dépasser 30 caractères.',
        ]);

        $enseignant = DB::transaction(function () use ($donnees) {
            $roleEnseignantId = DB::table('roles')->where('name', 'enseignant')->value('id');

            $user = \App\Models\User::create([
                'name' => $donnees['name'],
                'email' => $donnees['email'],
                'password' => \Illuminate\Support\Facades\Hash::make($donnees['password']),
                'role_id' => $roleEnseignantId,
                'est_autorise' => false, // compte en attente d'autorisation
            ]);

            return Enseignant::create([
                'user_id' => $user->id,
                'specialite' => $donnees['specialite'] ?? null,
                'telephone' => $donnees['telephone'] ?? null,
            ]);
        });

        $this->notifications->envoyer(
            $enseignant->user_id,
            'Bienvenue sur la plateforme',
            'Votre compte enseignant a été créé avec succès. Vous pourrez y retrouver vos jurys, '
                .'vos évaluations et vos soutenances. Notez bien votre mot de passe : il vous '
                .'sera demandé à chaque connexion.',
            'compte',
            ['enseignant_id' => $enseignant->id]
        );

        $this->audit->enregistrer(
            'INSCRIPTION_ENSEIGNANT',
            'enseignants',
            $enseignant->id,
            "Inscription autonome de l'enseignant {$donnees['email']}."
        );

        return response()->json([
            'message' => 'Inscription réussie. Votre compte enseignant a été créé et est en '
                .'attente d\'autorisation par l\'administration. Vous serez averti(e) dès '
                .'qu\'il sera activé, sur le web comme sur le mobile.',
            'user' => ['id' => $enseignant->user_id, 'email' => $donnees['email']],
        ], 201);
    }
}
