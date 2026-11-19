<?php

namespace Tests\Feature;

use Tests\FeatureTestCase;

class RoleThrottleTest extends FeatureTestCase
{
    public function test_nettoyage_resultats_limite_la_sixieme_requete_et_refuse_les_etudiants(): void
    {
        $admin = $this->creerAdmin();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($admin, 'sanctum')
                ->postJson('/api/resultats/nettoyer')
                ->assertOk();
        }

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/resultats/nettoyer')
            ->assertStatus(429);

        $responsable = $this->creerUser('responsable');
        $this->actingAs($responsable, 'sanctum')
            ->postJson('/api/resultats/nettoyer')
            ->assertOk();

        [$etudiant] = $this->creerEtudiant();
        $this->actingAs($etudiant, 'sanctum')
            ->postJson('/api/resultats/nettoyer')
            ->assertStatus(403);
    }

    public function test_suppression_pv_limitee_aux_roles_autorises_et_throttle_separe(): void
    {
        $admin = $this->creerAdmin();
        $url = '/api/proces-verbaux/999999';

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($admin, 'sanctum')
                ->deleteJson($url)
                ->assertNotFound();
        }

        $this->actingAs($admin, 'sanctum')
            ->deleteJson($url)
            ->assertStatus(429);

        $responsable = $this->creerUser('responsable');
        $this->actingAs($responsable, 'sanctum')
            ->deleteJson($url)
            ->assertNotFound();

        [$etudiant] = $this->creerEtudiant();
        $this->actingAs($etudiant, 'sanctum')
            ->deleteJson($url)
            ->assertStatus(403);
    }
}
