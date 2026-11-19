<?php

namespace App\Services;

use App\Models\Deliberation;
use App\Models\Evaluation;
use App\Models\Resultat;
use App\Models\Soutenance;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Service métier de la délibération du jury.
 *
 * Conformité au livre :
 *   - RG04 : les membres du jury réalisent chacun leur évaluation.
 *   - RG06 : lorsque les évaluations nécessaires sont disponibles et
 *     validées, le jury peut procéder à la délibération.
 *   - RG07 / RM02 : la note finale officielle n'est pas déterminée
 *     automatiquement par une formule imposée par le système ; elle
 *     est retenue par le jury à l'issue de sa discussion.
 *   - RG08 : la note finale retenue correspond à la décision commune
 *     du jury concernant la note à inscrire.
 *   - RG09 : le président du jury valide officiellement le résultat
 *     final après la délibération.
 *   - RM05 / RG20 : un résultat définitif ne peut plus être modifié.
 */
class DeliberationService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Prépare (ouvre) la délibération d'une soutenance.
     *
     * La délibération n'est possible que lorsque les trois
     * évaluations distinctes sont validées (RG06).
     */
    public function ouvrir(Soutenance $soutenance, int $presidentEnseignantId): Deliberation
    {
        $this->verifierEvaluationsPretes($soutenance);

        return DB::transaction(function () use ($soutenance, $presidentEnseignantId) {
            $deliberation = Deliberation::where('soutenance_id', $soutenance->id)->first();

            if (!$deliberation) {
                $deliberation = new Deliberation();
                $deliberation->soutenance_id = $soutenance->id;
            }

            $deliberation->president_enseignant_id = $presidentEnseignantId;
            $deliberation->decision = $deliberation->decision ?: 'en_attente';
            $deliberation->save();

            return $deliberation;
        });
    }

    /**
     * Enregistre l'issue de la délibération : la note finale retenue
     * par le jury, la décision et la mention correspondante.
     *
     * La note enregistrée est celle retenue par le jury (RG07/RG08) :
     * elle devient la moyenne finale de référence du résultat, qui
     * sera ensuite validée officiellement par le président (RG09).
     */
    public function enregistrer(
        Soutenance $soutenance,
        int $presidentEnseignantId,
        float $noteFinaleRetenue,
        string $decision,
        ?string $mention = null,
        ?string $observations = null
    ): Deliberation {
        $this->verifierEvaluationsPretes($soutenance);

        if ($noteFinaleRetenue < 0 || $noteFinaleRetenue > 20) {
            throw ValidationException::withMessages([
                'note_finale_retenue' => 'La note retenue doit être comprise entre 0 et 20.',
            ]);
        }

        $decision = mb_strtolower(trim($decision));

        if (!in_array($decision, ['admis', 'ajourne', 'ajourné'], true)) {
            throw ValidationException::withMessages([
                'decision' => 'La décision doit être « admis » ou « ajourné ».',
            ]);
        }

        $resultatExistant = Resultat::where('soutenance_id', $soutenance->id)->first();

        if ($resultatExistant && $resultatExistant->valide_le !== null) {
            throw ValidationException::withMessages([
                'soutenance_id' => 'Le résultat final est déjà validé : la délibération ne peut plus être modifiée (RG20).',
            ], 409);
        }

        $deliberation = DB::transaction(function () use (
            $soutenance,
            $presidentEnseignantId,
            $noteFinaleRetenue,
            $decision,
            $mention,
            $observations
        ) {
            $deliberation = Deliberation::where('soutenance_id', $soutenance->id)
                ->lockForUpdate()
                ->first();

            if (!$deliberation) {
                $deliberation = new Deliberation();
                $deliberation->soutenance_id = $soutenance->id;
            }

            $deliberation->president_enseignant_id = $presidentEnseignantId;
            $deliberation->note_finale_retenue = round($noteFinaleRetenue, 2);
            $deliberation->decision = $decision === 'ajourné' ? 'ajourne' : $decision;
            $deliberation->mention = $mention;
            $deliberation->observations = $observations;
            $deliberation->deliberee_le = now();
            $deliberation->save();

            // Le résultat reprend la note retenue par le jury.
            $resultat = Resultat::where('soutenance_id', $soutenance->id)
                ->lockForUpdate()
                ->first();

            if (!$resultat) {
                $resultat = new Resultat();
                $resultat->soutenance_id = $soutenance->id;
            }

            $resultat->moyenne_finale = $deliberation->note_finale_retenue;
            $resultat->decision = $deliberation->decision;
            $resultat->observations = $observations;
            $resultat->save();

            return $deliberation;
        });

        $this->audit->enregistrer(
            'DELIBERATION_ENREGISTREE',
            'deliberations',
            $deliberation->id,
            "Délibération enregistrée pour la soutenance #{$soutenance->id} — note retenue : {$deliberation->note_finale_retenue}/20, décision : {$deliberation->decision}."
        );

        return $deliberation->fresh(['soutenance.etudiant.user', 'president.user']);
    }

    /**
     * Consultation de la délibération (et du contexte d'évaluation)
     * pour les membres du jury.
     */
    public function consulter(Soutenance $soutenance): array
    {
        $evaluations = Evaluation::with('enseignant.user')
            ->where('soutenance_id', $soutenance->id)
            ->orderBy('enseignant_id')
            ->get();

        $moyenneReference = null;

        $evaluationsValidees = $evaluations->where('validee', true);

        if ($evaluationsValidees->count() === 3) {
            $moyenneReference = round((float) $evaluationsValidees->avg('note'), 2);
        }

        return [
            'soutenance' => $soutenance->load([
                'etudiant.user', 'session', 'jury.membresJury.enseignant.user',
            ]),
            'evaluations' => $evaluations,
            'moyenne_des_evaluations' => $moyenneReference,
            'deliberation' => Deliberation::with('president.user')
                ->where('soutenance_id', $soutenance->id)
                ->first(),
            'resultat' => Resultat::where('soutenance_id', $soutenance->id)->first(),
        ];
    }

    /**
     * Vérifie (RG06) que les trois évaluations distinctes sont
     * validées avant toute délibération.
     */
    private function verifierEvaluationsPretes(Soutenance $soutenance): void
    {
        $evaluations = Evaluation::where('soutenance_id', $soutenance->id)->get();

        $troisDistinctes = $evaluations->count() === 3
            && $evaluations->pluck('enseignant_id')->unique()->count() === 3;

        if (!$troisDistinctes) {
            throw ValidationException::withMessages([
                'soutenance_id' => 'Les trois membres du jury doivent chacun avoir saisi une évaluation.',
            ]);
        }

        if ($evaluations->contains(fn (Evaluation $e) => !$e->validee)) {
            throw ValidationException::withMessages([
                'soutenance_id' => 'Toutes les évaluations doivent être validées avant la délibération.',
            ]);
        }
    }
}
