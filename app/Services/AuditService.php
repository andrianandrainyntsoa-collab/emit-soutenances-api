<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Service de traçabilité.
 *
 * Conformité au livre :
 *   - RG17 : les opérations importantes liées aux résultats et aux
 *     procès-verbaux doivent pouvoir être tracées afin d'assurer le
 *     suivi des actions effectuées dans le système.
 *   - BF12 : conserver les traces des opérations importantes
 *     réalisées dans le système.
 */
class AuditService
{
    /**
     * Enregistre une trace d'audit pour une opération importante.
     *
     * @param  string  $action       Code court de l'action (ex : VALIDATION_RESULTAT).
     * @param  string|null  $entite  Nom de l'entité concernée (ex : resultats).
     * @param  int|null  $entiteId   Identifiant de l'entité concernée.
     * @param  string|null  $description  Description lisible de l'opération.
     * @param  array|null  $details   Données complémentaires sérialisables.
     */
    public function enregistrer(
        string $action,
        ?string $entite = null,
        ?int $entiteId = null,
        ?string $description = null,
        ?array $details = null
    ): void {
        try {
            AuditLog::create([
                'user_id' => Auth::id(),
                'action' => mb_substr($action, 0, 100),
                'entite' => $entite !== null ? mb_substr($entite, 0, 100) : null,
                'entite_id' => $entiteId,
                'description' => $description,
                'adresse_ip' => $this->adresseIp(),
                'details' => $details,
            ]);
        } catch (\Throwable $e) {
            // La traçabilité ne doit jamais bloquer le processus métier.
            report($e);
        }
    }

    /**
     * Adresse IP du client courant, si disponible.
     */
    private function adresseIp(): ?string
    {
        $request = request();

        if (!$request instanceof Request) {
            return null;
        }

        return mb_substr((string) $request->ip(), 0, 45);
    }
}
