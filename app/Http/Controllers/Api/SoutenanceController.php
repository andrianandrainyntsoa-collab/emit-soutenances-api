<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Creneau;
use App\Models\Etudiant;
use App\Models\Soutenance;
use App\Services\PlanificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SoutenanceController extends Controller
{
    public function index(Request $request)
    {
        $query = Soutenance::with([
            'etudiant.user', 'etudiant.promotion.parcours', 'etudiant.promotion.formation', 'session',
            'jury.membresJury.enseignant.user', 'salle', 'creneau',
        ])->orderByDesc('created_at');

        if ($request->filled('session_id')) $query->where('session_id', $request->integer('session_id'));
        if ($request->filled('statut')) $query->where('statut', $request->string('statut')->toString());

        $user = $request->user();
        if ($user->role?->name === 'etudiant') {
            $query->whereHas('etudiant', fn ($q) => $q->where('user_id', $user->id));
        } elseif ($user->role?->name === 'enseignant') {
            $query->whereHas('jury.membresJury.enseignant', fn ($q) => $q->where('user_id', $user->id));
        }

        return response()->json($query->paginate(min(max($request->integer('per_page', 20), 1), 100)));
    }

    public function show(Request $request, Soutenance $soutenance)
    {
        $user = $request->user();
        if ($user->role?->name === 'etudiant' && $soutenance->etudiant?->user_id !== $user->id) {
            return response()->json(['message' => 'Accès interdit à cette soutenance.'], 403);
        }
        if ($user->role?->name === 'enseignant' && !$soutenance->jury?->membresJury()->whereHas('enseignant', fn ($q) => $q->where('user_id', $user->id))->exists()) {
            return response()->json(['message' => 'Accès interdit à cette soutenance.'], 403);
        }
        return response()->json($soutenance->load(['etudiant.user', 'etudiant.promotion', 'session', 'jury.membresJury.enseignant.user', 'salle', 'creneau', 'evaluations', 'resultat']));
    }

    /**
     * Création d'une soutenance : date, heure de début, heure de fin
     * et groupe de jury sélectionnés directement dans la modale. La
     * durée correspond à l'intervalle entre les deux heures.
     */
    public function store(Request $request, PlanificationService $planification)
    {
        $data = $this->validateData($request);

        // La session doit être réellement ouverte à la planification.
        $session = \App\Models\SessionSoutenance::findOrFail($data['session_id']);
        if ($session->statut !== 'ouverte') {
            return response()->json([
                'message' => $session->statut === 'cloturee'
                    ? 'Cette session est clôturée : aucune nouvelle planification possible.'
                    : 'Cette session n\'est pas encore ouverte à la planification.',
            ], 409);
        }

        // L'étudiant doit être autorisé à soutenir (RG01) et ne pas
        // posséder déjà une soutenance planifiée ou active.
        $etudiant = Etudiant::findOrFail($data['etudiant_id']);
        if (!$etudiant->autorise_soutenance) {
            return response()->json(['message' => 'Cet étudiant n’est pas encore autorisé à soutenir.'], 422);
        }
        if (Soutenance::where('etudiant_id', $etudiant->id)->whereIn('statut', Soutenance::STATUTS_ACTIFS)->exists()) {
            return response()->json([
                'message' => 'Cet étudiant possède déjà une soutenance planifiée ou active : il n\'est pas disponible pour une nouvelle planification.',
            ], 409);
        }

        $soutenanceTemp = new Soutenance([
            'session_id' => $data['session_id'],
            'date_soutenance' => $data['date_soutenance'],
            'heure_debut' => $data['heure_debut'],
            'heure_fin' => $data['heure_fin'],
        ]);
        $soutenanceTemp->setRelation('session', $session);
        $soutenanceTemp->setRelation('creneau', null);

        // Vérification finale des conflits au moment de
        // l'enregistrement (salle, groupe de jury, membres).
        $planification->verifier($soutenanceTemp, (int) $data['salle_id'], (int) $data['jury_id']);

        $data['statut'] = 'planifiee';

        $soutenance = DB::transaction(function () use ($data) {
            // Les structures de créneaux existantes restent utilisées
            // en interne (convocations, affichages historiques) : le
            // créneau correspondant est trouvé ou créé, sans détruire
            // les données.
            $creneau = Creneau::firstOrCreate(
                [
                    'date_soutenance' => $data['date_soutenance'],
                    'heure_debut' => $data['heure_debut'],
                    'heure_fin' => $data['heure_fin'],
                ],
                ['statut' => 'planifie']
            );

            if ($creneau->statut === 'disponible') {
                $creneau->update(['statut' => 'planifie']);
            }

            $data['creneau_id'] = $creneau->id;

            return Soutenance::create($data);
        });

        return response()->json(['message' => 'Soutenance créée.', 'soutenance' => $soutenance->load(['etudiant.user', 'session', 'jury', 'salle', 'creneau'])], 201);
    }

    /**
     * Mise à jour (replanification) d'une soutenance non confirmée.
     */
    public function update(Request $request, Soutenance $soutenance, PlanificationService $planification)
    {
        if ($soutenance->resultat?->valide_le) {
            return response()->json(['message' => 'Une soutenance dont le résultat est définitif ne peut plus être replanifiée.'], 409);
        }

        if (in_array($soutenance->statut, ['confirmee', 'terminee'], true)) {
            return response()->json([
                'message' => 'Une soutenance confirmée ne peut plus être replanifiée. Marquez-la terminée, ou supprimez-la si elle est terminée.',
            ], 409);
        }

        $data = $this->validateData($request, $soutenance);

        $sessionId = (int) ($data['session_id'] ?? $soutenance->session_id);
        $session = \App\Models\SessionSoutenance::findOrFail($sessionId);
        if ($session->statut !== 'ouverte') {
            return response()->json(['message' => 'Cette session n\'est pas ouverte à la planification.'], 409);
        }

        $candidate = clone $soutenance;
        $candidate->session_id = $sessionId;
        $candidate->date_soutenance = $data['date_soutenance'] ?? $soutenance->date_soutenance;
        $candidate->heure_debut = $data['heure_debut'] ?? $soutenance->heure_debut;
        $candidate->heure_fin = $data['heure_fin'] ?? $soutenance->heure_fin;
        $candidate->setRelation('session', $session);
        $candidate->setRelation('creneau', $soutenance->creneau);

        $planification->verifier(
            $candidate,
            (int) ($data['salle_id'] ?? $soutenance->salle_id),
            (int) ($data['jury_id'] ?? $soutenance->jury_id)
        );

        DB::transaction(function () use ($soutenance, $candidate, $data) {
            if (
                $data['date_soutenance'] !== ($soutenance->date_soutenance?->toDateString())
                || $data['heure_debut'] !== substr((string) $soutenance->heure_debut, 0, 8)
                || $data['heure_fin'] !== substr((string) $soutenance->heure_fin, 0, 8)
            ) {
                $creneau = Creneau::firstOrCreate(
                    [
                        'date_soutenance' => $data['date_soutenance'],
                        'heure_debut' => $data['heure_debut'],
                        'heure_fin' => $data['heure_fin'],
                    ],
                    ['statut' => 'planifie']
                );
                $data['creneau_id'] = $creneau->id;
            }

            $soutenance->update($data);
        });

        return response()->json(['message' => 'Soutenance mise à jour.', 'soutenance' => $soutenance->fresh()->load(['etudiant.user', 'session', 'jury', 'salle', 'creneau'])]);
    }

    /**
     * Suppression d'une soutenance, sous réserve des dépendances :
     *   - une soutenance confirmée ne peut pas être annulée/supprimée ;
     *   - une soutenance terminée peut être supprimée si aucune
     *     évaluation n'a été validée et si son résultat n'est pas
     *     définitif (intégrité des données officielles).
     */
    public function destroy(Soutenance $soutenance)
    {
        if ($soutenance->statut === 'confirmee') {
            return response()->json([
                'message' => 'Une soutenance confirmée ne peut pas être annulée ni supprimée.',
            ], 409);
        }

        if ($soutenance->resultat?->valide_le) {
            return response()->json([
                'message' => 'Cette soutenance possède un résultat définitif : suppression impossible.',
            ], 409);
        }

        if ($soutenance->evaluations()->where('validee', true)->exists()) {
            return response()->json([
                'message' => 'Cette soutenance possède des évaluations validées : suppression impossible.',
            ], 409);
        }

        $supprimee = false;

        DB::transaction(function () use ($soutenance, &$supprimee) {
            // Suppression des fichiers de convocations liés avant la
            // suppression en cascade des enregistrements.
            $soutenance->convocations()->get()->each(function ($convocation) {
                if ($convocation->fichier_path) {
                    Storage::disk(config('filesystems.documents'))->delete($convocation->fichier_path);
                }
            });

            $id = $soutenance->id;
            $soutenance->delete();
            $supprimee = true;

            // Libère le créneau interne s'il n'est plus utilisé.
            if ($soutenance->creneau_id && !Soutenance::where('creneau_id', $soutenance->creneau_id)->exists()) {
                optional(Creneau::find($soutenance->creneau_id))->update(['statut' => 'disponible']);
            }

            // Historique personnel : retire les masquages orphelins.
            DB::table('historique_masques')->where('soutenance_id', $id)->delete();
        });

        return response()->json(['message' => 'Soutenance supprimée.', 'supprimee' => $supprimee]);
    }

    private function validateData(Request $request, ?Soutenance $soutenance = null): array
    {
        return $request->validate([
            'etudiant_id' => [$soutenance ? 'sometimes' : 'required', 'integer', 'exists:etudiants,id'],
            'session_id' => [$soutenance ? 'sometimes' : 'required', 'integer', 'exists:sessions_soutenance,id'],
            'jury_id' => ['nullable', 'integer', 'exists:jurys,id'],
            'salle_id' => ['nullable', 'integer', 'exists:salles,id'],
            'date_soutenance' => ['required', 'date'],
            'heure_debut' => ['required', 'date_format:H:i'],
            'heure_fin' => ['required', 'date_format:H:i', 'after:heure_debut'],
            'titre_memoire' => ['sometimes', 'required', 'string', 'max:255'],
            'observations' => ['nullable', 'string'],
        ], [
            'heure_fin.after' => 'L\'heure de fin doit être postérieure à l\'heure de début.',
            'date_soutenance.required' => 'La date de soutenance est requise.',
            'heure_debut.required' => 'L\'heure de début est requise.',
            'heure_fin.required' => 'L\'heure de fin est requise.',
        ]);
    }

    /**
     * Confirmation de la soutenance planifiée : le planning est
     * arrêté et les convocations peuvent être générées. Une
     * soutenance confirmée ne peut ensuite plus être annulée ni
     * ajournée.
     */
    public function confirmer(Request $request, Soutenance $soutenance, \App\Services\AuditService $audit, \App\Services\NotificationService $notifications)
    {
        if ($soutenance->statut !== 'planifiee') {
            return response()->json(['message' => 'Seule une soutenance planifiée peut être confirmée.'], 409);
        }

        if (!$soutenance->date_soutenance || !$soutenance->salle_id || !$soutenance->jury_id) {
            return response()->json(['message' => 'La planification est incomplète (date, heures, salle et jury requis).'], 422);
        }

        $soutenance->statut = 'confirmee';
        $soutenance->save();

        $audit->enregistrer(
            'CONFIRMATION_SOUTENANCE',
            'soutenances',
            $soutenance->id,
            "Confirmation de la soutenance #{$soutenance->id}."
        );

        if ($soutenance->etudiant) {
            $notifications->envoyer(
                $soutenance->etudiant->user_id,
                'Soutenance confirmée',
                'Votre soutenance « ' . $soutenance->titre_memoire . ' » est confirmée pour le ' .
                    ($soutenance->date_soutenance?->format('d/m/Y') ?? '—') . ' à ' .
                    substr((string) $soutenance->heure_debut, 0, 5) .
                    ', salle ' . ($soutenance->salle?->code ?? '—') . '. Votre convocation sera disponible prochainement.',
                'planification',
                ['soutenance_id' => $soutenance->id]
            );
        }

        return response()->json([
            'message' => 'Soutenance confirmée.',
            'soutenance' => $soutenance->fresh()->load(['etudiant.user', 'session', 'jury.membresJury.enseignant.user', 'salle', 'creneau']),
        ]);
    }

    /**
     * Clôture de la soutenance : le jury a terminé le passage.
     */
    public function terminer(Request $request, Soutenance $soutenance, \App\Services\AuditService $audit)
    {
        if (!in_array($soutenance->statut, ['confirmee', 'planifiee'], true)) {
            return response()->json(['message' => 'Seule une soutenance planifiée ou confirmée peut être terminée.'], 409);
        }

        $soutenance->statut = 'terminee';
        $soutenance->save();

        $audit->enregistrer(
            'FIN_SOUTENANCE',
            'soutenances',
            $soutenance->id,
            "Fin de la soutenance #{$soutenance->id}."
        );

        return response()->json([
            'message' => 'Soutenance marquée comme terminée.',
            'soutenance' => $soutenance->fresh(),
        ]);
    }

    /**
     * Ajournement (report) d'une soutenance non confirmée et non
     * finalisée. Une soutenance confirmée ne peut pas être ajournée.
     */
    public function ajourner(Request $request, Soutenance $soutenance, \App\Services\AuditService $audit, \App\Services\NotificationService $notifications)
    {
        if ($soutenance->resultat?->valide_le) {
            return response()->json(['message' => 'Le résultat est définitif : ajournement impossible.'], 409);
        }

        if (in_array($soutenance->statut, ['confirmee', 'terminee', 'annulee', 'annulée'], true)) {
            return response()->json(['message' => 'Cette soutenance ne peut pas être ajournée.'], 409);
        }

        $soutenance->statut = 'ajournee';
        $soutenance->save();

        $audit->enregistrer(
            'AJOURNEMENT_SOUTENANCE',
            'soutenances',
            $soutenance->id,
            "Ajournement de la soutenance #{$soutenance->id} (report à une date ultérieure)."
        );

        if ($soutenance->etudiant) {
            $notifications->envoyer(
                $soutenance->etudiant->user_id,
                'Soutenance ajournée',
                'Votre soutenance « ' . $soutenance->titre_memoire . ' » est ajournée. Une nouvelle date vous sera communiquée.',
                'planification',
                ['soutenance_id' => $soutenance->id]
            );
        }

        return response()->json([
            'message' => 'Soutenance ajournée.',
            'soutenance' => $soutenance->fresh(),
        ]);
    }
}
