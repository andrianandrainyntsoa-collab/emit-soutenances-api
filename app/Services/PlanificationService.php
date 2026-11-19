<?php

namespace App\Services;

use App\Models\DisponibiliteSalle;
use App\Models\Jury;
use App\Models\Salle;
use App\Models\Soutenance;
use Illuminate\Validation\ValidationException;

/**
 * Service de planification : vérification des conflits.
 *
 * La date, l'heure de début et l'heure de fin sont désormais portées
 * directement par la soutenance (sélection intégrée dans la modale
 * de planification). Le chevauchement est défini comme une
 * intersection réelle d'intervalles : deux soutenances successives
 * dont la première se termine exactement au début de la suivante ne
 * sont PAS conflictuelles.
 *
 * Contrôles appliqués :
 *   - cohérence horaire (fin > début) ;
 *   - date dans la plage de la session ;
 *   - salle disponible et non réservée sur l'intervalle (+ respect
 *     des disponibilités déclarées de la salle) ;
 *   - groupe de jury libre sur l'intervalle (soutenances déjà
 *     attribuées au groupe) ;
 *   - engagements individuels des membres (autres jurys occupés).
 */
class PlanificationService
{
    /**
     * Vérifie la planification d'une soutenance à partir de ses
     * propres horaires (date_soutenance, heure_debut, heure_fin),
     * avec repli sur le créneau lié pour les anciennes données.
     */
    public function verifier(Soutenance $soutenance, ?int $salleId = null, ?int $juryId = null): void
    {
        [$date, $debut, $fin] = $this->horaires($soutenance);

        if ($fin <= $debut) {
            throw ValidationException::withMessages([
                'heure_fin' => 'L\'heure de fin doit être postérieure à l\'heure de début.',
            ]);
        }

        if ($this->minutesDepuisMinuit($fin) - $this->minutesDepuisMinuit($debut) !== 90) {
            throw ValidationException::withMessages([
                'heure_fin' => 'La durée d\'une soutenance doit être de 90 minutes.',
            ]);
        }

        $session = $soutenance->session;

        if ($session && (
            $date < $session->date_debut->format('Y-m-d')
            || $date > $session->date_fin->format('Y-m-d')
        )) {
            throw ValidationException::withMessages([
                'date_soutenance' => 'La date de soutenance est en dehors des dates de la session '
                    .'(' . $session->date_debut->format('d/m/Y') . ' au ' . $session->date_fin->format('d/m/Y') . ').',
            ]);
        }

        $conflits = Soutenance::query()
            ->where('id', '!=', $soutenance->id ?? 0)
            ->whereNotIn('statut', ['annulee', 'annulée'])
            ->where('date_soutenance', $date)
            ->where('heure_debut', '<', $fin)
            ->where('heure_fin', '>', $debut);

        if ($salleId !== null) {
            $salle = Salle::findOrFail($salleId);

            if (!$salle->disponible) {
                throw ValidationException::withMessages([
                    'salle_id' => 'Cette salle est désactivée.',
                ]);
            }

            if ((clone $conflits)->where('salle_id', $salleId)->exists()) {
                throw ValidationException::withMessages([
                    'salle_id' => 'Cette salle est déjà réservée sur cet intervalle horaire.',
                ]);
            }

            // Si des disponibilités sont déclarées pour cette salle,
            // l'intervalle doit être entièrement couvert par l'une d'elles.
            $salleAUneDisponibilite = DisponibiliteSalle::query()
                ->where('salle_id', $salleId)
                ->whereDate('date_disponible', $date)
                ->exists();

            if ($salleAUneDisponibilite) {
                $disponibiliteSalle = DisponibiliteSalle::query()
                    ->where('salle_id', $salleId)
                    ->whereDate('date_disponible', $date)
                    ->where('heure_debut', '<=', $debut)
                    ->where('heure_fin', '>=', $fin)
                    ->exists();

                if (!$disponibiliteSalle) {
                    throw ValidationException::withMessages([
                        'salle_id' => 'La disponibilité enregistrée pour cette salle ne couvre pas cet intervalle.',
                    ]);
                }
            }
        }

        if ($juryId !== null) {
            $jury = Jury::with('membresJury')->findOrFail($juryId);

            if ((int) $jury->session_id !== (int) $soutenance->session_id) {
                throw ValidationException::withMessages([
                    'jury_id' => 'Ce jury n\'appartient pas à la session sélectionnée.',
                ]);
            }

            if ($jury->membresJury->isEmpty()) {
                throw ValidationException::withMessages([
                    'jury_id' => 'Ce jury ne possède aucun membre.',
                ]);
            }

            // Le groupe de jury est attribué comme un groupe : deux
            // soutenances ne peuvent pas utiliser le même groupe sur
            // des intervalles qui se chevauchent.
            if ((clone $conflits)->where('jury_id', $juryId)->exists()) {
                throw ValidationException::withMessages([
                    'jury_id' => 'Ce groupe de jury est déjà affecté à une autre soutenance sur cet intervalle.',
                ]);
            }

            // Engagements individuels : un membre présent dans un
            // autre jury occupé sur l'intervalle bloque la planification.
            foreach ($jury->membresJury as $membre) {
                $enseignantId = $membre->enseignant_id;

                $enseignantOccupe = (clone $conflits)
                    ->whereHas('jury.membresJury', function ($query) use ($enseignantId) {
                        $query->where('enseignant_id', $enseignantId);
                    })
                    ->exists();

                if ($enseignantOccupe) {
                    throw ValidationException::withMessages([
                        'jury_id' => 'Au moins un membre du jury est déjà mobilisé sur cet intervalle.',
                    ]);
                }
            }
        }
    }

