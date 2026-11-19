<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Convocation;
use App\Models\Soutenance;
use App\Services\ConvocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Gestion des convocations (BF06).
 *
 * Conformité au livre :
 *   - BF06 : générer et mettre à disposition les convocations.
 *   - Les convocations ne sont générées qu'à partir d'un planning
 *     validé (créneau + salle + jury établis).
 *   - Étudiant : consulter et télécharger sa convocation.
 *   - Responsable : suivre les convocations.
 */
class ConvocationController extends Controller
{
    public function __construct(
        private readonly ConvocationService $convocationService,
    ) {}

    /**
     * Liste des convocations (administration / responsable).
     */
    public function index(Request $request)
    {
        $query = Convocation::with([
            'soutenance.etudiant.user', 'soutenance.session', 'destinataire',
        ])->orderByDesc('created_at');

        if ($request->filled('soutenance_id')) {
            $query->where('soutenance_id', $request->integer('soutenance_id'));
        }

        if ($request->filled('statut')) {
            $query->where('statut', $request->string('statut')->toString());
        }

        return response()->json(
            $query->paginate(min(max($request->integer('per_page', 20), 1), 100))
        );
    }

    /**
     * Génération des convocations d'une soutenance (étudiant +
     * membres du jury). Idempotent : les convocations existantes ne
     * sont pas regénérées.
     */
    public function genererPourSoutenance(Soutenance $soutenance)
    {
        $convocations = $this->convocationService->genererPourSoutenance($soutenance);

        return response()->json([
            'message' => 'Convocations générées.',
            'convocations' => collect($convocations)->map->load('destinataire'),
        ], 201);
    }

    /**
     * Diffusion d'une convocation au destinataire.
     */
    public function envoyer(Convocation $convocation)
    {
        $convocation = $this->convocationService->diffuser($convocation);

        return response()->json([
            'message' => 'Convocation diffusée.',
            'convocation' => $convocation->load(['destinataire', 'soutenance.etudiant.user']),
        ]);
    }

    /**
     * Page des convocations regroupées par soutenance : une ligne =
     * une soutenance (étudiant + membres du jury regroupés).
     * Les convocations déjà envoyées ne figurent plus dans la liste
     * en attente (un historique technique reste accessible via
     * l'API standard).
     */
    public function parSoutenance(Request $request)
    {
        $groupes = $this->convocationService->listingParSoutenance();

        // Liste EN ATTENTE : uniquement les soutenances dont toutes
        // les convocations ne sont pas encore envoyées.
        if (!$request->boolean('inclure_envoyees')) {
            $groupes = $groupes->filter(fn ($g) => !$g['toutes_envoyees'])->values();
        }

        return response()->json(['groupes' => $groupes]);
    }

    /**
     * Envoi groupé : une seule opération déclenche l'envoi de toutes
     * les convocations liées à une soutenance, chacune à l'adresse
     * réelle de son destinataire (SMTP). Le retour indique précisément
     * les envois réussis et les échecs éventuels.
     */
    public function envoyerPourSoutenance(Soutenance $soutenance)
    {
        $resultat = $this->convocationService->envoyerPourSoutenance($soutenance);

        $reussi = count($resultat['echecs']) === 0;

        $message = $reussi
            ? "Toutes les convocations ({$resultat['total']}) ont été envoyées par e-mail à leurs destinataires respectifs."
            : 'Envoi partiel : ' . count($resultat['envoyees']) . ' convocation(s) envoyée(s), '
                . count($resultat['echecs']) . ' échec(s). Corrigez le problème puis réessayez.';

        return response()->json([
            'message' => $message,
            'reussi' => $reussi,
            'envoyees' => $resultat['envoyees'],
            'echecs' => $resultat['echecs'],
            'total' => $resultat['total'],
        ], $reussi ? 200 : 207);
    }

    /**
     * Convocations de l'utilisateur connecté (étudiant ou
     * enseignant membre du jury).
     */
    public function mesConvocations(Request $request)
    {
        $convocations = Convocation::with([
            'soutenance.etudiant.user', 'soutenance.session',
            'soutenance.salle', 'soutenance.creneau', 'soutenance.jury.membresJury.enseignant.user',
        ])
            ->where('destinataire_user_id', $request->user()->id)
            ->where('statut', '!=', 'a_generer')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($convocations);
    }

    /**
     * Téléchargement du PDF de la convocation.
     *
     * CORRECTION : si le fichier a disparu du stockage du serveur
     * (base conservée, storage vidé), il est régénéré automatiquement
     * à partir des données officielles au lieu de renvoyer
     * « document introuvable ».
     */
    public function telecharger(Request $request, Convocation $convocation)
    {
        $verdict = $this->verifierAcces($request, $convocation);

        if ($verdict !== null) {
            return $verdict;
        }

        if (!$this->convocationService->regenererFichier($convocation)) {
            return response()->json(['message' => 'Le fichier de la convocation est introuvable et n\'a pas pu être régénéré automatiquement.'], 404);
        }

        return Storage::disk(config('filesystems.documents'))->download(
            $convocation->fichier_path,
            'convocation-' . $convocation->id . '.pdf'
        );
    }

    /**
     * Affichage du PDF de la convocation dans le navigateur.
     * (Régénération automatique du fichier manquant, cf. telecharger.)
     */
    public function afficher(Request $request, Convocation $convocation)
    {
        $verdict = $this->verifierAcces($request, $convocation);

        if ($verdict !== null) {
            return $verdict;
        }

        if (!$this->convocationService->regenererFichier($convocation)) {
            return response()->json(['message' => 'Le fichier de la convocation est introuvable et n\'a pas pu être régénéré automatiquement.'], 404);
        }

        return Storage::disk(config('filesystems.documents'))->response(
            $convocation->fichier_path,
            'convocation-' . $convocation->id . '.pdf',
            [
                'Content-Type' => 'application/pdf',
            ],
            'inline'
        );
    }

    /**
     * Contrôle d'accès à une convocation :
     *   - administrateur : tout accès ;
     *   - responsable : consultation ;
     *   - destinataire : accès à sa propre convocation.
     *
     * Retourne null si l'accès est autorisé, sinon la réponse d'erreur.
     */
    private function verifierAcces(Request $request, Convocation $convocation): ?object
    {
        $utilisateur = $request->user()->loadMissing('role');

        if ($utilisateur->role->name === 'administrateur' || $utilisateur->role->name === 'responsable') {
            return null;
        }

        if ((int) $convocation->destinataire_user_id === (int) $utilisateur->id) {
            // Première consultation : la convocation passe à « consultee ».
            if ($convocation->statut === 'envoyee') {
                $convocation->statut = 'consultee';
                $convocation->save();
            }

            return null;
        }

        return response()->json(['message' => 'Accès interdit à cette convocation.'], 403);
    }
}
