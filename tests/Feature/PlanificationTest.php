<?php

namespace Tests\Feature;

use App\Models\Soutenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\FeatureTestCase;

/**
 * Tests de la planification : sessions disponibles, dates/heures
 * directes, conflits (groupe de jury, salle, étudiant), clôture de
 * session, règles de statut.
 */
class PlanificationTest extends FeatureTestCase
{
    public function test_sessions_disponibles_ninclut_que_les_sessions_ouvertes(): void
    {
        $admin = $this->creerAdmin();
        $this->creerSessionOuverte();

        \App\Models\SessionSoutenance::create([
            'nom' => 'Session en préparation',
            'date_debut' => now()->toDateString(),
            'date_fin' => now()->addDays(10)->toDateString(),
            'annee_universitaire' => '2025-2026',
            'statut' => 'planification',
        ]);

        \App\Models\SessionSoutenance::create([
            'nom' => 'Session clôturée',
            'date_debut' => now()->subDays(10)->toDateString(),
            'date_fin' => now()->subDays(5)->toDateString(),
            'annee_universitaire' => '2024-2025',
            'statut' => 'cloturee',
        ]);

        $reponse = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/sessions?disponibles=1')
            ->assertOk();

        $statuts = array_column($reponse->json(), 'statut');

        $this->assertNotEmpty($statuts);
        $this->assertNotContains('planification', $statuts);
        $this->assertNotContains('cloturee', $statuts);
        $this->assertContains('ouverte', $statuts);
    }

