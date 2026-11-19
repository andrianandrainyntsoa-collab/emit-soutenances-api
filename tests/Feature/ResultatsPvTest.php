<?php

namespace Tests\Feature;

use App\Models\Deliberation;
use App\Models\Evaluation;
use App\Models\ProcesVerbal;
use App\Models\Resultat;
use App\Models\Soutenance;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\FeatureTestCase;

/**
 * Tests du module Résultats & Procès-verbaux : validation du
 * résultat, génération du PV (sans doublon), suppression du PV après
 * téléchargement, compte rendu PDF, nettoyage/archives.
 */
class ResultatsPvTest extends FeatureTestCase
{
    private function preparerChaineComplete(): array
    {
        [$userAdmin] = [$this->creerAdmin(), null];
        $admin = $userAdmin;

        [$userEtudiant, $etudiant] = $this->creerEtudiant();
        $session = $this->creerSessionOuverte();
        [$jury, $membres] = $this->creerJuryComplet($session);
        $salle = $this->creerSalle();

        $soutenance = $this->creerSoutenance($etudiant, $session, $jury, [
            'salle' => $salle,
            'statut' => 'confirmee',
        ]);

        foreach ($membres as $i => $membre) {
            Evaluation::create([
                'soutenance_id' => $soutenance->id,
                'enseignant_id' => $membre->id,
                'note' => 12 + $i,
                'validee' => true,
                'validee_le' => now(),
            ]);
        }

        $resultat = Resultat::create([
            'soutenance_id' => $soutenance->id,
            'moyenne_finale' => 13.50,
            'decision' => 'admis',
        ]);

        return [$admin, $soutenance, $resultat, $membres];
    }

    public function test_pv_etudiant_retourne_une_liste_vide_sans_soutenance(): void
    {
        [$user] = $this->creerEtudiant();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/mon-proces-verbal')
            ->assertOk()
            ->assertExactJson(['proces_verbaux' => []]);
    }

    public function test_generation_pv_par_le_president_sans_doublon(): void
    {
        Storage::fake('local');

        [$admin, $soutenance, $resultat, $membres] = $this->preparerChaineComplete();

        $resultat->update(['valide_le' => now(), 'valide_par' => $membres[0]->id]);
        $soutenance->update(['statut' => 'terminee']);

        $userPresident = $membres[0]->user;

        $reponse = $this->actingAs($userPresident, 'sanctum')
            ->postJson("/api/resultats/{$resultat->id}/proces-verbal", ['type_master' => 'recherche'])
            ->assertStatus(201)
            ->assertJsonPath('proces_verbal.numero_pv', fn ($n) => str_starts_with($n, 'PV-MR-'));

        $pvId = $reponse->json('proces_verbal.id');

        // Tentative de doublon : refusée.
        $this->actingAs($userPresident, 'sanctum')
            ->postJson("/api/resultats/{$resultat->id}/proces-verbal", ['type_master' => 'recherche'])
            ->assertStatus(409);

        $this->assertEquals(1, ProcesVerbal::where('resultat_id', $resultat->id)->where('type_master', 'recherche')->count());
    }

    public function test_suppression_pv_apres_telechargement_respecte_les_permissions(): void
    {
        Storage::fake('local');

        [$admin, $soutenance, $resultat, $membres] = $this->preparerChaineComplete();
        $resultat->update(['valide_le' => now(), 'valide_par' => $membres[0]->id]);

        $userPresident = $membres[0]->user;

        $reponse = $this->actingAs($userPresident, 'sanctum')
            ->postJson("/api/resultats/{$resultat->id}/proces-verbal", ['type_master' => 'professionnel'])
            ->assertStatus(201);

        $pvId = $reponse->json('proces_verbal.id');

        // Un enseignant non autorisé ne peut pas supprimer le PV.
        [$autreUser] = $this->creerEnseignant();
        $this->actingAs($autreUser, 'sanctum')
            ->deleteJson("/api/proces-verbaux/{$pvId}")
            ->assertStatus(403);

        // L'administrateur peut supprimer le PV (après téléchargement).
        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/proces-verbaux/{$pvId}/telecharger")
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/proces-verbaux/{$pvId}")
            ->assertOk();

        $this->assertDatabaseMissing('proces_verbaux', ['id' => $pvId]);

        // Les données officielles (soutenance, résultat) sont conservées.
        $this->assertDatabaseHas('resultats', ['id' => $resultat->id]);
        $this->assertDatabaseHas('soutenances', ['id' => $soutenance->id]);
    }

