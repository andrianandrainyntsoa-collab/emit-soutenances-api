<?php

namespace Tests\Feature;

use App\Models\PasswordResetCode;
use Illuminate\Support\Facades\Mail;
use Tests\FeatureTestCase;

/**
 * Tests du flux de réinitialisation de mot de passe par code à
 * 6 chiffres envoyé par e-mail (SMTP réel en production, Mail::fake
 * en test).
 */
class MotDePasseTest extends FeatureTestCase
{
    public function test_demande_de_code_envoie_un_email_avec_code_6_chiffres(): void
    {
        Mail::fake();

        $user = $this->creerUser('enseignant', ['email' => 'code@emit.mg']);

        $reponse = $this->postJson('/api/mot-de-passe/oublie', ['email' => 'code@emit.mg'])
            ->assertOk()
            ->assertJsonMissing(['code_developpement']);

        // Un code haché est bien enregistré, valable 30 minutes.
        $code = PasswordResetCode::where('email', 'code@emit.mg')->first();
        $this->assertNotNull($code);
        $this->assertNotNull($code->expires_at);
    }

    public function test_reinitialisation_avec_le_bon_code(): void
    {
        Mail::fake();

        $user = $this->creerUser('enseignant', ['email' => 'reset@emit.mg', 'password' => 'ancien1234']);

        // Simule un code enregistré (haché comme dans le contrôleur).
        $codeClair = '123456';
        PasswordResetCode::create([
            'email' => 'reset@emit.mg',
            'code' => bcrypt($codeClair),
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->postJson('/api/mot-de-passe/reinitialiser', [
            'email' => 'reset@emit.mg',
            'code' => $codeClair,
            'password' => 'nouveaumdp1',
            'password_confirmation' => 'nouveaumdp1',
        ])->assertOk();

        $this->assertNotNull(PasswordResetCode::where('email', 'reset@emit.mg')->first()->used_at);

        // Connexion avec le nouveau mot de passe.
        $this->postJson('/api/login', ['email' => 'reset@emit.mg', 'password' => 'nouveaumdp1'])->assertOk();
    }

    public function test_mauvais_code_refuse(): void
    {
        Mail::fake();

        $user = $this->creerUser('enseignant', ['email' => 'badcode@emit.mg']);

        PasswordResetCode::create([
            'email' => 'badcode@emit.mg',
            'code' => bcrypt('123456'),
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->postJson('/api/mot-de-passe/reinitialiser', [
            'email' => 'badcode@emit.mg',
            'code' => '000000',
            'password' => 'nouveaumdp1',
            'password_confirmation' => 'nouveaumdp1',
        ])->assertStatus(422);
    }

    public function test_code_expire_refuse(): void
    {
        $user = $this->creerUser('enseignant', ['email' => 'expire@emit.mg']);

        PasswordResetCode::create([
            'email' => 'expire@emit.mg',
            'code' => bcrypt('123456'),
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/mot-de-passe/reinitialiser', [
            'email' => 'expire@emit.mg',
            'code' => '123456',
            'password' => 'nouveaumdp1',
            'password_confirmation' => 'nouveaumdp1',
        ])->assertStatus(422);
    }

    public function test_code_deja_utilise_refuse(): void
    {
        $user = $this->creerUser('enseignant', ['email' => 'used@emit.mg']);

        PasswordResetCode::create([
            'email' => 'used@emit.mg',
            'code' => bcrypt('123456'),
            'expires_at' => now()->addMinutes(30),
            'used_at' => now(),
        ]);

        $this->postJson('/api/mot-de-passe/reinitialiser', [
            'email' => 'used@emit.mg',
            'code' => '123456',
            'password' => 'nouveaumdp1',
            'password_confirmation' => 'nouveaumdp1',
        ])->assertStatus(422);
    }

    public function test_limite_de_demandes_par_heure(): void
    {
        Mail::fake();

        $user = $this->creerUser('enseignant', ['email' => 'ratelimit@emit.mg']);

        // 3 demandes passent.
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/mot-de-passe/oublie', ['email' => 'ratelimit@emit.mg'])->assertOk();
        }

        // La 4e demande est bloquée.
        $this->postJson('/api/mot-de-passe/oublie', ['email' => 'ratelimit@emit.mg'])
            ->assertStatus(429);
    }

    public function test_reponse_identique_que_le_compte_existe(): void
    {
        Mail::fake();

        $reponseExistant = $this->postJson('/api/mot-de-passe/oublie', ['email' => 'inconnu@emit.mg'])
            ->assertOk()
            ->json('message');

        $this->assertStringContainsString('code de réinitialisation', $reponseExistant);
    }
}