    public function test_creation_soutenance_avec_date_et_heures_directes(): void
    {
        $admin = $this->creerAdmin();
        [$user, $etudiant] = $this->creerEtudiant();
        $session = $this->creerSessionOuverte();
        [$jury] = $this->creerJuryComplet($session);
        $salle = $this->creerSalle();

        $date = now()->addDays(2)->toDateString();

        $reponse = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/soutenances', [
                'etudiant_id' => $etudiant->id,
                'session_id' => $session->id,
                'jury_id' => $jury->id,
                'salle_id' => $salle->id,
                'date_soutenance' => $date,
                'heure_debut' => '08:00',
                'heure_fin' => '09:30',
                'titre_memoire' => 'Nouveau mémoire',
            ])
            ->assertStatus(201)
            ->assertJsonPath('soutenance.statut', 'planifiee');

        $this->assertDatabaseHas('soutenances', [
            'etudiant_id' => $etudiant->id,
            'date_soutenance' => $date,
            'statut' => 'planifiee',
        ]);
    }

    public function test_duree_de_soutenance_doit_etre_de_90_minutes(): void
    {
        $admin = $this->creerAdmin();
        [, $etudiant] = $this->creerEtudiant();
        $session = $this->creerSessionOuverte();
        [$jury] = $this->creerJuryComplet($session);
        $salle = $this->creerSalle();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/soutenances', [
                'etudiant_id' => $etudiant->id,
                'session_id' => $session->id,
                'jury_id' => $jury->id,
                'salle_id' => $salle->id,
                'date_soutenance' => now()->addDays(2)->toDateString(),
                'heure_debut' => '08:00',
                'heure_fin' => '10:00',
                'titre_memoire' => 'Mémoire',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.heure_fin.0', 'La durée d\'une soutenance doit être de 90 minutes.');
    }

    public function test_heure_fin_avant_debut_refusee(): void
    {
        $admin = $this->creerAdmin();
        [$user, $etudiant] = $this->creerEtudiant();
        $session = $this->creerSessionOuverte();
        [$jury] = $this->creerJuryComplet($session);
        $salle = $this->creerSalle();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/soutenances', [
                'etudiant_id' => $etudiant->id,
                'session_id' => $session->id,
                'jury_id' => $jury->id,
                'salle_id' => $salle->id,
                'date_soutenance' => now()->addDays(2)->toDateString(),
                'heure_debut' => '10:00',
                'heure_fin' => '08:00',
                'titre_memoire' => 'Mémoire',
            ])
            ->assertStatus(422);
    }

    public function test_session_non_ouverte_refusee(): void
    {
        $admin = $this->creerAdmin();
        [$user, $etudiant] = $this->creerEtudiant();

        $session = \App\Models\SessionSoutenance::create([
            'nom' => 'Session planification',
            'date_debut' => now()->toDateString(),
            'date_fin' => now()->addDays(10)->toDateString(),
            'annee_universitaire' => '2025-2026',
            'statut' => 'planification',
        ]);

        [$jury] = $this->creerJuryComplet($session);
        $salle = $this->creerSalle();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/soutenances', [
                'etudiant_id' => $etudiant->id,
                'session_id' => $session->id,
                'jury_id' => $jury->id,
                'salle_id' => $salle->id,
                'date_soutenance' => now()->addDays(2)->toDateString(),
                'heure_debut' => '08:00',
                'heure_fin' => '10:00',
                'titre_memoire' => 'Mémoire',
            ])
            ->assertStatus(409);
    }

    public function test_conflit_groupe_de_jury_sur_intervalles_chevauchants(): void
    {
        $admin = $this->creerAdmin();
        [$user, $etudiantA] = $this->creerEtudiant();
        [$u2, $etudiantB] = $this->creerEtudiant();
        $session = $this->creerSessionOuverte();
        [$jury] = $this->creerJuryComplet($session);
        $salleA = $this->creerSalle();
        $salleB = $this->creerSalle();

        $dateConflit = now()->addDays(10)->toDateString();

        $this->creerSoutenance($etudiantA, $session, $jury, ['salle' => $salleA, 'date' => $dateConflit, 'debut' => '08:00', 'fin' => '10:00']);

        // Chevauchement (09:00–11:00 contre 08:00–10:00) : refusé.
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/soutenances', [
                'etudiant_id' => $etudiantB->id,
                'session_id' => $session->id,
                'jury_id' => $jury->id,
                'salle_id' => $salleB->id,
                'date_soutenance' => $dateConflit,
                'heure_debut' => '09:00',
                'heure_fin' => '10:30',
                'titre_memoire' => 'Mémoire B',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.jury_id.0', fn ($m) => str_contains($m, 'groupe de jury'));

        // Intervalles adjacents (première finit à 10:00, suivante commence à 10:00) : autorisé.
        [$u3, $etudiantC] = $this->creerEtudiant();
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/soutenances', [
                'etudiant_id' => $etudiantC->id,
                'session_id' => $session->id,
                'jury_id' => $jury->id,
                'salle_id' => $salleB->id,
                'date_soutenance' => $dateConflit,
                'heure_debut' => '10:00',
                'heure_fin' => '11:30',
                'titre_memoire' => 'Mémoire C',
            ])
            ->assertStatus(201);
    }

    public function test_jury_d_une_autre_session_refuse(): void
    {
        $admin = $this->creerAdmin();
        [, $etudiant] = $this->creerEtudiant();
        $session = $this->creerSessionOuverte();
        $autreSession = $this->creerSessionOuverte();
        [$jury] = $this->creerJuryComplet($autreSession);
        $salle = $this->creerSalle();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/soutenances', [
                'etudiant_id' => $etudiant->id,
                'session_id' => $session->id,
                'jury_id' => $jury->id,
                'salle_id' => $salle->id,
                'date_soutenance' => now()->addDays(2)->toDateString(),
                'heure_debut' => '08:00',
                'heure_fin' => '09:30',
                'titre_memoire' => 'Mémoire',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.jury_id.0', 'Ce jury n\'appartient pas à la session sélectionnée.');
    }

    public function test_etudiant_avec_soutenance_active_exclu_et_refuse(): void
    {
        $admin = $this->creerAdmin();
        [$user, $etudiant] = $this->creerEtudiant();
        $session = $this->creerSessionOuverte();
        [$jury] = $this->creerJuryComplet($session);
        $salle = $this->creerSalle();

        $this->creerSoutenance($etudiant, $session, $jury, ['salle' => $salle, 'statut' => 'planifiee']);

        // Filtre « sans soutenance active » : l'étudiant n'apparaît pas.
        $reponse = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/etudiants?sans_soutenance_active=1')
            ->assertOk();

        $ids = array_column($reponse->json('data'), 'id');
        $this->assertNotContains($etudiant->id, $ids);

        // Création refusée côté backend (double vérification).
        [$u2, $etudiantB] = $this->creerEtudiant();
        $this->creerSoutenance($etudiantB, $session, $jury, ['salle' => $salle, 'statut' => 'confirmee']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/soutenances', [
                'etudiant_id' => $etudiantB->id,
                'session_id' => $session->id,
                'jury_id' => $jury->id,
                'salle_id' => $salle->id,
                'date_soutenance' => now()->addDays(3)->toDateString(),
                'heure_debut' => '08:00',
                'heure_fin' => '10:00',
                'titre_memoire' => 'Second mémoire',
            ])
            ->assertStatus(409);
    }

    public function test_une_soutenance_terminee_ne_bloque_pas_un_etudiant(): void
    {
        $admin = $this->creerAdmin();
        [$user, $etudiant] = $this->creerEtudiant();
        $session = $this->creerSessionOuverte();
        [$jury] = $this->creerJuryComplet($session);
        $salle = $this->creerSalle();

        $this->creerSoutenance($etudiant, $session, $jury, ['salle' => $salle, 'statut' => 'terminee']);

        $reponse = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/etudiants?sans_soutenance_active=1')
            ->assertOk();

        $ids = array_column($reponse->json('data'), 'id');
        $this->assertContains($etudiant->id, $ids);
    }

    public function test_jurys_disponibles_exclut_les_groupes_occupes(): void
    {
        $admin = $this->creerAdmin();
        [$user, $etudiant] = $this->creerEtudiant();
        $session = $this->creerSessionOuverte();
        [$jury1, $membres1] = $this->creerJuryComplet($session);
        [$jury2, $membres2] = $this->creerJuryComplet($session);
        $salle = $this->creerSalle();

        // Jury 1 occupé de 08:00 à 10:00.
        $dateJury = now()->addDays(10)->toDateString();
        $this->creerSoutenance($etudiant, $session, $jury1, ['salle' => $salle, 'date' => $dateJury, 'debut' => '08:00', 'fin' => '10:00']);

        $reponse = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/jurys/disponibles?' . http_build_query([
                'session_id' => $session->id,
                'date' => $dateJury,
                'heure_debut' => '09:00',
                'heure_fin' => '11:00',
            ]))
            ->assertOk();

        $ids = array_column($reponse->json(), 'id');
        $this->assertNotContains($jury1->id, $ids);
        $this->assertContains($jury2->id, $ids);
    }

    public function test_cloture_session_marque_les_planifications_terminees(): void
    {
        $admin = $this->creerAdmin();
        [$user, $etudiant] = $this->creerEtudiant();
        $session = $this->creerSessionOuverte();
        [$jury] = $this->creerJuryComplet($session);
        $salle = $this->creerSalle();

        $s1 = $this->creerSoutenance($etudiant, $session, $jury, ['salle' => $salle, 'statut' => 'planifiee']);
        [$u2, $e2] = $this->creerEtudiant();
        $s2 = $this->creerSoutenance([$u2, $e2], $session, $jury, ['salle' => $salle, 'date' => now()->addDays(5)->toDateString(), 'statut' => 'confirmee']);
        [$u3, $e3] = $this->creerEtudiant();
        $s3 = $this->creerSoutenance([$u3, $e3], $session, $jury, ['salle' => $salle, 'date' => now()->addDays(6)->toDateString(), 'statut' => 'terminee']);

        $reponse = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/cloturer")
            ->assertOk();

        $this->assertEquals(2, $reponse->json('soutenances_terminees'));

        $this->assertEquals('terminee', $s1->fresh()->statut);
        $this->assertEquals('terminee', $s2->fresh()->statut);
        $this->assertEquals('terminee', $s3->fresh()->statut);
        $this->assertEquals('cloturee', $session->fresh()->statut);
    }

    public function test_soutenance_confirmee_ne_peut_pas_etre_annulee_ni_ajournee(): void
    {
        $admin = $this->creerAdmin();
        [$user, $etudiant] = $this->creerEtudiant();
        $session = $this->creerSessionOuverte();
        [$jury] = $this->creerJuryComplet($session);

        $s = $this->creerSoutenance($etudiant, $session, $jury, ['statut' => 'confirmee']);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/soutenances/{$s->id}")
            ->assertStatus(409);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/soutenances/{$s->id}/ajourner")
            ->assertStatus(409);

        // La soutenance est toujours là et confirmée.
        $this->assertEquals('confirmee', $s->fresh()->statut);
    }

    public function test_soutenance_terminee_supprimable_et_donnees_officielles_respectees(): void
    {
        $admin = $this->creerAdmin();
        [$user, $etudiant] = $this->creerEtudiant();
        $session = $this->creerSessionOuverte();
        [$jury] = $this->creerJuryComplet($session);

        $s = $this->creerSoutenance($etudiant, $session, $jury, ['statut' => 'terminee']);

        // Suppression autorisée (aucune évaluation validée, aucun résultat).
        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/soutenances/{$s->id}")
            ->assertOk();

        $this->assertDatabaseMissing('soutenances', ['id' => $s->id]);
    }

    public function test_suppression_refusee_si_evaluations_validees(): void
    {
        $admin = $this->creerAdmin();
        [$user, $etudiant] = $this->creerEtudiant();
        $session = $this->creerSessionOuverte();
        [$jury, $membres] = $this->creerJuryComplet($session);

        $s = $this->creerSoutenance($etudiant, $session, $jury, ['statut' => 'terminee']);

        foreach ($membres as $i => $membre) {
            \App\Models\Evaluation::create([
                'soutenance_id' => $s->id,
                'enseignant_id' => $membre->id,
                'note' => 12 + $i,
                'validee' => true,
                'validee_le' => now(),
            ]);
        }

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/soutenances/{$s->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('soutenances', ['id' => $s->id]);
    }
}
