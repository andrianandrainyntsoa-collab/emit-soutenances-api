<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Evaluation;
use App\Models\Resultat;
use App\Models\Soutenance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EvaluationController extends Controller
{
    public function index(Request $request)
    {
        $enseignant = $request->user()->enseignant;

        if (!$enseignant) {
            return response()->json([
                'message' => 'Aucun profil enseignant associe a ce compte.',
            ], 403);
        }

        $evaluations = Evaluation::with([
            'soutenance.etudiant.user',
            'soutenance.session',
        ])
            ->where('enseignant_id', $enseignant->id)
            ->latest()
            ->get();

        return response()->json($evaluations);
    }

    public function store(Request $request, int $soutenanceId)
    {
        $enseignant = $request->user()->enseignant;

        if (!$enseignant) {
            return response()->json([
                'message' => 'Profil enseignant introuvable.',
            ], 403);
        }

        $validated = $request->validate([
            'note' => ['required', 'numeric', 'min:0', 'max:20'],
            'commentaire' => ['nullable', 'string', 'max:5000'],
        ]);

        $evaluation = DB::transaction(function () use (
            $soutenanceId,
            $enseignant,
            $validated
        ) {
            $soutenance = Soutenance::whereKey($soutenanceId)
                ->lockForUpdate()
                ->firstOrFail();

            if (!$soutenance->jury_id) {
                abort(422, 'Aucun jury n est affecte a cette soutenance.');
            }

            $estMembre = DB::table('membres_jury')
                ->where('jury_id', $soutenance->jury_id)
                ->where('enseignant_id', $enseignant->id)
                ->exists();

            if (!$estMembre) {
                abort(403, 'Vous ne faites pas partie du jury de cette soutenance.');
            }

            $resultat = Resultat::where(
                'soutenance_id',
                $soutenance->id
            )->lockForUpdate()->first();

            if ($resultat && $resultat->valide_le !== null) {
                abort(409, 'Le resultat final est deja valide.');
            }

            $evaluation = Evaluation::where(
                'soutenance_id',
                $soutenance->id
            )->where('enseignant_id', $enseignant->id)
                ->lockForUpdate()
                ->first();

            if ($evaluation && $evaluation->validee) {
                abort(409, 'Cette evaluation est deja validee.');
            }

            if (!$evaluation) {
                $evaluation = new Evaluation();
                $evaluation->soutenance_id = $soutenance->id;
                $evaluation->enseignant_id = $enseignant->id;
            }

            $evaluation->note = $validated['note'];
            $evaluation->commentaire = $validated['commentaire'] ?? null;
            $evaluation->validee = false;
            $evaluation->validee_le = null;
            $evaluation->save();

            return $evaluation;
        });

        return response()->json([
            'message' => 'Evaluation enregistree. Elle attend sa validation.',
            'evaluation' => $evaluation,
        ], 201);
    }

    public function valider(Request $request, int $evaluationId)
    {
        $enseignant = $request->user()->enseignant;

        if (!$enseignant) {
            return response()->json([
                'message' => 'Profil enseignant introuvable.',
            ], 403);
        }

        $donnees = DB::transaction(function () use (
            $enseignant,
            $evaluationId
        ) {
            $evaluation = Evaluation::where('enseignant_id', $enseignant->id)
                ->whereKey($evaluationId)
                ->lockForUpdate()
                ->firstOrFail();

            $soutenance = Soutenance::whereKey($evaluation->soutenance_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($evaluation->validee) {
                abort(409, 'Cette evaluation est deja validee.');
            }

            $resultat = Resultat::where(
                'soutenance_id',
                $soutenance->id
            )->lockForUpdate()->first();

            if ($resultat && $resultat->valide_le !== null) {
                abort(409, 'Le resultat final est deja valide.');
            }

            $evaluation->validee = true;
            $evaluation->validee_le = now();
            $evaluation->save();

            $evaluationsValidees = Evaluation::where(
                'soutenance_id',
                $soutenance->id
            )
                ->where('validee', true)
                ->get();

            $nombre = $evaluationsValidees
                ->pluck('enseignant_id')
                ->unique()
                ->count();

            $resultatFinalise = false;

            // RG04 : chaque membre du jury saisit puis valide sa propre
            // note. Dès que les trois membres distincts ont validé leur
            // note, la moyenne des trois notes devient la note finale et
            // l'ensemble des informations de la soutenance est envoyé
            // automatiquement vers la table « resultats ». Le résultat
            // ainsi enregistré est directement définitif : il alimente
            // ensuite la génération, l'affichage et le téléchargement du
            // procès-verbal selon le rôle de l'utilisateur.
            if ($nombre === 3 && $evaluationsValidees->count() === 3) {
                $moyenne = round(
                    (float) $evaluationsValidees->avg('note'),
                    2
                );

                if (!$resultat) {
                    $resultat = new Resultat();
                    $resultat->soutenance_id = $soutenance->id;
                }

                $resultat->moyenne_finale = $moyenne;
                $resultat->decision = $moyenne >= 10
                    ? 'admis'
                    : 'ajourne';

                // Le président du jury officialise le résultat calculé.
                $president = DB::table('membres_jury')
                    ->where('jury_id', $soutenance->jury_id)
                    ->whereRaw('LOWER(fonction) = ?', ['president'])
                    ->value('enseignant_id');

                $resultat->valide_par = $president;
                $resultat->valide_le = now();
                $resultat->save();

                // La soutenance est terminée dès que le résultat est finalisé.
                if (!in_array($soutenance->statut, ['terminee'], true)) {
                    $soutenance->statut = 'terminee';
                    $soutenance->save();
                }

                // Notification de l'étudiant : son résultat est officiel.
                $etudiantUserId = $soutenance->etudiant?->user_id;

                if ($etudiantUserId) {
                    app(\App\Services\NotificationService::class)->envoyer(
                        $etudiantUserId,
                        'Résultat final disponible',
                        'Les trois membres du jury ont validé leur note. La moyenne des notes validées '
                            . 'constitue votre note finale : ' . $resultat->moyenne_finale . '/20 ('
                            . $resultat->mention . ', décision : ' . $resultat->decision . '). '
                            . 'Votre procès-verbal sera disponible prochainement.',
                        'resultat',
                        ['soutenance_id' => $soutenance->id, 'resultat_id' => $resultat->id]
                    );
                }

                app(\App\Services\AuditService::class)->enregistrer(
                    'RESULTAT_AUTO_FINALISE',
                    'resultats',
                    $resultat->id,
                    "Résultat automatiquement finalisé pour la soutenance #{$soutenance->id} "
                        . "après validation des trois notes par les membres du jury — "
                        . "moyenne finale : {$resultat->moyenne_finale}/20."
                );

                $resultatFinalise = true;
            }

            return [
                'evaluation' => $evaluation->fresh(),
                'nombre_evaluations_validees' => $nombre,
                'moyenne_calculable' => $nombre === 3
                    && $evaluationsValidees->count() === 3,
                'resultat_finalise' => $resultatFinalise,
                'resultat_disponible' => $resultatFinalise,
                'resultat' => $resultatFinalise
                    ? $resultat->fresh(['soutenance.etudiant.user'])
                    : null,
            ];
        });

        return response()->json([
            'message' => $donnees['resultat_finalise']
                ? 'Evaluation validee. Les trois notes etant validees, la moyenne des trois notes '
                    . 'a ete enregistree automatiquement comme note finale dans les resultats.'
                : 'Evaluation validee avec succes.',
            ...$donnees,
        ]);
    }
}