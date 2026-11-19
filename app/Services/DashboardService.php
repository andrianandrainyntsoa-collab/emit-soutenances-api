<?php

namespace App\Services;

use App\Models\Creneau;
use App\Models\Deliberation;
use App\Models\Enseignant;
use App\Models\Etudiant;
use App\Models\Evaluation;
use App\Models\Notification;
use App\Models\ProcesVerbal;
use App\Models\Resultat;
use App\Models\SessionSoutenance;
use App\Models\Soutenance;
use Illuminate\Support\Facades\DB;

/**
 * Service du tableau de bord.
 *
 * Conformité au livre (cahier des charges 3.5.3) :
 *   - Tableau de bord : présentation synthétique des informations
 *     et indicateurs utiles au suivi des activités liées aux
 *     soutenances. Les indicateurs sont adaptés au rôle de
 *     l'utilisateur connecté.
 */
class DashboardService
{
    /**
     * Indicateurs pour l'administrateur et le responsable :
     * vue globale du processus.
     */
    public function statistiquesGlobales(): array
    {
        return [
            'etudiants' => [
                'autorises' => Etudiant::where('autorise_soutenance', true)->count(),
                'total' => Etudiant::count(),
            ],
            'enseignants' => Enseignant::count(),
            'sessions' => [
                'actives' => SessionSoutenance::whereNotIn('statut', ['cloturee'])->count(),
                'total' => SessionSoutenance::count(),
            ],
            'soutenances_par_statut' => $this->compterParStatut(),
            'evaluations' => [
                'en_attente_validation' => Evaluation::where('validee', false)->count(),
                'validees' => Evaluation::where('validee', true)->count(),
            ],
            'deliberations' => Deliberation::whereNotNull('deliberee_le')->count(),
            'resultats' => [
                'valides' => Resultat::whereNotNull('valide_le')->count(),
                'en_attente_validation' => Resultat::whereNull('valide_le')
                    ->whereNotNull('moyenne_finale')->count(),
            ],
            'proces_verbaux' => ProcesVerbal::count(),
            'prochaines_soutenances' => $this->prochainesSoutenances(),
        ];
    }

    /**
     * Indicateurs pour un enseignant : ses soutenances en tant que
     * membre du jury, ses évaluations en attente, ses délibérations.
     */
    public function statistiquesEnseignant(int $enseignantId): array
    {
        $soutenanceIds = DB::table('membres_jury')
            ->where('enseignant_id', $enseignantId)
            ->pluck('jury_id')
            ->unique()
            ->pipe(fn ($juryIds) => DB::table('soutenances')
                ->whereIn('jury_id', $juryIds)
                ->pluck('id'));

        $estPresident = function (string $statutSoutenance = null) use ($enseignantId) {
            $query = DB::table('membres_jury')
                ->join('soutenances', 'soutenances.jury_id', '=', 'membres_jury.jury_id')
                ->where('membres_jury.enseignant_id', $enseignantId)
                ->whereRaw('LOWER(membres_jury.fonction) = ?', ['president']);

            if ($statutSoutenance !== null) {
                $query->where('soutenances.statut', $statutSoutenance);
            }

            return $query->count();
        };

        return [
            'soutenances_jury' => $soutenanceIds->count(),
            'evaluations_en_attente' => Evaluation::where('enseignant_id', $enseignantId)
                ->where('validee', false)->count(),
            'evaluations_validees' => Evaluation::where('enseignant_id', $enseignantId)
                ->where('validee', true)->count(),
            'soutenances_president' => $estPresident(),
            'deliberations_a_conduire' => $this->deliberationsAConduire($enseignantId),
            'prochaines_soutenances' => $this->prochainesSoutenances($enseignantId),
        ];
    }

    /**
     * Indicateurs pour un étudiant : sa soutenance, sa convocation,
     * son résultat, son PV.
     */
    public function statistiquesEtudiant(int $etudiantId): array
    {
        $soutenance = Soutenance::with(['creneau', 'salle', 'jury.membresJury.enseignant.user'])
            ->where('etudiant_id', $etudiantId)
            ->latest()
            ->first();

        return [
            'autorise_soutenance' => Etudiant::whereKey($etudiantId)->value('autorise_soutenance') === 1
                || Etudiant::whereKey($etudiantId)->value('autorise_soutenance') === true,
            'soutenance' => $soutenance,
            'convocations_disponibles' => $soutenance
                ? $soutenance->convocations()->where('type_destinataire', 'etudiant')
                    ->where('statut', '!=', 'a_generer')->count()
                : 0,
            'notifications_non_lues' => Notification::where('user_id', function ($query) use ($etudiantId) {
                $query->select('user_id')->from('etudiants')->where('id', $etudiantId);
            })->whereNull('lue_le')->count(),
        ];
    }

    /**
     * Nombre de délibérations à conduire : soutenances terminées
     * avec trois évaluations validées mais sans délibération
     * enregistrée, où l'enseignant est président.
     */
    private function deliberationsAConduire(int $enseignantId): int
    {
        $jurysPresides = DB::table('membres_jury')
            ->where('enseignant_id', $enseignantId)
            ->whereRaw('LOWER(fonction) = ?', ['president'])
            ->pluck('jury_id');

        if ($jurysPresides->isEmpty()) {
            return 0;
        }

        return Soutenance::whereIn('jury_id', $jurysPresides)
            ->whereNotIn('statut', ['annulee', 'annulée'])
            ->whereDoesntHave('deliberation', function ($query) {
                $query->whereNotNull('deliberee_le');
            })
            ->whereHas('evaluations', function ($query) {
                $query->where('validee', true);
            }, '=', 3)
            ->count();
    }

    /**
     * Prochaines soutenances planifiées / confirmées.
     */
    private function prochainesSoutenances(?int $enseignantId = null): array
    {
        $query = Soutenance::with([
            'etudiant.user', 'salle', 'creneau', 'jury.membresJury.enseignant.user',
        ])
            ->whereIn('soutenances.statut', ['planifiee', 'confirmee'])
            ->whereHas('creneau', function ($query) {
                $query->whereDate('date_soutenance', '>=', now()->toDateString());
            })
            ->join('creneaux', 'creneaux.id', '=', 'soutenances.creneau_id')
            ->orderBy('creneaux.date_soutenance')
            ->orderBy('creneaux.heure_debut')
            ->select('soutenances.*')
            ->limit(8);

        if ($enseignantId !== null) {
            $query->whereHas('jury.membresJury', function ($query) use ($enseignantId) {
                $query->where('enseignant_id', $enseignantId);
            });
        }

        return $query->get()->all();
    }

    /**
     * Comptage des soutenances par statut.
     */
    private function compterParStatut(): array
    {
        $compteurs = Soutenance::select('statut', DB::raw('count(*) as total'))
            ->groupBy('statut')
            ->pluck('total', 'statut');

        $tousStatuts = ['a_planifier', 'planifiee', 'confirmee', 'terminee', 'ajournee', 'annulee'];

        $resultat = [];

        foreach ($tousStatuts as $statut) {
            $resultat[$statut] = (int) ($compteurs[$statut] ?? 0);
        }

        return $resultat;
    }
}
