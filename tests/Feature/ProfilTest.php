<?php

namespace Tests\Feature;

use Tests\FeatureTestCase;

/**
 * Tests de la page « Mon Profil » (web + mobile) : champs modifiables
 * par rôle, validation du mot de passe actuel, isolation des comptes.
 */
class ProfilTest extends FeatureTestCase
{
    public function test_enseignant_modifie_nom_specialite_telephone_et_mot_de_passe(): void
    {
        [$user] = $this->creerEnseignant(attrs: ['password' => 'ancien1234']);

        $reponse = $this->actingAs($user, 'sanctum')
            ->putJson('/api/profil', [
                'name' => 'Rakoto Nouveau Nom',
                'specialite' => 'Réseaux & Sécurité',
                'telephone' => '0321112222',
            ])
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Rakoto Nouveau Nom']);
        $this->assertDatabaseHas('enseignants', ['user_id' => $user->id, 'specialite' => 'Réseaux & Sécurité', 'telephone' => '0321112222']);

        // Changement de mot de passe sans le mot de passe actuel : refusé.
        $this->actingAs($user, 'sanctum')
            ->putJson('/api/profil', [
                'password' => 'nouveaumdp1',
                'password_confirmation' => 'nouveaumdp1',
            ])
            ->assertStatus(422);

        // Avec mauvais mot de passe actuel : refusé.
        $this->actingAs($user, 'sanctum')
            ->putJson('/api/profil', [
                'mot_de_passe_actuel' => 'mauvais',
                'password' => 'nouveaumdp1',
                'password_confirmation' => 'nouveaumdp1',
            ])
            ->assertStatus(422);

        // Avec le bon mot de passe actuel : accepté.
        $this->actingAs($user, 'sanctum')
            ->putJson('/api/profil', [
                'mot_de_passe_actuel' => 'ancien1234',
                'password' => 'nouveaumdp1',
                'password_confirmation' => 'nouveaumdp1',
            ])
            ->assertOk();

        // L'ancien mot de passe ne fonctionne plus, le nouveau oui.
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'ancien1234'])->assertStatus(422);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'nouveaumdp1'])->assertOk();
    }

    public function test_etudiant_ne_modifie_que_son_mot_de_passe(): void
    {
        [$user, $etudiant] = $this->creerEtudiant(attrs: ['password' => 'ancien1234']);

        // Tentative de changement de nom : ignorée (champ non admis).
        $this->actingAs($user, 'sanctum')
            ->putJson('/api/profil', [
                'name' => 'Nom Piraté',
                'telephone' => '000000',
                'password' => 'nouveaumdp1',
                'password_confirmation' => 'nouveaumdp1',
            ])
            ->assertStatus(422); // 'name' et 'telephone' ne sont plus des champs valides → validation échoue

        // Tentative sans mot de passe actuel : refusée.
        $this->actingAs($user, 'sanctum')
            ->putJson('/api/profil', [
                'password' => 'nouveaumdp1',
                'password_confirmation' => 'nouveaumdp1',
            ])
            ->assertStatus(422);

        // Le nom et le matricule sont inchangés.
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => $user->name]);

        // Changement de mot de passe avec validation : OK.
        $this->actingAs($user, 'sanctum')
            ->putJson('/api/profil', [
                'mot_de_passe_actuel' => 'ancien1234',
                'password' => 'nouveaumdp1',
                'password_confirmation' => 'nouveaumdp1',
            ])
            ->assertOk();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'nouveaumdp1'])->assertOk();
    }

    public function test_un_utilisateur_ne_peut_pas_modifier_le_profil_d_un_autre(): void
    {
        [$enseignantA] = $this->creerEnseignant();
        [$enseignantB] = $this->creerEnseignant();

        // L'appel PUT /profil ne touche QUE l'utilisateur connecté :
        // enseignantA modifie son propre profil, jamais celui de B.
        $this->actingAs($enseignantA, 'sanctum')
            ->putJson('/api/profil', ['name' => 'A Modifié'])
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $enseignantA->id, 'name' => 'A Modifié']);
        $this->assertDatabaseHas('users', ['id' => $enseignantB->id, 'name' => $enseignantB->name]);
    }

    public function test_nettoyage_historique_enseignant_masque_sans_supprimer(): void
    {
        [$user, $etudiant] = $this->creerEtudiant();
        [$enseignantUser, $enseignant] = $this->creerEnseignant();

        $session = $this->creerSessionOuverte();
        [$jury] = $this->creerJuryComplet($session);

        // L'enseignant testé est membre du jury (examinateur).
        \App\Models\MembreJury::create([
            'jury_id' => $jury->id,
            'enseignant_id' => $enseignant->id,
            'fonction' => 'examinateur',
        ]);

        $this->creerSoutenance($etudiant, $session, $jury, ['statut' => 'terminee']);

        // Avant : la soutenance apparaît dans l'espace enseignant.
        $this->actingAs($enseignantUser, 'sanctum')
            ->getJson('/api/mon-espace-enseignant')
            ->assertOk()
            ->assertJsonCount(1, 'soutenances');

        // Nettoyage de l'historique personnel.
        $this->actingAs($enseignantUser, 'sanctum')
            ->postJson('/api/mon-historique/nettoyer')
            ->assertOk();

        // Après : la soutenance est masquée de l'historique personnel.
        $this->actingAs($enseignantUser, 'sanctum')
            ->getJson('/api/mon-espace-enseignant')
            ->assertOk()
            ->assertJsonCount(0, 'soutenances');

        // Les données officielles ne sont PAS supprimées.
        $this->assertDatabaseCount('soutenances', 1);
    }
}
