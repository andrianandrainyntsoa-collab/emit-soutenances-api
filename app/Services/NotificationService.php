<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;

/**
 * Service de notification des utilisateurs.
 *
 * Conformité au livre :
 *   - Espace personnel : consultation de son profil et de ses
 *     notifications.
 *   - Modèle de domaine : les notifications permettent d'informer
 *     les utilisateurs des opérations qui les concernent.
 */
class NotificationService
{
    /**
     * Envoie (persiste) une notification pour un utilisateur.
     *
     * @param  User|int  $destinataire  Utilisateur destinataire.
     * @param  string  $titre    Titre court de la notification.
     * @param  string  $message  Contenu détaillé.
     * @param  string|null  $type   Type applicatif (planification, convocation,
     *                        evaluation, deliberation, resultat, proces_verbal).
     * @param  array|null  $donnees  Données complémentaires (ex : ids de navigation).
     */
    public function envoyer(
        User|int $destinataire,
        string $titre,
        string $message,
        ?string $type = null,
        ?array $donnees = null
    ): Notification {
        $userId = $destinataire instanceof User
            ? $destinataire->id
            : $destinataire;

        return Notification::create([
            'user_id' => $userId,
            'titre' => mb_substr($titre, 0, 180),
            'message' => $message,
            'type' => $type,
            'donnees' => $donnees,
        ]);
    }

    /**
     * Notifie plusieurs utilisateurs d'un même événement.
     *
     * @param  iterable<User|int>  $destinataires
     */
    public function envoyerATous(
        iterable $destinataires,
        string $titre,
        string $message,
        ?string $type = null,
        ?array $donnees = null
    ): void {
        foreach ($destinataires as $destinataire) {
            $this->envoyer($destinataire, $titre, $message, $type, $donnees);
        }
    }
}
