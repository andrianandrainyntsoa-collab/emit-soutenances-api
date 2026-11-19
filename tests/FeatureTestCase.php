<?php

namespace Tests;

use App\Models\Enseignant;
use App\Models\Etudiant;
use App\Models\Role;
use App\Models\Soutenance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Cas de base des tests fonctionnels : helper de création de
 * comptes et de rôles (base SQLite en mémoire, refresh à chaque
 * test — les données réelles ne sont jamais touchées).
 */
abstract class FeatureTestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function seedRoles(): array
    {
        return [
            'administrateur' => Role::firstOrCreate(['name' => 'administrateur']),
            'responsable' => Role::firstOrCreate(['name' => 'responsable']),
            'enseignant' => Role::firstOrCreate(['name' => 'enseignant']),
            'etudiant' => Role::firstOrCreate(['name' => 'etudiant']),
        ];
    }

    protected function creerUser(string $roleName, array $attrs = []): User
    {
        $roles = $this->seedRoles();

        return User::create([
            'name' => $attrs['name'] ?? 'User ' . uniqid(),
            'email' => $attrs['email'] ?? (str_replace('_', '', $roleName) . uniqid() . '@test.mg'),
            'password' => bcrypt($attrs['password'] ?? 'password'),
            'role_id' => $roles[$roleName]->id,
            'est_autorise' => $attrs['est_autorise'] ?? true,
        ]);
    }

    protected function creerAdmin(): User
    {
        return $this->creerUser('administrateur', ['name' => 'Admin Test']);
    }

    protected function creerEnseignant(bool $autorise = true, array $attrs = []): array
    {
        $user = $this->creerUser('enseignant', [
            'name' => $attrs['name'] ?? 'Enseignant Test',
            'email' => $attrs['email'] ?? ('ens' . uniqid() . '@test.mg'),
            'password' => $attrs['password'] ?? 'password',
            'est_autorise' => $autorise,
        ]);

        $enseignant = Enseignant::create([
            'user_id' => $user->id,
            'specialite' => $attrs['specialite'] ?? 'Informatique',
            'telephone' => $attrs['telephone'] ?? null,
        ]);

        return [$user, $enseignant];
    }

    protected function creerEtudiant(bool $autoriseSoutenance = true, bool $autorise = true, array $attrs = []): array
    {
        [$user, $etudiant] = $this->creerEtudiantSansPromo($autoriseSoutenance, $autorise, $attrs);

        return [$user, $etudiant];
    }

    protected function creerEtudiantSansPromo(bool $autoriseSoutenance = true, bool $autorise = true, array $attrs = []): array
    {
        $formation = \App\Models\Formation::create(['code' => 'F' . uniqid(), 'nom' => 'Formation Test', 'active' => true]);
        $parcours = \App\Models\Parcours::create(['formation_id' => $formation->id, 'code' => 'P' . uniqid(), 'nom' => 'Parcours Test', 'active' => true]);
        $promotion = \App\Models\Promotion::create([
            'formation_id' => $formation->id,
            'parcours_id' => $parcours->id,
            'nom' => 'Promo ' . uniqid(),
            'annee_universitaire' => '2025-2026',
        ]);

        $user = $this->creerUser('etudiant', [
            'name' => $attrs['name'] ?? 'Etudiant Test',
            'email' => $attrs['email'] ?? ('etu' . uniqid() . '@test.mg'),
            'password' => $attrs['password'] ?? 'password',
            'est_autorise' => $autorise,
        ]);

        $etudiant = Etudiant::create([
            'user_id' => $user->id,
            'promotion_id' => $promotion->id,
            'numero_etudiant' => $attrs['numero_etudiant'] ?? (rand(100, 999) . 'TM' . rand(20, 29)),
            'telephone' => $attrs['telephone'] ?? null,
            'autorise_soutenance' => $autoriseSoutenance,
        ]);

        return [$user, $etudiant];
    }

    protected function creerSessionOuverte(): \App\Models\SessionSoutenance
    {
        return \App\Models\SessionSoutenance::create([
            'nom' => 'Session Test ' . uniqid(),
            'date_debut' => now()->toDateString(),
            'date_fin' => now()->addDays(30)->toDateString(),
            'annee_universitaire' => '2025-2026',
            'statut' => 'ouverte',
        ]);
    }

    protected function creerSalle(): \App\Models\Salle
    {
        return \App\Models\Salle::create([
            'code' => 'A0' . rand(10, 99),
            'nom' => 'Salle Test',
            'capacite' => 30,
            'disponible' => true,
        ]);
    }

    protected function creerJuryComplet(\App\Models\SessionSoutenance $session): array
    {
        $jury = \App\Models\Jury::create([
            'session_id' => $session->id,
            'code' => 'JURY-' . uniqid(),
            'nom' => 'Groupe Test ' . uniqid(),
        ]);

        $membres = [];

        foreach (['president', 'rapporteur', 'examinateur'] as $fonction) {
            [$user, $enseignant] = $this->creerEnseignant();
            \App\Models\MembreJury::create([
                'jury_id' => $jury->id,
                'enseignant_id' => $enseignant->id,
                'fonction' => $fonction,
            ]);
            $membres[] = $enseignant;
        }

        return [$jury, $membres];
    }

    protected function creerSoutenance(Etudiant|array $etudiant, \App\Models\SessionSoutenance $session, \App\Models\Jury $jury, array $attrs = []): Soutenance
    {
        $etudiantId = is_array($etudiant) ? $etudiant[1]->id : $etudiant->id;
        $salle = $attrs['salle'] ?? $this->creerSalle();

        return Soutenance::create([
            'etudiant_id' => $etudiantId,
            'session_id' => $session->id,
            'jury_id' => $jury->id,
            'salle_id' => $salle->id,
            'date_soutenance' => $attrs['date'] ?? now()->toDateString(),
            'heure_debut' => $attrs['debut'] ?? '08:00',
            'heure_fin' => $attrs['fin'] ?? '10:00',
            'titre_memoire' => $attrs['titre'] ?? 'Mémoire de test',
            'statut' => $attrs['statut'] ?? 'planifiee',
        ]);
    }
}