    /**
     * Indique si un groupe de jury est disponible sur un intervalle
     * (utilisé pour filtrer le menu déroulant des jurys).
     */
    public function juryDisponible(int $juryId, string $date, string $debut, string $fin, int $ignorerSoutenanceId = 0): bool
    {
        $conflits = Soutenance::query()
            ->where('id', '!=', $ignorerSoutenanceId)
            ->whereNotIn('statut', ['annulee', 'annulée'])
            ->where('date_soutenance', $date)
            ->where('heure_debut', '<', $fin)
            ->where('heure_fin', '>', $debut);

        if ((clone $conflits)->where('jury_id', $juryId)->exists()) {
            return false;
        }

        $membresIds = Jury::find($juryId)?->membresJury()->pluck('enseignant_id')->all() ?? [];

        if ($membresIds === []) {
            return false; // un jury sans membre n'est pas utilisable
        }

        $membreOccupe = (clone $conflits)
            ->whereHas('jury.membresJury', fn ($query) => $query->whereIn('enseignant_id', $membresIds))
            ->exists();

        return !$membreOccupe;
    }

    /**
     * Horaires effectifs de la soutenance [date, début, fin],
     * normalisés en chaînes « Y-m-d » et « H:i:s ».
     */
    private function horaires(Soutenance $soutenance): array
    {
        if ($soutenance->date_soutenance && $soutenance->heure_debut && $soutenance->heure_fin) {
            $date = $soutenance->date_soutenance->toDateString();
            $debut = substr((string) $soutenance->heure_debut, 0, 8);
            $fin = substr((string) $soutenance->heure_fin, 0, 8);

            return [$date, $debut, $fin];
        }

        // Repli historique : anciennes données portées par le créneau.
        $creneau = $soutenance->creneau;

        if ($creneau) {
            return [
                $creneau->date_soutenance->toDateString(),
                substr((string) $creneau->heure_debut, 0, 8),
                substr((string) $creneau->heure_fin, 0, 8),
            ];
        }

        throw ValidationException::withMessages([
            'date_soutenance' => 'La date et les heures de la soutenance sont requises.',
        ]);
    }

    private function minutesDepuisMinuit(string $heure): int
    {
        [$heures, $minutes] = array_map('intval', explode(':', $heure));

        return ($heures * 60) + $minutes;
    }
}
