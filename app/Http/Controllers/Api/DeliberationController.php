<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deliberation;
use App\Models\Soutenance;
use App\Services\AuditService;
use App\Services\DeliberationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Délibération du jury (BF08, BF09).
 *
 * Conformité au livre :
 *   - RG06 : le jury procède à la délibération lorsque les
 *     évaluations nécessaires sont disponibles et validées.
 *   - RG07 / RG08 : la note finale est retenue par le jury à
 *     l'issue de sa discussion (elle n'est pas calculée par le
 *     système).
 *   - RG09 : le président du jury valide officiellement le résultat
 *     (voir ResultatController::valider).
 *   - RG20 : aucun retour en arrière une fois le résultat validé.
 */
class DeliberationController extends Controller
{
    public function __construct(
        private readonly DeliberationService $deliberationService,
        private readonly AuditService $audit,
    ) {}

    /**
     * Consultation de la délibération et du contexte d'évaluation
     * (réservée aux membres du jury concerné).
     */
    public function consulter(Request $request, Soutenance $soutenance)
    {
        $verdict = $this->verifierMembreJury($request, $soutenance);

        if ($verdict !== null) {
            return $verdict;
        }

        return response()->json(
            $this->deliberationService->consulter($soutenance)
        );
    }

    /**
     * Ouverture de la délibération (président du jury).
     */
    public function ouvrir(Request $request, Soutenance $soutenance)
    {
        $verdict = $this->verifierPresident($request, $soutenance);

        if ($verdict !== null) {
            return $verdict;
        }

        $deliberation = $this->deliberationService->ouvrir(
            $soutenance,
            (int) $request->user()->enseignant->id
        );

        return response()->json([
            'message' => 'Délibération ouverte.',
            'deliberation' => $deliberation->load('president.user'),
        ]);
    }

    /**
     * Enregistrement de l'issue de la délibération : note finale
     * retenue par le jury, décision et mention (président du jury).
     */
    public function enregistrer(Request $request, Soutenance $soutenance)
    {
        $verdict = $this->verifierPresident($request, $soutenance);

        if ($verdict !== null) {
            return $verdict;
        }

        $donnees = $request->validate([
            'note_finale_retenue' => ['required', 'numeric', 'min:0', 'max:20'],
            'decision' => ['required', 'string', 'max:30'],
            'mention' => ['nullable', 'string', 'max:100'],
            'observations' => ['nullable', 'string', 'max:5000'],
        ]);

        $deliberation = $this->deliberationService->enregistrer(
            $soutenance,
            (int) $request->user()->enseignant->id,
            (float) $donnees['note_finale_retenue'],
            $donnees['decision'],
            $donnees['mention'] ?? null,
            $donnees['observations'] ?? null
        );

        return response()->json([
            'message' => 'Délibération enregistrée. Le résultat attend la validation officielle du président.',
            'deliberation' => $deliberation->load('president.user'),
            'resultat' => $soutenance->resultat()->with(['validePar.user'])->first(),
        ]);
    }

    /**
     * Liste des délibérations enregistrées (administration /
     * responsable).
     */
    public function index(Request $request)
    {
        $query = Deliberation::with([
            'soutenance.etudiant.user', 'soutenance.session', 'president.user',
        ])
            ->whereNotNull('deliberee_le')
            ->orderByDesc('deliberee_le');

        if ($request->filled('session_id')) {
            $query->whereHas('soutenance', fn ($q) => $q->where('session_id', $request->integer('session_id')));
        }

        return response()->json($query->get());
    }

    /**
     * Vérifie que l'utilisateur est membre du jury de la soutenance.
     */
    private function verifierMembreJury(Request $request, Soutenance $soutenance): ?object
    {
        $enseignant = $request->user()->enseignant;

        if (!$enseignant) {
            return response()->json(['message' => 'Profil enseignant introuvable.'], 403);
        }

        if (!$soutenance->jury_id) {
            return response()->json(['message' => 'Aucun jury n\'est affecté à cette soutenance.'], 422);
        }

        $estMembre = DB::table('membres_jury')
            ->where('jury_id', $soutenance->jury_id)
            ->where('enseignant_id', $enseignant->id)
            ->exists();

        if (!$estMembre) {
            return response()->json(['message' => 'Vous ne faites pas partie du jury de cette soutenance.'], 403);
        }

        return null;
    }

    /**
     * Vérifie que l'utilisateur est le président du jury.
     */
    private function verifierPresident(Request $request, Soutenance $soutenance): ?object
    {
        $enseignant = $request->user()->enseignant;

        if (!$enseignant) {
            return response()->json(['message' => 'Profil enseignant introuvable.'], 403);
        }

        if (!$soutenance->jury_id) {
            return response()->json(['message' => 'Aucun jury n\'est affecté à cette soutenance.'], 422);
        }

        $estPresident = DB::table('membres_jury')
            ->where('jury_id', $soutenance->jury_id)
            ->where('enseignant_id', $enseignant->id)
            ->whereRaw('LOWER(fonction) = ?', ['president'])
            ->exists();

        if (!$estPresident) {
            return response()->json(['message' => 'Seul le président du jury peut conduire la délibération.'], 403);
        }

        return null;
    }
}
