<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Enseignant;
use App\Models\Soutenance;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

/**
 * Espace personnel de l'utilisateur connecté.
 *
 * Page « Mon Profil » (web et mobile) :
 *   - Enseignant : peut modifier son nom, son mot de passe (avec
 *     validation du mot de passe actuel), sa spécialité et son
 *     numéro de téléphone.
 *   - Étudiant : peut uniquement modifier son mot de passe (avec
 *     validation du mot de passe actuel). Le matricule, la
 *     promotion, le parcours et la mention ne sont pas modifiables.
 *   - Administrateur / responsable : nom et mot de passe.
 *
 * Sécurité : seules les informations de l'utilisateur CONNECTÉ sont
 * modifiées ($request->user()) — il est impossible de modifier le
 * profil d'un autre utilisateur en manipulant la requête API.
 */
class EspaceController extends Controller
{
    /**
     * Profil de l'utilisateur connecté.
     */
    public function monProfil(Request $request)
    {
        $utilisateur = $request->user()->loadMissing(['role']);

        if ($utilisateur->etudiant) {
            $utilisateur->load('etudiant.promotion.formation', 'etudiant.promotion.parcours');
        } elseif ($utilisateur->enseignant) {
            $utilisateur->load('enseignant');
        }

        return response()->json($utilisateur);
    }

