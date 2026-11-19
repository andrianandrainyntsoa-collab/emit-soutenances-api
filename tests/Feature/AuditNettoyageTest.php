<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Tests\FeatureTestCase;

/**
 * Tests du nettoyage des journaux d'audit (protégé par permission,
 * trace de l'opération conservée).
 */
class AuditNettoyageTest extends FeatureTestCase
{
    public function test_nettoyage_reserve_a_l_administrateur(): void
    {
        [$userEnseignant] = $this->creerEnseignant();

        $this->actingAs($userEnseignant, 'sanctum')
            ->deleteJson('/api/audit-logs')
            ->assertStatus(403);
    }

    public function test_nettoyage_vide_les_journaux_et_conserve_une_trace(): void
    {
        $admin = $this->creerAdmin();

        AuditLog::create([
            'user_id' => $admin->id,
            'action' => 'ACTION_TEST',
            'entite' => 'test',
            'description' => 'Trace de test',
        ]);

        $this->assertDatabaseCount('audit_logs', 1);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/audit-logs')
            ->assertOk()
            ->assertJsonPath('supprimes', 1);

        // Les journaux sont vidés, mais l'opération elle-même est tracée.
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'NETTOYAGE_JOURNAUX']);
    }
}
