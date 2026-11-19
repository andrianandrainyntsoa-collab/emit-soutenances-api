<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Models\Soutenance;
use App\Services\AuditService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Consultation des étudiants (BF03 / RG01).
 *
 * Règles de correction :
 *   - Les étudiants s'inscrivent eux-mêmes : l'administration ne
 *     crée plus de compte étudiant et ne modifie plus leurs
 *     informations personnelles (gérées depuis « Mon Profil »).
 *   - Les actions admises : rechercher, consulter, autoriser le
 *     compte, autoriser la soutenance (RG01) et supprimer selon les
 *     règles métier.
 *   - Le filtre sans_soutenance_active exclut les étudiants qui ont
 *     déjà une soutenance planifiée ou active (utilisé par le
 *     formulaire de planification).
 */
class EtudiantController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Liste des étudiants avec filtres (promotion, autorisation,
     * recherche, disponibilité pour une nouvelle planification).
     */
    public function index(Request $request)
    {
        $query = Etudiant::with(['user', 'promotion.formation', 'promotion.parcours'])
            ->join('users', 'users.id', '=', 'etudiants.user_id')
            ->orderBy('users.name')
            ->select('etudiants.*', 'users.est_autorise');

        if ($request->filled('promotion_id')) {
            $query->where('promotion_id', $request->integer('promotion_id'));
        }

        if ($request->filled('autorise')) {
            $query->where('autorise_soutenance', $request->boolean('autorise'));
        }

        // Étudiants disponibles pour une NOUVELLE planification : un
        // étudiant possédant déjà une soutenance planifiée ou active
        // (à planifier / planifiée / confirmée) est exclu. Une
        // soutenance terminée ou annulée ne bloque pas.
        if ($request->boolean('sans_soutenance_active')) {
            $query->whereDoesntHave('soutenances', function ($q) {
                $q->whereIn('statut', Soutenance::STATUTS_ACTIFS);
            });
        }

        if ($request->filled('recherche')) {
            $terme = '%' . $request->string('recherche')->toString() . '%';
            $query->where(function ($q) use ($terme) {
                $q->whereLike('numero_etudiant', $terme)
                    ->orWhereLike('users.name', $terme)
                    ->orWhereLike('users.email', $terme);
            });
        }

        return response()->json(
            $query->paginate(min(max($request->integer('per_page', 20), 1), 100))
        );
    }

    /**
     * Détail d'un étudiant avec ses soutenances.
     */
    public function show(Etudiant $etudiant)
    {
        return response()->json(
            $etudiant->load([
                'user', 'promotion.formation', 'promotion.parcours',
                'soutenances.session', 'soutenances.jury.membresJury.enseignant.user',
                'soutenances.salle', 'soutenances.creneau', 'soutenances.resultat',
            ])
        );
    }

    /**
     * Suppression d'un étudiant sans soutenance.
     */
    public function destroy(Etudiant $etudiant)
    {
        if ($etudiant->soutenances()->exists()) {
            return response()->json([
                'message' => 'Cet étudiant possède déjà des soutenances : suppression impossible.',
            ], 409);
        }

        $etudiant->load('user');
        $numero = $etudiant->numero_etudiant;

        DB::transaction(function () use ($etudiant) {
            $etudiant->user->delete(); // cascade sur etudiants
        });

        $this->audit->enregistrer(
            'SUPPRESSION_ETUDIANT',
            'etudiants',
            $etudiant->id,
            "Suppression de l'étudiant {$numero}."
        );

        return response()->json(['message' => 'Étudiant supprimé.']);
    }

    /**
     * Autorisation (ou retrait d'autorisation) à soutenir — RG01.
     */
    public function autoriser(Request $request, Etudiant $etudiant)
    {
        $donnees = $request->validate([
            'autorise_soutenance' => ['required', 'boolean'],
        ]);

        $ancien = $etudiant->autorise_soutenance;
        $etudiant->autorise_soutenance = $donnees['autorise_soutenance'];
        $etudiant->save();

        if (!$ancien && $etudiant->autorise_soutenance) {
            $this->notifications->envoyer(
                $etudiant->user_id,
                'Autorisation de soutenir',
                'Félicitations ! Vous êtes autorisé(e) à soutenir votre mémoire de Master. Le planning de votre soutenance sera communiqué prochainement.',
                'autorisation',
                ['etudiant_id' => $etudiant->id]
            );
        }

        $this->audit->enregistrer(
            $etudiant->autorise_soutenance ? 'AUTORISATION_SOUTENANCE' : 'RETRAIT_AUTORISATION',
            'etudiants',
            $etudiant->id,
            "Autorisation de soutenir " . ($etudiant->autorise_soutenance ? 'accordée' : 'retirée') . " pour {$etudiant->numero_etudiant}."
        );

        return response()->json([
            'message' => $etudiant->autorise_soutenance
                ? 'Étudiant autorisé à soutenir.'
                : 'Autorisation retirée.',
            'etudiant' => $etudiant->fresh(['user', 'promotion.formation', 'promotion.parcours']),
        ]);
    }
}
