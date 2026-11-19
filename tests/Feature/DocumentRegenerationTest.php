<?php

namespace Tests\Feature;

use App\Models\Evaluation;
use App\Models\ProcesVerbal;
use App\Models\Resultat;
use Illuminate\Support\Facades\Storage;
use Tests\FeatureTestCase;

/**
 * CORRECTION (« document introuvable sur le serveur ») : lorsqu'un
 * enregistrement (convocation ou PV) existe toujours en base mais que
 * son fichier PDF a disparu du stockage du serveur (p.ex. après une
 * recopie ou une réinstallation du projet), le téléchargement doit
 * régénérer automatiquement le document à partir des données
 * officielles au lieu de renvoyer une erreur 404.
 */
class DocumentRegenerationTest extends FeatureTestCase
{
    public function test_document_routes_nacceptent_pas_de_token_dans_l_url(): void
    {
        $admin = $this->creerAdmin();
        $token = $admin->createToken('test-token-url')->plainTextToken;

        $this->getJson('/api/convocations?token=' . urlencode($token))
            ->assertUnauthorized();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/convocations')
            ->assertOk();
    }

    public function test_convocation_fichier_manquant_regenere_au_telechargement(): void
    {
        Storage::fake('local');

        $admin = $this->creerAdmin();
        [$userEtudiant, $etudiant] = $this->creerEtudiant();
        $session = $this->creerSessionOuverte();
        [$jury, $membres] = $this->creerJuryComplet($session);
        $salle = $this->creerSalle();

        $s = $this->creerSoutenance($etudiant, $session, $jury, [
            'salle' => $salle,
            'statut' => 'confirmee',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/soutenances/{$s->id}/convocations/generer")
            ->assertStatus(201);

        $convocation = \App\Models\Convocation::where('soutenance_id', $s->id)
            ->where('type_destinataire', 'etudiant')
            ->firstOrFail();

        $chemin = $convocation->fichier_path;
        Storage::disk('local')->assertExists($chemin);

        // Simulation du scénario réel : le dossier storage a été vidé
        // alors que la base conserve l'enregistrement de la convocation.
        Storage::disk('local')->delete($chemin);
        Storage::disk('local')->assertMissing($chemin);

        // Le destinataire télécharge sa convocation : le fichier est
        // régénéré automatiquement et le téléchargement aboutit.
        $reponse = $this->actingAs($userEtudiant, 'sanctum')
            ->getJson("/api/convocations/{$convocation->id}/telecharger")
            ->assertOk();

        $this->assertEquals('application/pdf', $reponse->headers->get('Content-Type'));

        // Le fichier a été recréé au même emplacement, avec un contenu
        // PDF valide, sans doublon.
        Storage::disk('local')->assertExists($chemin);
        $this->assertStringStartsWith('%PDF', substr(Storage::disk('local')->get($chemin), 0, 4));
    }

    public function test_pv_fichier_manquant_regenere_au_telechargement(): void
    {
        Storage::fake('local');

        $admin = $this->creerAdmin();
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

        $resultat->update(['valide_le' => now(), 'valide_par' => $membres[0]->id]);

        $userPresident = $membres[0]->user;

        $reponse = $this->actingAs($userPresident, 'sanctum')
            ->postJson("/api/resultats/{$resultat->id}/proces-verbal", ['type_master' => 'recherche'])
            ->assertStatus(201);

        $pv = ProcesVerbal::findOrFail($reponse->json('proces_verbal.id'));
        $chemin = $pv->fichier_path;
        Storage::disk('local')->assertExists($chemin);

        // Simulation : fichier disparu du stockage, enregistrement intact.
        Storage::disk('local')->delete($chemin);
        Storage::disk('local')->assertMissing($chemin);

        // L'administrateur télécharge le PV : régénération automatique.
        $reponse = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/proces-verbaux/{$pv->id}/telecharger")
            ->assertOk();

        $this->assertEquals('application/pdf', $reponse->headers->get('Content-Type'));

        Storage::disk('local')->assertExists($chemin);
        $this->assertStringStartsWith('%PDF', substr(Storage::disk('local')->get($chemin), 0, 4));

        // L'étudiant accède aussi à son PV régénéré (route /mes-pv).
        $this->actingAs($userEtudiant, 'sanctum')
            ->getJson("/api/mes-pv/{$pv->id}/telecharger")
            ->assertOk();

        $this->assertEquals(1, ProcesVerbal::where('resultat_id', $resultat->id)->count());
    }
}
