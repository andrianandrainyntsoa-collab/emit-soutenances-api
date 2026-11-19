<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Tests\FeatureTestCase;

/**
 * Tests de la suppression des notifications (web + mobile) :
 *   - chaque utilisateur ne supprime que SES notifications ;
 *   - suppression individuelle ;
 *   - suppression globale (« tout supprimer ») ;
 *   - suppression des seules notifications lues (lues_seulement=1).
 */
class NotificationSuppressionTest extends FeatureTestCase
{
    private function creerNotifications(User $user, int $nombre): void
    {
        for ($i = 1; $i <= $nombre; $i++) {
            Notification::create([
                'user_id' => $user->id,
                'titre' => "Notification test {$i}",
                'message' => "Message de test {$i}",
                'type' => 'compte',
                'lue_le' => $i % 2 === 0 ? now() : null, // 1 sur 2 déjà lue
            ]);
        }
    }

    public function test_un_utilisateur_supprime_sa_propre_notification(): void
    {
        $user = $this->creerUser('etudiant');
        $autre = $this->creerUser('enseignant');

        $this->creerNotifications($user, 2);
        $this->creerNotifications($autre, 1);

        $sienne = Notification::where('user_id', $user->id)->first();

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/notifications/{$sienne->id}")
            ->assertOk()
            ->assertJsonFragment(['message' => 'Notification supprimée.']);

        $this->assertDatabaseMissing('notifications', ['id' => $sienne->id]);
        // La notification de l'autre utilisateur est intacte.
        $this->assertDatabaseHas('notifications', ['user_id' => $autre->id]);
    }

    public function test_suppression_refusee_pour_la_notification_d_autrui(): void
    {
        $user = $this->creerUser('etudiant');
        $autre = $this->creerUser('enseignant');

        $this->creerNotifications($autre, 1);
        $notificationAutre = Notification::where('user_id', $autre->id)->first();

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/notifications/{$notificationAutre->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('notifications', ['id' => $notificationAutre->id]);
    }

    public function test_suppression_totale_ne_concerne_que_le_proprietaire(): void
    {
        $user = $this->creerUser('etudiant');
        $autre = $this->creerUser('enseignant');

        $this->creerNotifications($user, 4);
        $this->creerNotifications($autre, 3);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/notifications')
            ->assertOk()
            ->assertJsonFragment(['supprimees' => 4]);

        $this->assertDatabaseMissing('notifications', ['user_id' => $user->id]);
        $this->assertDatabaseCount('notifications', 3);
    }

    public function test_suppression_des_seules_notifications_lues(): void
    {
        $user = $this->creerUser('etudiant');

        $this->creerNotifications($user, 6); // 3 lues, 3 non lues

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/notifications?lues_seulement=1')
            ->assertOk()
            ->assertJsonFragment(['supprimees' => 3]);

        $restantes = Notification::where('user_id', $user->id)->get();
        $this->assertCount(3, $restantes);
        $this->assertTrue($restantes->every(fn ($n) => $n->lue_le === null));
    }

    public function test_requires_authentification(): void
    {
        $this->deleteJson('/api/notifications')->assertStatus(401);
        $this->getJson('/api/notifications')->assertStatus(401);
    }
}
