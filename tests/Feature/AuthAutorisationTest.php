<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Tests\FeatureTestCase;

/**
 * Tests de l'inscription autonome, de l'autorisation des comptes et
 * des règles d'administration des utilisateurs.
 */
class AuthAutorisationTest extends FeatureTestCase
{
    public function test_inscription_enseignant_sans_matricule_ni_grade(): void
    {
        Mail::fake();

        $reponse = $this->postJson('/api/register/enseignant', [
            'name' => 'Rakoto Jean',
            'email' => 'rakoto.jean@emit.mg',
            'password' => 'motdepasse1',
            'password_confirmation' => 'motdepasse1',
            'telephone' => '0340000000',
        ]);

        $reponse->assertStatus(201);

        $user = \App\Models\User::where('email', 'rakoto.jean@emit.mg')->first();

        $this->assertNotNull($user);
        $this->assertFalse((bool) $user->est_autorise);
        $this->assertNotNull($user->enseignant);
        $this->assertNull($user->enseignant->matricule);
        $this->assertNull($user->enseignant->grade);
    }

    public function test_inscription_enseignant_refuse_un_email_deja_utilise_avec_message_clair(): void
    {
        $this->creerUser('enseignant', ['email' => 'existant@emit.mg']);

        $this->postJson('/api/register/enseignant', [
            'name' => 'Nouvel enseignant',
            'email' => 'existant@emit.mg',
            'password' => 'motdepasse1',
            'password_confirmation' => 'motdepasse1',
        ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.email.0',
                'Cette adresse e-mail est déjà utilisée. Utilisez une autre adresse ou connectez-vous.'
            );
    }

    public function test_connexion_refusee_avant_autorisation(): void
    {
        $this->creerEnseignant(autorise: false, attrs: ['email' => 'attente@emit.mg']);

        $reponse = $this->postJson('/api/login', [
            'email' => 'attente@emit.mg',
            'password' => 'password',
        ]);

        $reponse->assertStatus(403);
        $reponse->assertJsonPath('compte_en_attente', true);
    }

    public function test_acces_protege_refuse_pour_compte_non_autorise(): void
    {
        $user = $this->creerUser('enseignant', ['est_autorise' => false]);

        // Même avec un jeton valide, toute requête authentifiée est
        // refusée tant que le compte n'est pas autorisé.
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/profil')
            ->assertStatus(403)
            ->assertJsonPath('compte_en_attente', true);

        $reponse = $this->actingAs($user, 'sanctum')->getJson('/api/soutenances');

        $reponse->assertStatus(403);
        $reponse->assertJsonPath('compte_en_attente', true);
    }

    public function test_jeton_sanctum_expire_est_refuse(): void
    {
        config(['sanctum.expiration' => 10080]);

        $user = $this->creerAdmin();
        $token = $user->createToken('test');
        $token->accessToken->forceFill([
            'created_at' => now()->subMinutes(10081),
            'updated_at' => now()->subMinutes(10081),
        ])->save();

        $this->withToken($token->plainTextToken)
            ->getJson('/api/user')
            ->assertUnauthorized();
    }

    public function test_autorisation_par_admin_puis_connexion_reussie(): void
    {
        Mail::fake();

        $admin = $this->creerAdmin();

        $this->postJson('/api/register/enseignant', [
            'name' => 'Rabe Marie',
            'email' => 'rabe.marie@emit.mg',
            'password' => 'motdepasse1',
            'password_confirmation' => 'motdepasse1',
        ])->assertStatus(201);

        $user = \App\Models\User::where('email', 'rabe.marie@emit.mg')->first();

        // L'administrateur autorise le compte (web ou mobile — même compte).
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/users/{$user->id}/autoriser", ['est_autorise' => true])
            ->assertOk()
            ->assertJsonPath('user.est_autorise', true);

        // La connexion fonctionne immédiatement après autorisation.
        $this->postJson('/api/login', [
            'email' => 'rabe.marie@emit.mg',
            'password' => 'motdepasse1',
        ])->assertOk()
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_la_creation_d_utilisateur_produit_un_compte_administrateur(): void
    {
        $admin = $this->creerAdmin();

        $reponse = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/users', [
                'name' => 'Nouvel Admin',
                'email' => 'admin2@test.mg',
                'password' => 'motdepasse1',
            ]);

        $reponse->assertStatus(201);

        $this->assertEquals(
            'administrateur',
            \App\Models\User::where('email', 'admin2@test.mg')->first()->role->name
        );
    }

    public function test_creation_admin_refuse_email_deja_utilise_avec_message_clair(): void
    {
        $admin = $this->creerAdmin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/users', [
                'name' => 'Autre Admin',
                'email' => $admin->email,
                'password' => 'motdepasse1',
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.email.0',
                'Cette adresse e-mail est déjà utilisée. Choisissez une adresse différente.'
            );
    }

    public function test_admin_ne_peut_pas_modifier_un_enseignant_ni_un_etudiant(): void
    {
        $admin = $this->creerAdmin();
        [$userEnseignant] = $this->creerEnseignant();
        [$userEtudiant] = $this->creerEtudiant();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/users/{$userEnseignant->id}", ['name' => 'Nom Modifié'])
            ->assertStatus(403);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/users/{$userEtudiant->id}", ['name' => 'Nom Modifié'])
            ->assertStatus(403);
    }

    public function test_liste_des_utilisateurs_filtree_par_role(): void
    {
        $admin = $this->creerAdmin();
        $this->creerEnseignant();
        $this->creerEtudiant();

        $reponse = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/users?role=enseignant')
            ->assertOk();

        $this->assertGreaterThanOrEqual(1, count($reponse->json('data')));
        foreach ($reponse->json('data') as $u) {
            $this->assertEquals('enseignant', $u['role']['name']);
        }
    }

    public function test_enseignants_liste_expose_le_statut_de_compte(): void
    {
        $admin = $this->creerAdmin();
        $this->creerEnseignant(autorise: false);

        $reponse = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/enseignants')
            ->assertOk();

        $premier = $reponse->json('data.0');
        $this->assertArrayHasKey('est_autorise', $premier);
        $this->assertFalse((bool) $premier['est_autorise']);
    }
}
