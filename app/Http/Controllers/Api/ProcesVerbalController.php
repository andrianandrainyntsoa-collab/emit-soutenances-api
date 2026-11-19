<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Enseignant;
use App\Models\ProcesVerbal;
use App\Models\Resultat;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProcesVerbalController extends Controller
{
    /**
     * Liste paginée des procès-verbaux.
     */
    public function index(Request $request)
    {
        $query = ProcesVerbal::with([
            'resultat.soutenance.etudiant.user',
            'resultat.soutenance.etudiant.promotion.formation',
            'resultat.soutenance.etudiant.promotion.parcours',
            'resultat.soutenance.etudiant.promotion.parcours.formation',
            'resultat.soutenance.session',
            'generePar.user',
        ]);

        if ($request->user()->role?->name === 'enseignant') {
            $enseignant = $request->user()->enseignant;

            if (!$enseignant) {
                return response()->json(['message' => 'Profil enseignant introuvable.'], 403);
            }

            $query->whereHas(
                'resultat.soutenance.jury.membresJury',
                fn ($q) => $q->where('enseignant_id', $enseignant->id)
            );
        }

        $procesVerbaux = $query->latest()->paginate(15);

        return response()->json($procesVerbaux);
    }

    /**
     * Génération du PV officiel après validation du résultat.
     */
    public function generer(Request $request, int $resultatId)
    {
        $donnees = $request->validate([
            'type_master' => [
                'required',
                Rule::in(['professionnel', 'recherche']),
            ],
        ]);

        $enseignant = Enseignant::where(
            'user_id',
            $request->user()->id
        )->first();

        if (!$enseignant) {
            return response()->json([
                'message' => 'Aucun profil enseignant associé à ce compte.',
            ], 403);
        }

        $resultat = Resultat::with([
            'soutenance.etudiant.user',
            'soutenance.etudiant.promotion.formation',
            'soutenance.etudiant.promotion.parcours',
            'soutenance.session',
            'soutenance.creneau',
            'soutenance.jury.membresJury.enseignant.user',
            'soutenance.evaluations.enseignant.user',
        ])->findOrFail($resultatId);

        if (!$resultat->valide_le) {
            return response()->json([
                'message' => 'Le résultat doit être validé par le président avant la génération du PV.',
            ], 422);
        }

        $soutenance = $resultat->soutenance;

        if (!$soutenance || !$soutenance->jury) {
            return response()->json([
                'message' => 'Aucun jury associé à cette soutenance.',
            ], 422);
        }

        $president = $soutenance->jury->membresJury->first(
            fn ($membre) =>
                strtolower(trim($membre->fonction)) === 'president'
        );

        if (
            !$president ||
            (int) $president->enseignant_id !== (int) $enseignant->id
        ) {
            return response()->json([
                'message' => 'Seul le président du jury peut générer le PV.',
            ], 403);
        }

        $evaluations = $soutenance->evaluations
            ->where('validee', true)
            ->unique('enseignant_id')
            ->values();

        if (
            $evaluations->count() !== 3 ||
            $evaluations->contains(
                fn ($evaluation) => !$evaluation->validee
            )
        ) {
            return response()->json([
                'message' => 'Trois évaluations validées par trois membres distincts sont nécessaires.',
            ], 422);
        }

        $existant = ProcesVerbal::where('resultat_id', $resultat->id)
            ->where('type_master', $donnees['type_master'])
            ->first();

        if ($existant) {
            return response()->json([
                'message' => 'Un PV de ce type existe déjà pour ce résultat.',
                'proces_verbal' => $existant,
            ], 409);
        }

        $prefixe = $donnees['type_master'] === 'professionnel'
            ? 'MP'
            : 'MR';

        $numeroPv = sprintf(
            'PV-%s-%s-%06d',
            $prefixe,
            now()->format('Y'),
            $resultat->id
        );

        // Nom du fichier lisible : numéro du PV + numéro étudiant + nom,
        // pour retrouver facilement le document après téléchargement
        // (ex : PV-MR-2026-000012_312SDIA24_rasoanaivo-ando.pdf).
        $etudiantPv = $soutenance->etudiant;
        $numeroEtudiant = preg_replace('/[^A-Za-z0-9\-]/', '', $etudiantPv?->numero_etudiant ?? 'sans-numero');
        $slugNom = Str::slug($etudiantPv?->user?->name ?? 'etudiant', '-');

        $pdf = Pdf::loadView('pdf.proces-verbal', [
            'resultat' => $resultat,
            'soutenance' => $soutenance,
            'evaluations' => $evaluations,
            'numeroPv' => $numeroPv,
            'typeMaster' => $donnees['type_master'],
            'dateGeneration' => now(),
        ])->setPaper('a4', 'portrait');

        $chemin = 'proces-verbaux/' . $numeroPv . '_' . $numeroEtudiant . '_' . $slugNom . '.pdf';

        Storage::disk(config('filesystems.documents'))->put($chemin, $pdf->output());

        try {
            $pv = DB::transaction(function () use (
                $resultat,
                $donnees,
                $numeroPv,
                $chemin,
                $enseignant
            ) {
                $resultatVerrouille = Resultat::whereKey($resultat->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (!$resultatVerrouille->valide_le) {
                    abort(422, 'Le résultat doit être validé.');
                }

                $existe = ProcesVerbal::where('resultat_id', $resultat->id)
                    ->where('type_master', $donnees['type_master'])
                    ->exists();

                if ($existe) {
                    abort(409, 'Un PV de ce type existe déjà.');
                }

                return ProcesVerbal::create([
                    'resultat_id' => $resultat->id,
                    'numero_pv' => $numeroPv,
                    'type_master' => $donnees['type_master'],
                    'fichier_path' => $chemin,
                    'genere_par' => $enseignant->id,
                    'genere_le' => now(),
                    'statut' => 'genere',
                ]);
            });
        } catch (\Throwable $e) {
            Storage::disk(config('filesystems.documents'))->delete($chemin);
            throw $e;
        }

        // Notification de l'étudiant : son procès-verbal est disponible.
        $etudiantUserId = $soutenance->etudiant?->user_id;

        if ($etudiantUserId) {
            app(\App\Services\NotificationService::class)->envoyer(
                $etudiantUserId,
                'Procès-verbal disponible',
                'Votre procès-verbal de soutenance « ' . $soutenance->titre_memoire . ' » a été '
                    .'généré (n° ' . $pv->numero_pv . '). Vous pouvez le consulter et le '
                    .'télécharger depuis votre espace.',
                'proces_verbal',
                ['soutenance_id' => $soutenance->id, 'proces_verbal_id' => $pv->id]
            );
        }

        app(\App\Services\AuditService::class)->enregistrer(
            'GENERATION_PV',
            'proces_verbaux',
            $pv->id,
            "Génération du procès-verbal {$pv->numero_pv} pour la soutenance #{$soutenance->id}."
        );

        return response()->json([
            'message' => 'Procès-verbal généré avec succès.',
            'proces_verbal' => $pv,
        ], 201);
    }
    
    private function utilisateurAutorise(Request $request, ProcesVerbal $pv): bool
    {
        $utilisateur = $request->user()->loadMissing('role');

        // L'administrateur peut consulter tous les PV.
        if ($utilisateur->role->name === 'administrateur') {
            return true;
        }

        // Le responsable consulte et télécharge les PV (RG14).
        if ($utilisateur->role->name === 'responsable') {
            return true;
        }

        // L'étudiant accède uniquement au PV définitif de sa propre
        // soutenance (RG14 : téléchargement).
        if ($utilisateur->role->name === 'etudiant') {
            $soutenance = $pv->resultat?->soutenance;

            return $soutenance
                && $soutenance->etudiant
                && (int) $soutenance->etudiant->user_id === (int) $utilisateur->id;
        }

        // Pour les autres utilisateurs, seul un enseignant membre
        // du jury concerné peut consulter le PV.
        if ($utilisateur->role->name !== 'enseignant') {
            return false;
        }

        $enseignant = Enseignant::where(
            'user_id',
            $utilisateur->id
        )->first();

        if (!$enseignant) {
            return false;
        }

        $soutenance = $pv->resultat?->soutenance;

        if (!$soutenance || !$soutenance->jury_id) {
            return false;
        }

        return DB::table('membres_jury')
            ->where('jury_id', $soutenance->jury_id)
            ->where('enseignant_id', $enseignant->id)
            ->exists();
}

    /**
     * Liste paginée des procès-verbaux accessibles au rôle
     * responsable (RG14).
     */
    public function indexResponsable()
    {
        $procesVerbaux = ProcesVerbal::with([
            'resultat.soutenance.etudiant.user',
            'resultat.soutenance.session',
            'generePar.user',
        ])->latest()->paginate(15);

        return response()->json($procesVerbaux);
    }

    /**
     * Le PV de l'étudiant connecté (téléchargement — RG14).
     */
    public function monProcesVerbal(Request $request)
    {
        $etudiant = $request->user()->etudiant;

        if (!$etudiant) {
            return response()->json(['message' => 'Profil étudiant introuvable.'], 403);
        }

        $soutenance = \App\Models\Soutenance::where('etudiant_id', $etudiant->id)
            ->latest()
            ->first();

        if (!$soutenance) {
            return response()->json(['proces_verbaux' => []]);
        }

        $pv = ProcesVerbal::with([
            'resultat.soutenance.etudiant.user',
            'resultat.soutenance.session',
            'generePar.user',
        ])
            ->whereHas('resultat', fn ($q) => $q->where('soutenance_id', $soutenance->id))
            ->latest()
            ->get();

        return response()->json(['proces_verbaux' => $pv]);
    }

    /**
     * Téléchargement du PV.
     */
    
    /**
     * Suppression d'un procès-verbal (fichier PDF + enregistrement),
     * APRÈS un téléchargement réussi et avec confirmation. Les
     * informations officielles de résultat et d'archivage sont
     * préservées : la soutenance, le résultat et les données liées ne
     * sont PAS supprimés.
     */
    public function supprimer(Request $request, int $procesVerbalId)
    {
        $pv = ProcesVerbal::with('resultat.soutenance.etudiant.user')
            ->findOrFail($procesVerbalId);

        $utilisateur = $request->user()->loadMissing('role');

        if (!in_array($utilisateur->role->name, ['administrateur', 'responsable'], true)) {
            return response()->json([
                'message' => 'Seuls les administrateurs et responsables peuvent supprimer un procès-verbal.',
            ], 403);
        }

        $numero = $pv->numero_pv;

        \Illuminate\Support\Facades\DB::transaction(function () use ($pv) {
            if ($pv->fichier_path) {
                Storage::disk(config('filesystems.documents'))->delete($pv->fichier_path);
            }

            $pv->delete();
        });

        app(\App\Services\AuditService::class)->enregistrer(
            'SUPPRESSION_PV',
            'proces_verbaux',
            $pv->id,
            "Suppression du procès-verbal {$numero} (fichier et enregistrement). Le résultat officiel est conservé."
        );

        return response()->json([
            'message' => "Procès-verbal {$numero} supprimé. Le résultat officiel et les données d'archivage restent conservés.",
        ]);
    }

    /**
     * Nom de fichier lisible pour le téléchargement / l'affichage :
     * numéro du PV + numéro de l'étudiant + son nom.
     */
    private function nomFichierTelechargement(ProcesVerbal $pv): string
    {
        $pv->loadMissing('resultat.soutenance.etudiant.user');

        $etudiant = $pv->resultat?->soutenance?->etudiant;

        $numero = preg_replace(
            '/[^A-Za-z0-9\-]/',
            '',
            $etudiant?->numero_etudiant ?? 'sans-numero'
        );

        $slugNom = Str::slug($etudiant?->user?->name ?? 'etudiant', '-');

        return $pv->numero_pv . '_' . $numero . '_' . $slugNom . '.pdf';
    }

    /**
     * CORRECTION (« document introuvable sur le serveur ») : régénère
     * le fichier PDF d'un PV existant lorsque le fichier a disparu du
     * stockage du serveur (base conservée, storage vidé — p.ex. après
     * une recopie ou une réinstallation du projet).
     *
     * Le document est reconstruit À L'IDENTIQUE à partir des données
     * officielles enregistrées en base (même vue, même numéro de PV,
     * même type de Master, même emplacement). Les trois évaluations
     * validées doivent toujours exister : sinon la régénération est
     * refusée (retour false) et le téléchargement reste en erreur 404.
     */
    private function regenererFichier(ProcesVerbal $pv): bool
    {
        if (
            $pv->fichier_path
            && Storage::disk(config('filesystems.documents'))->exists($pv->fichier_path)
        ) {
            return true;
        }

        $resultat = Resultat::with([
            'soutenance.etudiant.user',
            'soutenance.etudiant.promotion.formation',
            'soutenance.etudiant.promotion.parcours',
            'soutenance.session',
            'soutenance.creneau',
            'soutenance.jury.membresJury.enseignant.user',
            'soutenance.evaluations.enseignant.user',
        ])->find($pv->resultat_id);

        if (!$resultat || !$resultat->soutenance) {
            return false;
        }

        $evaluations = $resultat->soutenance->evaluations
            ->where('validee', true)
            ->unique('enseignant_id')
            ->values();

        if ($evaluations->count() !== 3) {
            return false;
        }

        $chemin = $pv->fichier_path
            ?: 'proces-verbaux/' . $pv->numero_pv . '.pdf';

        try {
            $pdf = Pdf::loadView('pdf.proces-verbal', [
                'resultat' => $resultat,
                'soutenance' => $resultat->soutenance,
                'evaluations' => $evaluations,
                'numeroPv' => $pv->numero_pv,
                'typeMaster' => $pv->type_master,
                'dateGeneration' => $pv->genere_le ?? now(),
            ])->setPaper('a4', 'portrait');

            Storage::disk(config('filesystems.documents'))->put($chemin, $pdf->output());
        } catch (\Throwable $e) {
            report($e);

            return false;
        }

        if ($pv->fichier_path !== $chemin) {
            $pv->fichier_path = $chemin;
            $pv->save();
        }

        return true;
    }

    public function telecharger(Request $request, int $procesVerbalId)
    {
        $pv = ProcesVerbal::with('resultat.soutenance.etudiant.user')
            ->findOrFail($procesVerbalId);

        if (!$this->utilisateurAutorise($request, $pv)) {
            return response()->json([
                'message' => 'Accès interdit à ce procès-verbal.',
            ], 403);
        }

        if (!$this->regenererFichier($pv)) {
            return response()->json([
                'message' => 'Le fichier PDF est introuvable et n\'a pas pu être régénéré automatiquement.',
            ], 404);
        }

        return Storage::disk(config('filesystems.documents'))->download(
            $pv->fichier_path,
            $this->nomFichierTelechargement($pv)
        );
    }


    /**
     * Affichage du PV dans le navigateur.
     * (Régénération automatique du fichier manquant, cf. telecharger.)
     */

    public function afficher(Request $request, int $procesVerbalId)
    {
        $pv = ProcesVerbal::with('resultat.soutenance.etudiant.user')
            ->findOrFail($procesVerbalId);

        if (!$this->utilisateurAutorise($request, $pv)) {
            return response()->json([
                'message' => 'Accès interdit à ce procès-verbal.',
            ], 403);
        }

        if (!$this->regenererFichier($pv)) {
            return response()->json([
                'message' => 'Le fichier PDF est introuvable et n\'a pas pu être régénéré automatiquement.',
            ], 404);
        }

        return Storage::disk(config('filesystems.documents'))->response(
            $pv->fichier_path,
            $this->nomFichierTelechargement($pv),
            [
                'Content-Type' => 'application/pdf',
            ],
            'inline'
        );
    }

}