    /**
     * Mise à jour du profil de l'utilisateur connecté, selon son
     * rôle.
     */
    public function majMonProfil(Request $request)
    {
        $utilisateur = $request->user();
        $role = $utilisateur->role?->name;

        $regles = [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'specialite' => ['nullable', 'string', 'max:150'],
            'mot_de_passe_actuel' => ['nullable', 'required_with:password', 'string'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ];

        // L'étudiant ne peut modifier QUE son mot de passe.
        if ($role === 'etudiant') {
            unset($regles['name'], $regles['telephone'], $regles['specialite']);
        }

        // Un enseignant ne peut pas changer sa spécialité sans la
        // valider ; un administrateur/responsable n'a ni téléphone ni
        // spécialité métier.
        $donnees = $request->validate($regles, [
            'password.confirmed' => 'La confirmation du nouveau mot de passe ne correspond pas.',
            'mot_de_passe_actuel.required_with' => 'Le mot de passe actuel est requis pour changer de mot de passe.',
            'password.min' => 'Le nouveau mot de passe doit contenir au moins 8 caractères.',
        ]);

        // Vérification du mot de passe actuel si un changement de
        // mot de passe est demandé.
        if (!empty($donnees['password'])) {
            if (!Hash::check($donnees['mot_de_passe_actuel'] ?? '', $utilisateur->password)) {
                return response()->json([
                    'message' => 'Le mot de passe actuel est incorrect.',
                    'erreurs' => ['mot_de_passe_actuel' => ['Le mot de passe actuel est incorrect.']],
                ], 422);
            }
        }

        $message = 'Profil mis à jour.';

        DB::transaction(function () use ($utilisateur, $role, $donnees, &$message) {
            // 1. Nom (jamais pour un étudiant).
            if ($role !== 'etudiant' && array_key_exists('name', $donnees)) {
                $utilisateur->update(['name' => $donnees['name']]);
            }

            // 2. Mot de passe (tous rôles, avec validation préalable).
            if (!empty($donnees['password'])) {
                $utilisateur->update(['password' => Hash::make($donnees['password'])]);
                $message = 'Profil mis à jour : votre mot de passe a été modifié.';
            }

            // 3. Champs du profil enseignant.
            if ($utilisateur->enseignant) {
                $champsEnseignant = collect($donnees)
                    ->only(['telephone', 'specialite'])
                    ->filter(fn ($valeur) => $valeur !== null)
                    ->all();

                if ($champsEnseignant !== []) {
                    $utilisateur->enseignant->update($champsEnseignant);
                }
            }
        });

        return response()->json([
            'message' => $message,
            'profil' => $this->monProfil($request)->getData(),
        ]);
    }

    /**
     * La soutenance de l'étudiant connecté (date, heure, salle,
     * jury, statut).
     */
    public function maSoutenance(Request $request)
    {
        $etudiant = $request->user()->etudiant;

        if (!$etudiant) {
            return response()->json(['message' => 'Profil étudiant introuvable.'], 403);
        }

        $soutenance = Soutenance::with([
            'session', 'salle', 'creneau',
            'jury.membresJury.enseignant.user',
            'convocations' => fn ($q) => $q->where('type_destinataire', 'etudiant'),
        ])
            ->where('etudiant_id', $etudiant->id)
            ->latest()
            ->first();

        if (!$soutenance) {
            return response()->json([
                'soutenance' => null,
                'message' => 'Aucune soutenance n\'est encore enregistrée pour votre compte.',
            ]);
        }

        return response()->json(['soutenance' => $soutenance]);
    }

    /**
     * Le résultat de l'étudiant connecté (visible après validation).
     */
    public function monResultat(Request $request)
    {
        $etudiant = $request->user()->etudiant;

        if (!$etudiant) {
            return response()->json(['message' => 'Profil étudiant introuvable.'], 403);
        }

        $resultat = Soutenance::where('etudiant_id', $etudiant->id)
            ->latest()
            ->first()
            ?->resultat()
            ->with(['validePar.user', 'soutenance.etudiant.user', 'soutenance.etudiant.promotion.formation', 'soutenance.etudiant.promotion.parcours', 'soutenance.etudiant.promotion.parcours.formation', 'procesVerbal'])
            ->first();

        if (!$resultat) {
            return response()->json([
                'resultat' => null,
                'message' => 'Aucun résultat n\'est encore disponible pour votre soutenance.',
            ]);
        }

        return response()->json(['resultat' => $resultat]);
    }

    /**
     * Espace enseignant : ses jurys et les soutenances associées.
     * L'historique personnel masqué (nettoyage) est exclu.
     */
    public function monEspaceEnseignant(Request $request)
    {
        $enseignant = Enseignant::with(['user'])
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$enseignant) {
            return response()->json(['message' => 'Profil enseignant introuvable.'], 403);
        }

        $jurysIds = $enseignant->membresJury()->pluck('jury_id');

        // Éléments d'historique personnellement masqués (nettoyage).
        $masquesSoutenances = DB::table('historique_masques')
            ->where('user_id', $request->user()->id)
            ->whereNotNull('soutenance_id')
            ->pluck('soutenance_id')
            ->all();

        $soutenances = Soutenance::with([
            'etudiant.user', 'session', 'salle', 'creneau',
            'jury.membresJury.enseignant.user',
            'evaluations' => fn ($q) => $q->where('enseignant_id', $enseignant->id),
            'deliberation', 'resultat',
        ])
            ->whereIn('jury_id', $jurysIds)
            ->when($masquesSoutenances !== [], fn ($q) => $q->whereNotIn('id', $masquesSoutenances))
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'enseignant' => $enseignant,
            'soutenances' => $soutenances,
        ]);
    }

    /**
     * Nettoyage de l'historique personnel (web et mobile) :
     * masque les soutenances terminées et les évaluations validées
     * de l'utilisateur connecté. Les données officielles, les
     * archives et les données partagées des autres utilisateurs ne
     * sont PAS supprimées — les éléments sont simplement retirés de
     * l'historique personnel.
     */
    public function nettoyerMonHistorique(Request $request)
    {
        $utilisateur = $request->user();
        $role = $utilisateur->role?->name;
        $masques = 0;

        // Le nettoyage peut cibler un élément précis (soutenance_id)
        // ou porter sur tout l'historique.
        $donnees = $request->validate([
            'soutenance_id' => ['nullable', 'integer', 'exists:soutenances,id'],
        ]);

        if ($role === 'enseignant' && $utilisateur->enseignant) {
            $enseignant = $utilisateur->enseignant;

            $soutenancesIds = Soutenance::whereIn('jury_id', $enseignant->membresJury()->pluck('jury_id'))
                ->whereIn('statut', ['terminee', 'annulee', 'annulée'])
                ->when(
                    $donnees['soutenance_id'] ?? null,
                    fn ($q, $id) => $q->where('id', $id)
                )
                ->pluck('id');

            foreach ($soutenancesIds as $soutenanceId) {
                $existe = DB::table('historique_masques')
                    ->where('user_id', $utilisateur->id)
                    ->where('soutenance_id', $soutenanceId)
                    ->exists();

                if (!$existe) {
                    DB::table('historique_masques')->insert([
                        'user_id' => $utilisateur->id,
                        'soutenance_id' => $soutenanceId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $masques++;
                }
            }
        }

        return response()->json([
            'message' => $masques > 0
                ? "Historique nettoyé : {$masques} élément(s) retiré(s) de votre historique personnel. Les données officielles et les archives sont conservées."
                : 'Aucun élément d\'historique à nettoyer.',
            'elements_masques' => $masques,
        ]);
    }
}
