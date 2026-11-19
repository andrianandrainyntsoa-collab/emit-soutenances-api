<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Jury;
use App\Models\SessionSoutenance;
use App\Services\AuditService;
use App\Services\JuryService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Gestion des jurys (BF04).
 *
 * Conformité au livre :
 *   - RG03 : chaque soutenance est associée à un jury composé de
 *     membres auxquels sont attribués des rôles, notamment
 *     Président, Rapporteur et Examinateur(s).
 */
class JuryController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly JuryService $juryService,
    ) {}

    /**
     * Liste des jurys avec filtres.
     */
    public function index(Request $request)
    {
        $query = Jury::with(['session', 'membresJury.enseignant.user'])
            ->withCount(['soutenances'])
            ->orderBy('nom')
            ->orderBy('code');

        if ($request->filled('session_id')) {
            $query->where('session_id', $request->integer('session_id'));
        }

        return response()->json($query->get());
    }

    /**
     * Jurys disponibles pour accueillir une nouvelle soutenance sur
     * un intervalle donné : le menu déroulant de la modale de
     * planification exclut les groupes de jury déjà affectés (ou dont
     * un membre est engagé) sur cet intervalle.
     */
    public function disponibles(Request $request, \App\Services\PlanificationService $planification)
    {
        $donnees = $request->validate([
            'session_id' => ['required', 'integer', 'exists:sessions_soutenance,id'],
            'date' => ['required', 'date'],
            'heure_debut' => ['required', 'date_format:H:i'],
            'heure_fin' => ['required', 'date_format:H:i', 'after:heure_debut'],
            'ignorer_soutenance_id' => ['nullable', 'integer'],
        ]);

        $jurys = Jury::with(['session', 'membresJury.enseignant.user'])
            ->where('session_id', $donnees['session_id'])
            ->orderBy('nom')
            ->orderBy('code')
            ->get();

        $disponibles = $jurys->filter(function ($jury) use ($planification, $donnees) {
            return $planification->juryDisponible(
                (int) $jury->id,
                $donnees['date'],
                $donnees['heure_debut'],
                $donnees['heure_fin'],
                (int) ($donnees['ignorer_soutenance_id'] ?? 0)
            );
        })->values();

        return response()->json($disponibles);
    }

    /**
     * Création d'un jury rattaché à une session. Le nom du groupe de
     * jury est l'information affichée aux utilisateurs ; le code
     * technique reste généré en interne pour l'intégrité des
     * relations.
     */
    public function store(Request $request)
    {
        $donnees = $request->validate([
            'session_id' => ['required', 'integer', 'exists:sessions_soutenance,id'],
            'code' => ['nullable', 'string', 'max:50', 'unique:jurys,code'],
            'nom' => ['required', 'string', 'max:150'],
        ], [
            'nom.required' => 'Le nom du groupe de jury est requis.',
        ]);

        $session = SessionSoutenance::findOrFail($donnees['session_id']);

        if ($session->statut === 'cloturee') {
            return response()->json(['message' => 'La session est clôturée : création de jury impossible.'], 409);
        }

        $code = $donnees['code'] ?? 'JURY-' . strtoupper(Str::random(6));

        $jury = Jury::create([
            'session_id' => $session->id,
            'code' => $code,
            'nom' => $donnees['nom'],
        ]);

        $this->audit->enregistrer(
            'CREATION_JURY',
            'jurys',
            $jury->id,
            "Création du jury {$jury->code} pour la session « {$session->nom} »."
        );

        return response()->json([
            'message' => 'Jury créé.',
            'jury' => $jury->load('membresJury.enseignant.user'),
        ], 201);
    }

    /**
     * Détail d'un jury (composition complète + soutenances).
     */
    public function show(Jury $jury)
    {
        $jury->load(['session', 'membresJury.enseignant.user', 'soutenances.etudiant.user']);

        return response()->json([
            'jury' => $jury,
            'composition_complete' => $this->juryService->estCompositionComplete($jury),
        ]);
    }

    /**
     * Mise à jour d'un jury.
     */
    public function update(Request $request, Jury $jury)
    {
        $donnees = $request->validate([
            'code' => ['sometimes', 'required', 'string', 'max:50', 'unique:jurys,code,' . $jury->id],
            'nom' => ['nullable', 'string', 'max:150'],
        ]);

        $jury->update($donnees);

        return response()->json([
            'message' => 'Jury mis à jour.',
            'jury' => $jury->fresh()->load('membresJury.enseignant.user'),
        ]);
    }

    /**
     * Suppression d'un jury sans soutenance.
     */
    public function destroy(Jury $jury)
    {
        if ($jury->soutenances()->exists()) {
            return response()->json([
                'message' => 'Ce jury est affecté à des soutenances : suppression impossible.',
            ], 409);
        }

        $code = $jury->code;
        $jury->delete();

        $this->audit->enregistrer(
            'SUPPRESSION_JURY',
            'jurys',
            $jury->id,
            "Suppression du jury {$code}."
        );

        return response()->json(['message' => 'Jury supprimé.']);
    }

    /**
     * Ajout d'un membre à un jury (avec fonction président /
     * rapporteur / examinateur).
     */
    public function ajouterMembre(Request $request, Jury $jury)
    {
        $donnees = $request->validate([
            'enseignant_id' => ['required', 'integer', 'exists:enseignants,id'],
            'fonction' => ['required', 'string', 'max:30'],
        ]);

        $this->juryService->verifierAjoutMembre(
            $jury,
            (int) $donnees['enseignant_id'],
            mb_strtolower(trim($donnees['fonction']))
        );

        $membre = $jury->membresJury()->create([
            'enseignant_id' => $donnees['enseignant_id'],
            'fonction' => mb_strtolower(trim($donnees['fonction'])),
        ]);

        return response()->json([
            'message' => 'Membre ajouté au jury.',
            'membre' => $membre->load('enseignant.user'),
            'composition_complete' => $this->juryService->estCompositionComplete($jury->fresh('membresJury')),
        ], 201);
    }

    /**
     * Retrait d'un membre d'un jury.
     */
    public function retirerMembre(Jury $jury, MembreJury $membre)
    {
        if ((int) $membre->jury_id !== (int) $jury->id) {
            return response()->json(['message' => 'Ce membre n\'appartient pas à ce jury.'], 422);
        }

        if ($jury->soutenances()->exists()) {
            return response()->json([
                'message' => 'Ce jury est affecté à des soutenances : modification de la composition impossible.',
            ], 409);
        }

        $membre->delete();

        return response()->json([
            'message' => 'Membre retiré du jury.',
            'composition_complete' => $this->juryService->estCompositionComplete($jury->fresh('membresJury')),
        ]);
    }

    /**
     * Jurys de l'enseignant connecté (vue membre du jury).
     */
    public function mesJurys(Request $request)
    {
        $enseignant = $request->user()->enseignant;

        if (!$enseignant) {
            return response()->json(['message' => 'Profil enseignant introuvable.'], 403);
        }

        $jurys = Jury::with(['session', 'membresJury.enseignant.user'])
            ->whereHas('membresJury', fn ($q) => $q->where('enseignant_id', $enseignant->id))
            ->orderBy('code')
            ->get()
            ->each(function ($jury) use ($enseignant) {
                $jury->ma_fonction = $jury->membresJury
                    ->first(fn ($m) => (int) $m->enseignant_id === (int) $enseignant->id)
                    ?->fonction;
            });

        return response()->json($jurys);
    }
}
