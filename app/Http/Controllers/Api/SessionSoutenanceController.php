<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SessionSoutenance;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Gestion des sessions de soutenance (BF03).
 *
 * Conformité au livre :
 *   - RG02 : chaque soutenance est associée à une session.
 *   - Dictionnaire de données : statut PREPARATION / OUVERTE /
 *     CLOTUREE (valeurs applicatives : planification / ouverte /
 *     cloturee).
 */
class SessionSoutenanceController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * Liste des sessions (accessible à tous les rôles
     * authentifiés pour la consultation).
     *
     * Paramètre disponibles=1 : renvoie uniquement les sessions
     * réellement disponibles pour accueillir une nouvelle
     * planification (statut « ouverte ») — pas les sessions
     * simplement créées (statut « planification »), pas les sessions
     * clôturées.
     */
    public function index(Request $request)
    {
        $query = SessionSoutenance::withCount(['soutenances', 'jurys'])
            ->orderByDesc('date_debut');

        if ($request->filled('statut')) {
            $query->where('statut', $request->string('statut')->toString());
        }

        if ($request->boolean('disponibles')) {
            $query->where('statut', 'ouverte');
        }

        return response()->json($query->get());
    }

    /**
     * Création d'une session (statut initial : planification).
     */
    public function store(Request $request)
    {
        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:150'],
            'date_debut' => ['required', 'date', 'before_or_equal:date_fin'],
            'date_fin' => ['required', 'date', 'after_or_equal:date_debut'],
            'annee_universitaire' => ['required', 'string', 'max:20'],
            'description' => ['nullable', 'string'],
            'statut' => ['sometimes', 'string', Rule::in(['planification', 'ouverte', 'cloturee'])],
        ]);

        $session = SessionSoutenance::create($donnees);

        $this->audit->enregistrer(
            'CREATION_SESSION',
            'sessions_soutenance',
            $session->id,
            "Création de la session « {$session->nom} » ({$session->annee_universitaire})."
        );

        return response()->json([
            'message' => 'Session créée.',
            'session' => $session->fresh(),
        ], 201);
    }

    /**
     * Détail d'une session.
     */
    public function show(SessionSoutenance $session)
    {
        return response()->json(
            $session->loadCount(['soutenances', 'jurys'])
                ->load(['jurys.membresJury.enseignant.user'])
        );
    }

    /**
     * Mise à jour d'une session.
     */
    public function update(Request $request, SessionSoutenance $session)
    {
        if ($session->statut === 'cloturee') {
            return response()->json([
                'message' => 'Cette session est clôturée : elle ne peut plus être modifiée.',
            ], 409);
        }

        $donnees = $request->validate([
            'nom' => ['sometimes', 'required', 'string', 'max:150'],
            'date_debut' => ['sometimes', 'required', 'date', 'before_or_equal:date_fin'],
            'date_fin' => ['sometimes', 'required', 'date', 'after_or_equal:date_debut'],
            'annee_universitaire' => ['sometimes', 'required', 'string', 'max:20'],
            'description' => ['nullable', 'string'],
            'statut' => ['sometimes', 'string', Rule::in(['planification', 'ouverte', 'cloturee'])],
        ]);

        $session->update($donnees);

        $this->audit->enregistrer(
            'MODIFICATION_SESSION',
            'sessions_soutenance',
            $session->id,
            "Modification de la session « {$session->nom} »."
        );

        return response()->json([
            'message' => 'Session mise à jour.',
            'session' => $session->fresh(),
        ]);
    }

    /**
     * Suppression d'une session sans contenu.
     */
    public function destroy(SessionSoutenance $session)
    {
        if ($session->soutenances()->exists() || $session->jurys()->exists()) {
            return response()->json([
                'message' => 'Cette session contient des jurys ou des soutenances : suppression impossible.',
            ], 409);
        }

        $nom = $session->nom;
        $session->delete();

        $this->audit->enregistrer(
            'SUPPRESSION_SESSION',
            'sessions_soutenance',
            $session->id,
            "Suppression de la session « {$nom} »."
        );

        return response()->json(['message' => 'Session supprimée.']);
    }

    /**
     * Ouverture d'une session : le planning devient opérable et les
     * convocations peuvent être générées.
     */
    public function ouvrir(SessionSoutenance $session)
    {
        if ($session->statut === 'cloturee') {
            return response()->json(['message' => 'Une session clôturée ne peut pas être rouverte.'], 409);
        }

        $session->statut = 'ouverte';
        $session->save();

        $this->audit->enregistrer(
            'OUVERTURE_SESSION',
            'sessions_soutenance',
            $session->id,
            "Ouverture de la session « {$session->nom} »."
        );

        return response()->json(['message' => 'Session ouverte.', 'session' => $session->fresh()]);
    }

    /**
     * Clôture d'une session : plus aucune opération de planification
     * n'est permise pour cette session, et toutes les planifications
     * de soutenance associées passent automatiquement à l'état
     * « Terminée » (cohérence base de données / backend / interfaces).
     */
    public function cloturer(SessionSoutenance $session)
    {
        if ($session->statut === 'cloturee') {
            return response()->json(['message' => 'Cette session est déjà clôturée.'], 409);
        }

        $miseesTerminees = 0;

        DB::transaction(function () use ($session, &$miseesTerminees) {
            $miseesTerminees = $session->soutenances()
                ->whereNotIn('statut', ['terminee', 'annulee', 'annulée'])
                ->update(['statut' => 'terminee']);

            $session->statut = 'cloturee';
            $session->save();
        });

        $this->audit->enregistrer(
            'CLOTURE_SESSION',
            'sessions_soutenance',
            $session->id,
            "Clôture de la session « {$session->nom} » — {$miseesTerminees} planification(s) marquée(s) « Terminée »."
        );

        $session->loadCount(['soutenances', 'jurys']);

        return response()->json([
            'message' => "Session clôturée. {$miseesTerminees} planification(s) de soutenance associée(s) "
                .'ont été marquée(s) « Terminée ».',
            'soutenances_terminees' => $miseesTerminees,
            'session' => $session->fresh(),
        ]);
    }
}
