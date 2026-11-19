<?php

namespace Database\Seeders;

use App\Models\Creneau;
use App\Models\Deliberation;
use App\Models\DisponibiliteJury;
use App\Models\DisponibiliteSalle;
use App\Models\Evaluation;
use App\Models\Jury;
use App\Models\MembreJury;
use App\Models\ProcesVerbal;
use App\Models\Resultat;
use App\Models\Role;
use App\Models\Salle;
use App\Models\SessionSoutenance;
use App\Models\Soutenance;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * Jeu de données de démonstration.
 *
 * Il couvre l'ensemble du processus décrit dans le livre :
 *   - Administration → Planification → Convocation → Soutenance →
 *     Évaluation → Délibération → Résultat final → Procès-verbal →
 *     Archivage.
 *
 * Comptes créés (mot de passe : défini par SEEDER_PASSWORD ou 'password' par défaut) :
 *   - admin@emit.mg        (administrateur)
 *   - responsable@emit.mg  (responsable)
 *   - 6 enseignants        (role enseignant, membres des jurys)
 *   - 6 étudiants          (role etudiant)
 *
 * NOTE : Ce seeder ne s'exécute pas en environnement production.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Sécurité : ne pas exécuter en production
        if (app()->environment('production')) {
            return;
        }

        // ------------------------------------------------------------------
        // Rôles
        // ------------------------------------------------------------------
        $roleAdmin = Role::firstOrCreate(['name' => 'administrateur'], ['description' => 'Administration et supervision du système']);
        $roleResponsable = Role::firstOrCreate(['name' => 'responsable'], ['description' => 'Suivi et consultation des soutenances']);
        $roleEnseignant = Role::firstOrCreate(['name' => 'enseignant'], ['description' => 'Membre du jury : évaluation, délibération']);
        $roleEtudiant = Role::firstOrCreate(['name' => 'etudiant'], ['description' => 'Candidat autorisé à soutenir']);

        $motDePasse = Hash::make(env('SEEDER_PASSWORD', 'password'));

        // ------------------------------------------------------------------
        // Utilisateurs principaux
        // ------------------------------------------------------------------
        $admin = \App\Models\User::firstOrCreate(
            ['email' => 'admin@emit.mg'],
            ['name' => 'Administrateur EMIT', 'password' => $motDePasse, 'role_id' => $roleAdmin->id, 'est_autorise' => true]
        );

        $responsable = \App\Models\User::firstOrCreate(
            ['email' => 'responsable@emit.mg'],
            ['name' => 'Responsable Scolarité', 'password' => $motDePasse, 'role_id' => $roleResponsable->id, 'est_autorise' => true]
        );

        // ------------------------------------------------------------------
        // Mentions (formations) de l'EMIT — trois formations uniquement :
        // ICM, INFO, MGT. Parcours réels rattachés à chaque mention.
        // ------------------------------------------------------------------
        $formationIcm = \App\Models\Formation::firstOrCreate(
            ['code' => 'ICM'],
            ['nom' => 'Information, Communication et Multimédia', 'description' => 'Mention ICM — EMIT', 'active' => true]
        );

        $formationInfo = \App\Models\Formation::firstOrCreate(
            ['code' => 'INFO'],
            ['nom' => 'Informatique', 'description' => 'Mention INFO — EMIT', 'active' => true]
        );

        $formationMgt = \App\Models\Formation::firstOrCreate(
            ['code' => 'MGT'],
            ['nom' => 'Management', 'description' => 'Mention MGT — EMIT', 'active' => true]
        );

        $parcoursCm = \App\Models\Parcours::firstOrCreate(
            ['formation_id' => $formationIcm->id, 'code' => 'CM'],
            ['nom' => 'Communication Multimédia', 'active' => true]
        );

        $parcoursSdia = \App\Models\Parcours::firstOrCreate(
            ['formation_id' => $formationInfo->id, 'code' => 'SDIA'],
            ['nom' => 'Science des Données et Intelligence Artificielle', 'active' => true]
        );

        $parcoursM2i = \App\Models\Parcours::firstOrCreate(
            ['formation_id' => $formationInfo->id, 'code' => 'M2I'],
            ['nom' => 'Modélisation et Ingénierie Informatique', 'active' => true]
        );

        $parcoursMd = \App\Models\Parcours::firstOrCreate(
            ['formation_id' => $formationMgt->id, 'code' => 'MD'],
            ['nom' => 'Management Décisionnel', 'active' => true]
        );

        // Nettoyage de l'ancien référentiel (Master Informatique — DA2I,
        // GOL) : supprimé uniquement si aucune promotion peuplée ne le
        // référence encore.
        foreach (\App\Models\Parcours::whereIn('code', ['DA2I', 'GOL'])->get() as $ancienParcours) {
            $ancienParcours->promotions()->whereDoesntHave('etudiants')->delete();

            if (!$ancienParcours->promotions()->exists()) {
                $ancienParcours->delete();
            }
        }

        \App\Models\Formation::where('code', 'MI')
            ->whereDoesntHave('promotions')
            ->whereDoesntHave('parcours')
            ->delete();

        // ------------------------------------------------------------------
        // Promotions de démonstration (Master 2 — 2025-2026)
        // ------------------------------------------------------------------
        $promotionSdia = \App\Models\Promotion::firstOrCreate(
            ['parcours_id' => $parcoursSdia->id, 'nom' => 'Master 2 SDIA', 'annee_universitaire' => '2025-2026'],
            ['formation_id' => $formationInfo->id]
        );

        $promotionM2i = \App\Models\Promotion::firstOrCreate(
            ['parcours_id' => $parcoursM2i->id, 'nom' => 'Master 2 M2I', 'annee_universitaire' => '2025-2026'],
            ['formation_id' => $formationInfo->id]
        );

        $promotionMd = \App\Models\Promotion::firstOrCreate(
            ['parcours_id' => $parcoursMd->id, 'nom' => 'Master 2 MD', 'annee_universitaire' => '2025-2026'],
            ['formation_id' => $formationMgt->id]
        );

        // ------------------------------------------------------------------
        // Enseignants (6) : suffisants pour composer deux jurys complets
        // ------------------------------------------------------------------
        $donneesEnseignants = [
            ['RAKOTO Jean Marc', 'jean-marc.rakoto@emit.mg', 'ENS001', 'Professeur Titulaire', 'Génie logiciel'],
            ['RABEMANANJARA Hanta', 'hanta.rabemananjara@emit.mg', 'ENS002', 'Maître de Conférences', 'Bases de données'],
            ['RANDRIAMBOLOLONA Soa', 'soa.randriambololona@emit.mg', 'ENS003', 'Maître Assistant', 'Réseaux et sécurité'],
            ['RAVELOSON Tovo', 'tovo.raveloson@emit.mg', 'ENS004', 'Maître Assistant', 'Intelligence artificielle'],
            ['RASOLOFOMANANA Nirina', 'nirina.rasolofomanana@emit.mg', 'ENS005', 'Doctorant-Enseignant', 'Développement web'],
            ['RANAIVOSON Fenosoa', 'fenosoa.ranaivoson@emit.mg', 'ENS006', 'Maître de Conférences', 'Systèmes d\'information'],
        ];

        $enseignants = [];

        foreach ($donneesEnseignants as [$nom, $email, $matricule, $grade, $specialite]) {
            $user = \App\Models\User::firstOrCreate(
                ['email' => $email],
                ['name' => $nom, 'password' => $motDePasse, 'role_id' => $roleEnseignant->id, 'est_autorise' => true]
            );

            $enseignants[] = \App\Models\Enseignant::firstOrCreate(
                ['user_id' => $user->id],
                ['matricule' => $matricule, 'grade' => $grade, 'specialite' => $specialite, 'telephone' => null]
            );
        }

        // ------------------------------------------------------------------
        // Étudiants (6), tous autorisés à soutenir (RG01) — numéro
        // étudiant au format numero-formation-annee (ex : 312SDIA24).
        // ------------------------------------------------------------------
        $donneesEtudiants = [
            ['RASOANAIVO Ando', 'ando.rasoanaivo@univ-fianarantsoa.mg', '312SDIA24', 'Plateforme de gestion des soutenances de Master', $promotionSdia->id],
            ['RANDRIANJAFY Toky', 'toky.randrianjafy@univ-fianarantsoa.mg', '312SDIA25', 'Application mobile de suivi académique', $promotionSdia->id],
            ['RAZANAMALALA Fetra', 'fetra.razanamalala@univ-fianarantsoa.mg', '312SDIA26', 'Système de recommandation de cours en ligne', $promotionSdia->id],
            ['ANDRIANARISOA Mamy', 'mamy.andrianarisoa@univ-fianarantsoa.mg', '312M2I24', 'Gestion intelligente des transports urbains', $promotionM2i->id],
            ['RAKOTONDRAZAKA Hery', 'hery.rakondrazaka@univ-fianarantsoa.mg', '312M2I25', 'Détection d\'anomalies dans les réseaux informatiques', $promotionM2i->id],
            ['RABEMANANJARA Lova', 'lova.rabemananjara@univ-fianarantsoa.mg', '312MD24', 'Portail de services numériques universitaires', $promotionMd->id],
        ];

        $etudiants = [];
        $titresMemoires = [];

        foreach ($donneesEtudiants as [$nom, $email, $numero, $memoire, $promotionId]) {
            $user = \App\Models\User::firstOrCreate(
                ['email' => $email],
                ['name' => $nom, 'password' => $motDePasse, 'role_id' => $roleEtudiant->id, 'est_autorise' => true]
            );

            $etudiants[] = \App\Models\Etudiant::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'promotion_id' => $promotionId,
                    'numero_etudiant' => $numero,
                    'telephone' => null,
                    'autorise_soutenance' => true,
                ]
            );

            $titresMemoires[$numero] = $memoire;
        }

        foreach ($etudiants as $etudiant) {
            $etudiant->titre_memoire_demo = $titresMemoires[$etudiant->numero_etudiant];
        }

        // ------------------------------------------------------------------
        // Session de soutenance
        // ------------------------------------------------------------------
        $session = SessionSoutenance::firstOrCreate(
            ['nom' => 'Session de soutenance Octobre 2026'],
            [
                'date_debut' => '2026-11-02',
                'date_fin' => '2026-11-13',
                'annee_universitaire' => '2025-2026',
                'statut' => 'ouverte',
                'description' => 'Sessions de soutenance des Masters de l\'EMIT — Novembre 2026',
            ]
        );

        // ------------------------------------------------------------------
        // Salles et disponibilités
        // ------------------------------------------------------------------
        $salleA101 = Salle::firstOrCreate(
            ['code' => 'A101'],
            ['nom' => 'Salle de soutenance A101', 'capacite' => 40, 'batiment' => 'Bloc A', 'etage' => 1, 'localisation' => 'Bloc A — 1er étage']
        );

        $salleA102 = Salle::firstOrCreate(
            ['code' => 'A102'],
            ['nom' => 'Salle A102', 'capacite' => 30, 'batiment' => 'Bloc A', 'etage' => 1, 'localisation' => 'Bloc A — 1er étage']
        );

        $salleB201 = Salle::firstOrCreate(
            ['code' => 'B201'],
            ['nom' => 'Salle de réunion B201', 'capacite' => 25, 'batiment' => 'Bloc B', 'etage' => 2, 'localisation' => 'Bloc B — 2e étage']
        );

        $datesSession = ['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06'];

        foreach ([$salleA101, $salleA102, $salleB201] as $salle) {
            foreach ($datesSession as $date) {
                foreach ([['08:00:00', '12:00:00'], ['13:00:00', '17:00:00']] as [$debut, $fin]) {
                    DisponibiliteSalle::firstOrCreate([
                        'salle_id' => $salle->id,
                        'date_disponible' => $date,
                        'heure_debut' => $debut,
                        'heure_fin' => $fin,
                    ]);
                }
            }
        }

        // ------------------------------------------------------------------
        // Disponibilités des enseignants (toutes les demi-journées de la session)
        // ------------------------------------------------------------------
        foreach ($enseignants as $enseignant) {
            foreach ($datesSession as $date) {
                foreach ([['08:00:00', '12:00:00'], ['13:00:00', '17:00:00']] as [$debut, $fin]) {
                    DisponibiliteJury::firstOrCreate([
                        'enseignant_id' => $enseignant->id,
                        'date_disponible' => $date,
                        'heure_debut' => $debut,
                        'heure_fin' => $fin,
                    ]);
                }
            }
        }

        // ------------------------------------------------------------------
        // Créneaux
        // ------------------------------------------------------------------
        $creneaux = [];

        foreach ($datesSession as $date) {
            foreach ([['08:00:00', '10:00:00'], ['10:00:00', '12:00:00'], ['13:00:00', '15:00:00'], ['15:00:00', '17:00:00']] as [$debut, $fin]) {
                $creneaux[] = Creneau::firstOrCreate([
                    'date_soutenance' => $date,
                    'heure_debut' => $debut,
                    'heure_fin' => $fin,
                    'statut' => 'disponible',
                ]);
            }
        }

        // ------------------------------------------------------------------
        // Jurys (2) composés conformément à RG03 :
        // un Président, un Rapporteur, un Examinateur.
        // ------------------------------------------------------------------
        $jury1 = Jury::firstOrCreate(
            ['code' => 'JURY-01'],
            ['session_id' => $session->id, 'nom' => 'Jury 1 — Soutenances Master']
        );

        $jury2 = Jury::firstOrCreate(
            ['code' => 'JURY-02'],
            ['session_id' => $session->id, 'nom' => 'Jury 2 — Soutenances Master']
        );

        foreach ([
            [$jury1, $enseignants[0], 'president'],
            [$jury1, $enseignants[1], 'rapporteur'],
            [$jury1, $enseignants[2], 'examinateur'],
            [$jury2, $enseignants[3], 'president'],
            [$jury2, $enseignants[4], 'rapporteur'],
            [$jury2, $enseignants[5], 'examinateur'],
        ] as [$jury, $enseignant, $fonction]) {
            MembreJury::firstOrCreate([
                'jury_id' => $jury->id,
                'enseignant_id' => $enseignant->id,
            ], ['fonction' => $fonction]);
        }

        // ------------------------------------------------------------------
        // Soutenances couvrant les différents états du processus
        // ------------------------------------------------------------------
        $this->soutenanceComplete($etudiants[0], $session, $jury1, $salleA101, $creneaux[0], $enseignants[0]);
        $this->soutenanceTermineeEvaluee($etudiants[1], $session, $jury1, $salleA101, $creneaux[1], $enseignants[1]);
        $this->soutenanceComplete($etudiants[2], $session, $jury2, $salleA102, $creneaux[2], $enseignants[3]);
        $this->soutenanceSimple($etudiants[3], $session, 'a_planifier');
        $this->soutenanceSimple($etudiants[4], $session, 'a_planifier');

        $this->command?->info('Seeder terminé. Comptes de démonstration (mot de passe : password) :');
        $this->command?->line('  - admin@emit.mg (administrateur)');
        $this->command?->line('  - responsable@emit.mg (responsable)');
        $this->command?->line('  - jean-marc.rakoto@emit.mg / hanta... / soa... (enseignants)');
        $this->command?->line('  - ando.rasoanaivo@univ-fianarantsoa.mg / toky... / fetra... (étudiants)');
    }

    /**
     * Soutenance planifiée + confirmée + convocations générées.
     */
    private function soutenanceComplete(
        \App\Models\Etudiant $etudiant,
        SessionSoutenance $session,
        Jury $jury,
        Salle $salle,
        Creneau $creneau,
        \App\Models\Enseignant $president
    ): Soutenance {
        $soutenance = Soutenance::firstOrCreate(
            ['etudiant_id' => $etudiant->id, 'session_id' => $session->id],
            [
                'jury_id' => $jury->id,
                'salle_id' => $salle->id,
                'creneau_id' => $creneau->id,
                'date_soutenance' => $creneau->date_soutenance,
                'heure_debut' => $creneau->heure_debut,
                'heure_fin' => $creneau->heure_fin,
                'titre_memoire' => $etudiant->titre_memoire_demo,
                'statut' => 'confirmee',
            ]
        );

        $creneau->statut = 'planifie';
        $creneau->save();

        return $soutenance;
    }

    /**
     * Soutenance terminée avec évaluations validées, délibération
     * enregistrée, résultat validé et PV généré : démontre la chaîne
     * complète du module Résultats & Procès-verbaux.
     */
    private function soutenanceTermineeEvaluee(
        \App\Models\Etudiant $etudiant,
        SessionSoutenance $session,
        Jury $jury,
        Salle $salle,
        Creneau $creneau,
        \App\Models\Enseignant $president
    ): Soutenance {
        $soutenance = Soutenance::firstOrCreate(
            ['etudiant_id' => $etudiant->id, 'session_id' => $session->id],
            [
                'jury_id' => $jury->id,
                'salle_id' => $salle->id,
                'creneau_id' => $creneau->id,
                'date_soutenance' => $creneau->date_soutenance,
                'heure_debut' => $creneau->heure_debut,
                'heure_fin' => $creneau->heure_fin,
                'titre_memoire' => $etudiant->titre_memoire_demo,
                'statut' => 'terminee',
            ]
        );

        $creneau->statut = 'planifie';
        $creneau->save();

        // Évaluations des trois membres, toutes validées (RG04/RG05).
        $notes = [15.5, 14.0, 16.0];

        $jury->membresJury()->get()->values()->each(function ($membre, $index) use ($soutenance, $notes) {
            $evaluation = Evaluation::firstOrCreate(
                [
                    'soutenance_id' => $soutenance->id,
                    'enseignant_id' => $membre->enseignant_id,
                ],
                [
                    'note' => $notes[$index % count($notes)],
                    'commentaire' => 'Bonne maîtrise du sujet et qualité de la présentation.',
                    'validee' => true,
                    'validee_le' => now(),
                ]
            );
        });

        // Délibération : consultation du jury (RG06) — la note finale
        // étant désormais calculée automatiquement comme moyenne des
        // trois notes validées, la délibération reprend cette moyenne.
        $moyenneFinale = round(array_sum($notes) / count($notes), 2);

        $deliberation = Deliberation::firstOrCreate(
            ['soutenance_id' => $soutenance->id],
            [
                'president_enseignant_id' => $president->id,
                'observations' => 'Le jury retient la moyenne des trois notes validées comme note finale.',
                'note_finale_retenue' => $moyenneFinale,
                'mention' => 'Bien',
                'decision' => 'admis',
                'deliberee_le' => now(),
            ]
        );

        // Résultat final : enregistré automatiquement dès que les trois
        // membres du jury ont validé leur note — la moyenne des trois
        // notes validées constitue la note finale (RG04/RG05).
        $resultat = Resultat::firstOrCreate(
            ['soutenance_id' => $soutenance->id],
            [
                'moyenne_finale' => $moyenneFinale,
                'decision' => 'admis',
                'valide_par' => $president->id,
                'valide_le' => now(),
                'observations' => 'Résultat finalisé automatiquement après validation des trois notes par les membres du jury.',
            ]
        );

        // Procès-verbal généré (RG11/RG12) si absent.
        if (!$resultat->procesVerbal()->exists()) {
            $resultat->load([
                'soutenance.etudiant.user', 'soutenance.etudiant.promotion.formation',
                'soutenance.etudiant.promotion.parcours',
                'soutenance.etudiant.promotion.parcours.formation',
                'soutenance.session',
                'soutenance.creneau', 'soutenance.jury.membresJury.enseignant.user',
                'soutenance.evaluations.enseignant.user',
            ]);

            $numeroPv = sprintf('PV-MR-%s-%06d', now()->format('Y'), $resultat->id);

            $etudiantPv = $resultat->soutenance->etudiant;
            $numeroEtudiant = preg_replace(
                '/[^A-Za-z0-9\-]/',
                '',
                $etudiantPv->numero_etudiant ?? 'sans-numero'
            );
            $slugNom = \Illuminate\Support\Str::slug($etudiantPv->user->name ?? 'etudiant', '-');

            $pdf = Pdf::loadView('pdf.proces-verbal', [
                'resultat' => $resultat,
                'soutenance' => $resultat->soutenance,
                'evaluations' => $resultat->soutenance->evaluations,
                'numeroPv' => $numeroPv,
                'typeMaster' => 'recherche',
                'dateGeneration' => now(),
            ])->setPaper('a4', 'portrait');

            $chemin = 'proces-verbaux/' . $numeroPv . '_' . $numeroEtudiant . '_' . $slugNom . '.pdf';
            Storage::disk('local')->put($chemin, $pdf->output());

            ProcesVerbal::firstOrCreate(
                ['resultat_id' => $resultat->id, 'type_master' => 'recherche'],
                [
                    'numero_pv' => $numeroPv,
                    'fichier_path' => $chemin,
                    'genere_par' => $president->id,
                    'genere_le' => now(),
                    'statut' => 'genere',
                ]
            );
        }

        return $soutenance;
    }

    /**
     * Soutenance en attente de planification.
     */
    private function soutenanceSimple(\App\Models\Etudiant $etudiant, SessionSoutenance $session, string $statut): Soutenance
    {
        return Soutenance::firstOrCreate(
            ['etudiant_id' => $etudiant->id, 'session_id' => $session->id],
            [
                'titre_memoire' => $etudiant->titre_memoire_demo,
                'statut' => $statut,
            ]
        );
    }
}
