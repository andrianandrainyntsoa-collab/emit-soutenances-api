<?php

namespace App\Services;

use App\Models\Jury;
use Illuminate\Validation\ValidationException;

/**
 * Service de gestion de la composition des jurys.
 *
 * Conformité au livre :
 *   - RG03 : chaque soutenance est associée à un jury composé de
 *     membres auxquels sont attribués des rôles, notamment
 *     Président, Rapporteur et Examinateur(s).
 *   - Le processus d'évaluation déjà implémenté repose sur trois
 *     évaluations distinctes (une par membre) : la composition
 *     attendue est donc un Président, un Rapporteur et un
 *     Examinateur.
 */
class JuryService
{
    public const FONCTION_PRESIDENT = 'president';
    public const FONCTION_RAPPORTEUR = 'rapporteur';
    public const FONCTION_EXAMINATEUR = 'examinateur';

    /** Fonctions autorisées pour un membre du jury. */
    public const FONCTIONS = [
        self::FONCTION_PRESIDENT,
        self::FONCTION_RAPPORTEUR,
        self::FONCTION_EXAMINATEUR,
    ];

    /**
     * Vérifie qu'une fonction est valide.
     */
    public function fonctionValide(string $fonction): bool
    {
        return in_array(mb_strtolower(trim($fonction)), self::FONCTIONS, true);
    }

    /**
     * Vérifie que l'ajout d'un membre ne viole pas les règles de
     * composition du jury (unicité du président, unicité du
     * rapporteur, membre unique, fonction valide).
     */
    public function verifierAjoutMembre(Jury $jury, int $enseignantId, string $fonction): void
    {
        $fonction = mb_strtolower(trim($fonction));

        if (!$this->fonctionValide($fonction)) {
            throw ValidationException::withMessages([
                'fonction' => 'La fonction doit être president, rapporteur ou examinateur.',
            ]);
        }

        $dejaMembre = $jury->membresJury()
            ->where('enseignant_id', $enseignantId)
            ->exists();

        if ($dejaMembre) {
            throw ValidationException::withMessages([
                'enseignant_id' => 'Cet enseignant fait déjà partie de ce jury.',
            ]);
        }

        if (in_array($fonction, [self::FONCTION_PRESIDENT, self::FONCTION_RAPPORTEUR], true)) {
            $dejaOccupe = $jury->membresJury()
                ->whereRaw('LOWER(fonction) = ?', [$fonction])
                ->exists();

            if ($dejaOccupe) {
                throw ValidationException::withMessages([
                    'fonction' => "Ce jury possède déjà un {$fonction}.",
                ]);
            }
        }
    }

    /**
     * Vérifie que la composition d'un jury est complète :
     * un Président, un Rapporteur et un Examinateur, tous distincts.
     *
     * Cette vérification est utilisée avant la planification d'une
     * soutenance : un jury incomplet ne peut pas évaluer puis
     * délibérer selon le processus défini.
     */
    public function verifierCompositionComplete(Jury $jury): void
    {
        $membres = $jury->membresJury()->with('enseignant.user')->get();

        if ($membres->count() !== 3) {
            throw ValidationException::withMessages([
                'jury_id' => 'Le jury doit être composé de trois membres (un Président, un Rapporteur et un Examinateur).',
            ]);
        }

        foreach (self::FONCTIONS as $fonctionAttendue) {
            $nombre = $membres->filter(
                fn ($membre) => mb_strtolower(trim($membre->fonction)) === $fonctionAttendue
            )->count();

            if ($nombre !== 1) {
                throw ValidationException::withMessages([
                    'jury_id' => "Le jury doit compter exactement un {$fonctionAttendue}.",
                ]);
            }
        }
    }

    /**
     * Composition complète ? (version booléenne pour les contrôles souples)
     */
    public function estCompositionComplete(Jury $jury): bool
    {
        try {
            $this->verifierCompositionComplete($jury);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    /**
     * Retourne le membre président d'un jury, s'il existe.
     */
    public function presidentDe(Jury $jury): ?object
    {
        return $jury->membresJury->first(
            fn ($membre) => mb_strtolower(trim($membre->fonction)) === self::FONCTION_PRESIDENT
        );
    }
}