    public function test_compte_rendu_pdf_et_nettoyage_liste_resultats(): void
    {
        [$admin, $soutenance, $resultat, $membres] = $this->preparerChaineComplete();
        $resultat->update(['valide_le' => now(), 'valide_par' => $membres[0]->id]);

        // Liste de travail : le résultat apparaît.
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/resultats')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Compte rendu PDF à partir des données réelles.
        $reponse = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/resultats/compte-rendu')
            ->assertOk();

        $this->assertEquals('application/pdf', $reponse->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', substr($reponse->getContent(), 0, 4));

        // Nettoyage : archivage logique, pas de suppression.
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/resultats/nettoyer')
            ->assertOk()
            ->assertJsonPath('resultats_archives', 1);

        // La liste de travail est vide ; les archives restent consultables.
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/resultats')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/resultats?archives=1')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Les données officielles sont toujours en base.
        $this->assertDatabaseHas('resultats', ['id' => $resultat->id]);
        $this->assertNotNull($resultat->fresh()->archive_le);
    }

    public function test_nettoyage_resultats_reserve_aux_admin_responsable(): void
    {
        [$userEnseignant] = $this->creerEnseignant();

        $this->actingAs($userEnseignant, 'sanctum')
            ->postJson('/api/resultats/nettoyer')
            ->assertStatus(403);
    }

    public function test_liste_des_pv_d_un_enseignant_est_limitee_a_ses_jurys(): void
    {
        [, $soutenance, $resultat, $membres] = $this->preparerChaineComplete();
        $pvVisible = ProcesVerbal::create([
            'resultat_id' => $resultat->id,
            'numero_pv' => 'PV-MR-2026-000001',
            'type_master' => 'recherche',
            'genere_par' => $membres[0]->id,
            'genere_le' => now(),
            'statut' => 'genere',
        ]);

        [, $autreEtudiant] = $this->creerEtudiant();
        $session = $soutenance->session;
        [$autreJury, $autresMembres] = $this->creerJuryComplet($session);
        $autreSoutenance = $this->creerSoutenance($autreEtudiant, $session, $autreJury);
        $autreResultat = Resultat::create([
            'soutenance_id' => $autreSoutenance->id,
            'moyenne_finale' => 14,
            'decision' => 'admis',
        ]);
        ProcesVerbal::create([
            'resultat_id' => $autreResultat->id,
            'numero_pv' => 'PV-MR-2026-000002',
            'type_master' => 'recherche',
            'genere_par' => $autresMembres[0]->id,
            'genere_le' => now(),
            'statut' => 'genere',
        ]);

        $this->actingAs($membres[0]->user, 'sanctum')
            ->getJson('/api/proces-verbaux')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pvVisible->id);
    }

    public function test_validation_resultat_par_le_president_avec_note_deliberation(): void
    {
        [$admin, $soutenance, $resultat, $membres] = $this->preparerChaineComplete();

        // Délibération : le président retient une note spécifique.
        Deliberation::create([
            'soutenance_id' => $soutenance->id,
            'president_enseignant_id' => $membres[0]->id,
            'decision' => 'admis',
            'note_finale_retenue' => 15.25,
            'deliberee_le' => now(),
        ]);

        $userPresident = $membres[0]->user;

        $reponse = $this->actingAs($userPresident, 'sanctum')
            ->postJson("/api/resultats/{$resultat->id}/valider")
            ->assertOk();

        $this->assertEquals(15.25, (float) $reponse->json('data.moyenne_finale'));
        $this->assertNotNull($reponse->json('data.valide_le'));

        // La soutenance est passée à terminée.
        $this->assertEquals('terminee', $soutenance->fresh()->statut);
    }
}
