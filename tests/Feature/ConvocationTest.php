<?php

namespace Tests\Feature;

use App\Models\Convocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\FeatureTestCase;

/**
 * Tests des convocations : regroupement par soutenance, envoi groupé
 * réel (SMTP simulé par Mail::fake en test), retrait des lignes
 * envoyées de la liste en attente.
 */
class ConvocationTest extends FeatureTestCase
{
    public function test_regroupement_par_soutenance_et_envoi_groupe(): void
    {
        Mail::fake();

        $admin = $this->creerAdmin();
        [$userEtudiant, $etudiant] = $this->creerEtudiant();
        $session = $this->creerSessionOuverte();
        [$jury, $membres] = $this->creerJuryComplet($session);
        $salle = $this->creerSalle();

        $s = $this->creerSoutenance($etudiant, $session, $jury, ['salle' => $salle, 'statut' => 'confirmee']);

        // Liste regroupée : vide avant génération.
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/convocations/par-soutenance')
            ->assertOk()
            ->assertJsonCount(0, 'groupes');

        // Génération (une convocation par destinataire).
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/soutenances/{$s->id}/convocations/generer")
            ->assertStatus(201);

        // Une ligne = la soutenance, avec les destinataires regroupés
        // (1 étudiant + 3 membres du jury).
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/convocations/par-soutenance')
            ->assertOk()
            ->assertJsonCount(1, 'groupes')
            ->assertJsonPath('groupes.0.total_convocations', 4)
            ->assertJsonPath('groupes.0.toutes_envoyees', false);

        // Envoi groupé : chaque destinataire reçoit son propre e-mail
        // avec son PDF (pas de courriel collectif).
        $reponse = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/soutenances/{$s->id}/convocations/envoyer")
            ->assertOk()
            ->assertJsonPath('reussi', true);

        Mail::assertSentCount(4);

        $destinataires = collect(Mail::sent(\Illuminate\Mail\Mailable::class))->count();
        $this->assertEquals(4, Convocation::where('soutenance_id', $s->id)->whereNotNull('date_envoi')->count());

        // Après envoi réussi : la ligne disparaît de la liste en attente.
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/convocations/par-soutenance')
            ->assertOk()
            ->assertJsonCount(0, 'groupes');

        // Mais reste consultable dans la liste complète (historique).
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/convocations/par-soutenance?inclure_envoyees=1')
            ->assertOk()
            ->assertJsonCount(1, 'groupes');
    }

    public function test_envoi_partiel_ne_retire_pas_la_ligne(): void
    {
        Mail::fake();

        $admin = $this->creerAdmin();
        [$userEtudiant, $etudiant] = $this->creerEtudiant();
        $session = $this->creerSessionOuverte();
        [$jury, $membres] = $this->creerJuryComplet($session);
        $salle = $this->creerSalle();

        $s = $this->creerSoutenance($etudiant, $session, $jury, ['salle' => $salle, 'statut' => 'confirmee']);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/soutenances/{$s->id}/convocations/generer")
            ->assertStatus(201);

        // Un destinataire sans e-mail → échec partiel.
        $userSansEmail = \App\Models\User::find($membres[0]->user_id);
        $emailAvant = $userSansEmail->email;
        $userSansEmail->update(['email' => '']);

        $reponse = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/soutenances/{$s->id}/convocations/envoyer")
            ->assertStatus(207)
            ->assertJsonPath('reussi', false);

        $this->assertNotEmpty($reponse->json('echecs'));

        // La ligne reste dans la liste en attente (envoi incomplet).
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/convocations/par-soutenance')
            ->assertOk()
            ->assertJsonCount(1, 'groupes');

        // Restaure l'e-mail (hygiène du test).
        $userSansEmail->update(['email' => $emailAvant]);
    }

    public function test_mes_convocations_ne_renvoie_pas_les_conversations_non_generees(): void
    {
        Mail::fake();

        [$userEtudiant] = $this->creerEtudiant();
        $session = $this->creerSessionOuverte();
        [$jury] = $this->creerJuryComplet($session);
        $salle = $this->creerSalle();
        $s = $this->creerSoutenance([$userEtudiant, $this->creerEtudiant()[1]], $session, $jury, ['salle' => $salle]);
        $etudiantUser = \App\Models\User::find($s->etudiant->user_id);

        Convocation::create([
            'soutenance_id' => $s->id,
            'type_destinataire' => 'etudiant',
            'destinataire_user_id' => $etudiantUser->id,
            'statut' => 'a_generer',
            'fichier_path' => 'convocations/x.pdf',
        ]);

        $this->actingAs($etudiantUser, 'sanctum')
            ->getJson('/api/mes-convocations')
            ->assertOk()
            ->assertJsonCount(0);
    }
}
