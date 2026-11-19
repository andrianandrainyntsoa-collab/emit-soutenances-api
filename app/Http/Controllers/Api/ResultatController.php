<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Resultat;
use App\Models\Soutenance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ResultatController extends Controller
{
    
    public function index(Request $request)
    {
        $utilisateur = $request->user()->loadMissing('role');

        $requete = Resultat::with([
            'soutenance.etudiant.user',
            'soutenance.etudiant.promotion.formation',
            'soutenance.etudiant.promotion.parcours',
            'soutenance.etudiant.promotion.parcours.formation',
            'soutenance.session',
            'soutenance.jury.membresJury.enseignant.user',
            'soutenance.evaluations.enseignant.user',
            'procesVerbal',
        ])
            ->whereHas('soutenance.evaluations', function ($query) {
                $query->where('validee', true);
            });

        // Un enseignant ne consulte que les résultats des jurys auxquels il appartient.
        if ($utilisateur->role->name === 'enseignant') {
            $enseignant = $utilisateur->enseignant;

            if (!$enseignant) {
                return response()->json([
                    'message' => 'Profil enseignant introuvable.',
               ], 403);
            }

        $requete->whereHas(
            'soutenance.jury.membresJury',
            function ($query) use ($enseignant) {
                $query->where('enseignant_id', $enseignant->id);
            }
        );
    }

        // Liste de travail : les résultats archivés (nettoyage) ne
        // figurent plus ; ils restent consultables via
        // GET /resultats?archives=1.
        if (!$request->boolean('archives')) {
            $requete->whereNull('archive_le');
        } else {
            $requete->whereNotNull('archive_le');
        }

        $resultats = $requete
            ->latest()
            ->get()
            ->filter(function ($resultat) {
                if (!$resultat->soutenance) {
                    return false;
                }

                $evaluations = $resultat->soutenance->evaluations;

                return $evaluations->count() === 3
                    && $evaluations->pluck('enseignant_id')->unique()->count() === 3
                    && $evaluations->every(
                        fn ($evaluation) => $evaluation->validee
                    );
            })
            ->values();

        return response()->json([
            'data' => $resultats,
        ]);
    }

    /**
     * Nettoyage de la liste de travail : archive les résultats
     * concernés sans supprimer les données officielles. Les résultats
     * archivés et les procès-verbaux restent consultables
     * (GET /resultats?archives=1 et page des PV).
     */
    public function nettoyer(Request $request)
    {
        $utilisateur = $request->user()->loadMissing('role');

        if (!in_array($utilisateur->role->name, ['administrateur', 'responsable'], true)) {
            return response()->json(['message' => 'Accès interdit.'], 403);
        }

        $nombre = Resultat::whereNull('archive_le')
            ->whereHas('soutenance.evaluations', fn ($q) => $q->where('validee', true))
            ->update(['archive_le' => now()]);

        app(\App\Services\AuditService::class)->enregistrer(
            'NETTOYAGE_RESULTATS',
            'resultats',
            null,
            "Nettoyage de la liste des résultats : {$nombre} résultat(s) archivé(s) (données officielles conservées)."
        );

        return response()->json([
            'message' => "Nettoyage effectué : {$nombre} résultat(s) retiré(s) de la liste de travail. "
                .'Les données officielles et les archives restent consultables.',
            'resultats_archives' => $nombre,
        ]);
    }

    /**
     * Génération du compte rendu PDF des résultats : document
     * récapitulatif construit à partir des données réelles du système
     * (nom de l'étudiant, titre du mémoire, note retenue, décision
     * finale).
     */
    public function compteRendu(Request $request)
    {
        $query = Resultat::with([
            'soutenance.etudiant.user',
            'soutenance.etudiant.promotion.formation',
            'soutenance.etudiant.promotion.parcours',
            'soutenance.session',
        ])
            ->whereNotNull('valide_le')
            ->orderBy('id');

        if ($request->user()->role?->name === 'enseignant') {
            $enseignant = $request->user()->enseignant;

            if (!$enseignant) {
                return response()->json(['message' => 'Profil enseignant introuvable.'], 403);
            }

            $query->whereHas(
                'soutenance.jury.membresJury',
                fn ($q) => $q->where('enseignant_id', $enseignant->id)
            );
        }

        $resultats = $query->get();

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.compte-rendu-resultats', [
            'resultats' => $resultats,
            'dateGeneration' => now(),
        ])->setPaper('a4', 'portrait');

        $nomFichier = 'compte-rendu-resultats-' . now()->format('Ymd-His') . '.pdf';

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $nomFichier . '"',
        ]);
    }


    public function valider(Request $request, int $resultatId)
    {
        $enseignant = $request->user()->enseignant;

        if (!$enseignant) {
            return response()->json([
                'message' => 'Profil enseignant introuvable.',
            ], 403);
        }

        return DB::transaction(function () use ($resultatId, $enseignant) {
            $resultatInitial = Resultat::findOrFail($resultatId);

            $soutenance = Soutenance::whereKey(
                $resultatInitial->soutenance_id
            )->lockForUpdate()->firstOrFail();

            $resultat = Resultat::whereKey($resultatId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($resultat->valide_le !== null) {
                return response()->json([
                    'message' => 'Le resultat est deja valide.',
                ], 409);
            }

            if (!$soutenance->jury_id) {
                return response()->json([
                    'message' => 'Aucun jury associe a cette soutenance.',
                ], 422);
            }

            $estPresident = DB::table('membres_jury')
                ->where('jury_id', $soutenance->jury_id)
                ->where('enseignant_id', $enseignant->id)
                ->whereRaw('LOWER(fonction) = ?', ['president'])
                ->exists();

            if (!$estPresident) {
                return response()->json([
                    'message' => 'Seul le president du jury peut valider le resultat.',
                ], 403);
            }

            $evaluations = $soutenance->evaluations()
                ->lockForUpdate()
                ->get();

            $troisEvaluationsDistinctes = $evaluations->count() === 3
                && $evaluations->pluck('enseignant_id')->unique()->count() === 3;

            $toutesValidees = $evaluations->every(
                fn ($evaluation) => $evaluation->validee
            );

            if (!$troisEvaluationsDistinctes || !$toutesValidees) {
                return response()->json([
                    'message' => 'Les trois evaluations distinctes doivent etre validees avant le resultat final.',
                ], 422);
            }

            // RM02 / RG07 : la note finale officielle est celle
            // retenue par le jury lors de la délibération. Elle
            // prévaut sur toute moyenne calculée. À défaut de
            // délibération enregistrée, la moyenne des évaluations
            // validées sert de valeur de repli.
            $deliberation = \App\Models\Deliberation::where(
                'soutenance_id',
                $soutenance->id
            )->first();

            if ($deliberation && $deliberation->note_finale_retenue !== null) {
                $resultat->moyenne_finale = $deliberation->note_finale_retenue;
                $resultat->decision = $deliberation->decision ?: 'admis';
            } else {
                $resultat->moyenne_finale = round(
                    (float) $evaluations->avg('note'),
                    2
                );
            }

            $resultat->valide_par = $enseignant->id;
            $resultat->valide_le = now();
            $resultat->save();

            // La soutenance est terminée une fois le résultat validé.
            if (!in_array($soutenance->statut, ['terminee'], true)) {
                $soutenance->statut = 'terminee';
                $soutenance->save();
            }

            // Notification de l'étudiant : son résultat est officiel.
            $etudiantUserId = $soutenance->etudiant?->user_id;

            if ($etudiantUserId) {
                app(\App\Services\NotificationService::class)->envoyer(
                    $etudiantUserId,
                    'Résultat final validé',
                    'Le jury a validé le résultat de votre soutenance « ' . $soutenance->titre_memoire .
                        ' ». Note finale : ' . $resultat->moyenne_finale . '/20 (' . $resultat->mention . '). ' .
                        'Votre procès-verbal sera disponible prochainement.',
                    'resultat',
                    ['soutenance_id' => $soutenance->id, 'resultat_id' => $resultat->id]
                );
            }

            app(\App\Services\AuditService::class)->enregistrer(
                'VALIDATION_RESULTAT',
                'resultats',
                $resultat->id,
                "Validation du résultat final de la soutenance #{$soutenance->id} — note : {$resultat->moyenne_finale}/20."
            );

            return response()->json([
                'message' => 'Resultat final valide avec succes.',
                'data' => $resultat->fresh([
                    'soutenance.etudiant.user',
                    'soutenance.session',
                    'soutenance.evaluations.enseignant.user',
                    'procesVerbal',
                ]),
            ]);
        });
    }
}