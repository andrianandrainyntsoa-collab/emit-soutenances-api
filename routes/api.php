<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\ConvocationController;
use App\Http\Controllers\Api\CreneauController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DeliberationController;
use App\Http\Controllers\Api\DisponibiliteJuryController;
use App\Http\Controllers\Api\DisponibiliteSalleController;
use App\Http\Controllers\Api\EnseignantController;
use App\Http\Controllers\Api\EspaceController;
use App\Http\Controllers\Api\EtudiantController;
use App\Http\Controllers\Api\EvaluationController;
use App\Http\Controllers\Api\FormationController;
use App\Http\Controllers\Api\JuryController;
use App\Http\Controllers\Api\MembreJuryController;
use App\Http\Controllers\Api\MotDePasseController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ParcoursController;
use App\Http\Controllers\Api\PromotionController;
use App\Http\Controllers\Api\ProcesVerbalController;
use App\Http\Controllers\Api\RegisterController;
use App\Http\Controllers\Api\ResultatController;
use App\Http\Controllers\Api\SalleController;
use App\Http\Controllers\Api\SessionSoutenanceController;
use App\Http\Controllers\Api\SoutenanceController;
use App\Http\Controllers\Api\UserController;

/*
|--------------------------------------------------------------------------
| Routes de l'API — Gestion de Soutenance Master
|--------------------------------------------------------------------------
| Organisation conforme au livre :
|   - Authentification (BF01)
|   - Administration : utilisateurs, référentiels, sessions, salles,
|     créneaux, disponibilités (BF02, BF03, BF04)
|   - Planification et soutenance (BF05)
|   - Convocations (BF06)
|   - Évaluation et délibération (BF07, BF08)
|   - Résultat final et procès-verbal (BF09, BF10, BF11)
|   - Traçabilité et tableau de bord (BF12)
*/

// Connexion avec protection anti force-brute.
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

// ----------------------------------------------------------------------
// Routes publiques : inscription autonome et mot de passe oublié.
// Chaque utilisateur choisit son propre mot de passe ; les comptes
// créés démarrent « en attente d'autorisation » et apparaissent dans
// la partie « Utilisateurs » de l'administration.
// ----------------------------------------------------------------------
Route::middleware('throttle:30,1')->group(function () {
    Route::get('/public/referentiels', [RegisterController::class, 'referentiels']);
    Route::post('/register/etudiant', [RegisterController::class, 'etudiant']);
    Route::post('/register/enseignant', [RegisterController::class, 'enseignant']);
    Route::post('/mot-de-passe/oublie', [MotDePasseController::class, 'oublier']);
    Route::post('/mot-de-passe/reinitialiser', [MotDePasseController::class, 'reinitialiser']);
});

Route::middleware(['auth:sanctum', 'compte_autorise'])->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user()->load('role', 'etudiant.promotion', 'enseignant');
    });

    Route::post('/logout', [AuthController::class, 'logout']);

    // ------------------------------------------------------------------
    // Espace personnel (tous rôles) — « Mon Profil » (web + mobile)
    // ------------------------------------------------------------------
    Route::get('/profil', [EspaceController::class, 'monProfil']);
    Route::put('/profil', [EspaceController::class, 'majMonProfil']);
    Route::post('/mon-historique/nettoyer', [EspaceController::class, 'nettoyerMonHistorique'])
        ->middleware('throttle:10,1');
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/non-lues-count', [NotificationController::class, 'nonLuesCount']);
    Route::post('/notifications/tout-marquer-lues', [NotificationController::class, 'marquerToutesLues']);
    Route::post('/notifications/{notification}/lue', [NotificationController::class, 'marquerLue']);

    // Suppression des notifications (web + mobile).
    Route::delete('/notifications', [NotificationController::class, 'supprimerToutes']);
    Route::delete('/notifications/{notification}', [NotificationController::class, 'supprimer']);
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // ------------------------------------------------------------------
    // Administration : utilisateurs et rôles (BF02)
    // L'administrateur ne crée que des comptes administrateurs, ne
    // modifie pas les informations personnelles des étudiants /
    // enseignants et peut autoriser un compte (web = mobile).
    // ------------------------------------------------------------------
    Route::middleware('role:administrateur')->group(function () {
        Route::get('/users/roles', [UserController::class, 'roles']);
        Route::apiResource('users', UserController::class);
        Route::post('/users/{user}/autoriser', [UserController::class, 'autoriser']);

        // Journaux de traçabilité (BF12 / RG17) + nettoyage.
        Route::get('/audit-logs', [AuditLogController::class, 'index']);
        Route::delete('/audit-logs', [AuditLogController::class, 'nettoyer'])
            ->middleware('throttle:5,1');
    });

    // ------------------------------------------------------------------
    // Administration : étudiants (BF03 / RG01) — consultation,
    // autorisation à soutenir et suppression ; pas de création ni
    // d'édition administrative (inscription autonome + Mon Profil).
    // ------------------------------------------------------------------
    Route::middleware('role:administrateur')->group(function () {
        Route::apiResource('etudiants', EtudiantController::class)
            ->only(['index', 'show', 'destroy']);
        Route::post('/etudiants/{etudiant}/autoriser', [EtudiantController::class, 'autoriser']);
    });

    // ------------------------------------------------------------------
    // Enseignants — consultation et suppression selon les règles
    // métier ; pas de création ni d'édition administrative.
    // ------------------------------------------------------------------
    Route::get('/enseignants', [EnseignantController::class, 'index'])
        ->middleware('role:administrateur,responsable');

    Route::middleware('role:administrateur')->group(function () {
        Route::apiResource('enseignants', EnseignantController::class)
            ->only(['show', 'destroy']);
    });

    // ------------------------------------------------------------------
    // Administration : référentiels académiques (BF03)
    // ------------------------------------------------------------------
    Route::middleware('role:administrateur')->group(function () {
        Route::apiResource('formations', FormationController::class);
        Route::apiResource('promotions', PromotionController::class);
        Route::apiResource('parcours', ParcoursController::class);
    });

    // Consultation des référentiels pour les formulaires.
    Route::middleware('role:administrateur,responsable,enseignant,etudiant')->group(function () {
        Route::get('/formations', [FormationController::class, 'index']);
        Route::get('/promotions', [PromotionController::class, 'index']);
        Route::get('/parcours', [ParcoursController::class, 'index']);
    });

    // ------------------------------------------------------------------
    // Sessions de soutenance (BF03 / RG02)
    // ------------------------------------------------------------------
    Route::get('/sessions', [SessionSoutenanceController::class, 'index'])
        ->middleware('role:administrateur,responsable,enseignant,etudiant');
    Route::get('/sessions/{session}', [SessionSoutenanceController::class, 'show'])
        ->middleware('role:administrateur,responsable,enseignant,etudiant');

    Route::middleware('role:administrateur')->group(function () {
        Route::post('/sessions', [SessionSoutenanceController::class, 'store']);
        Route::put('/sessions/{session}', [SessionSoutenanceController::class, 'update']);
        Route::patch('/sessions/{session}', [SessionSoutenanceController::class, 'update']);
        Route::delete('/sessions/{session}', [SessionSoutenanceController::class, 'destroy']);
        Route::post('/sessions/{session}/ouvrir', [SessionSoutenanceController::class, 'ouvrir']);
        Route::post('/sessions/{session}/cloturer', [SessionSoutenanceController::class, 'cloturer']);
    });

    // ------------------------------------------------------------------
    // Ressources de planification : salles, créneaux, disponibilités
    // (BF04)
    // ------------------------------------------------------------------
    Route::middleware('role:administrateur')->group(function () {
        Route::get('/salles/disponibles', [SalleController::class, 'disponibles']);
        Route::apiResource('salles', SalleController::class);
        Route::apiResource('creneaux', CreneauController::class);

        Route::post('/disponibilites-salle', [DisponibiliteSalleController::class, 'store']);
        Route::put('/disponibilites-salle/{disponibilite}', [DisponibiliteSalleController::class, 'update']);
        Route::delete('/disponibilites-salle/{disponibilite}', [DisponibiliteSalleController::class, 'destroy']);

        Route::get('/disponibilites-jury', [DisponibiliteJuryController::class, 'index']);
        Route::post('/disponibilites-jury', [DisponibiliteJuryController::class, 'store']);
        Route::put('/disponibilites-jury/{disponibilite}', [DisponibiliteJuryController::class, 'update']);
        Route::delete('/disponibilites-jury/{disponibilite}', [DisponibiliteJuryController::class, 'destroy']);
    });

    // Consultation des disponibilités de salles : planification et
    // suivi (administration + responsable).
    Route::get('/disponibilites-salle', [DisponibiliteSalleController::class, 'index'])
        ->middleware('role:administrateur,responsable');

    // L'enseignant gère ses propres disponibilités (vue jury).
    Route::middleware('role:enseignant')->group(function () {
        Route::get('/mes-disponibilites', [DisponibiliteJuryController::class, 'mesDisponibilites']);
        Route::post('/mes-disponibilites', function (Request $request, DisponibiliteJuryController $controller) {
            $enseignant = $request->user()->enseignant;

            if (!$enseignant) {
                return response()->json(['message' => 'Profil enseignant introuvable.'], 403);
            }

            $request->merge(['enseignant_id' => $enseignant->id]);

            return $controller->store($request);
        });
    });

    // ------------------------------------------------------------------
    // Jurys et membres (BF04 / RG03) — affichage par NOM du groupe de
    // jury ; les jurys disponibles pour un intervalle sont filtrables
    // via /jurys/disponibles.
    // ------------------------------------------------------------------
    Route::middleware('role:administrateur')->group(function () {
        Route::get('/jurys/disponibles', [JuryController::class, 'disponibles']);
        Route::apiResource('jurys', JuryController::class)->only(['index', 'store', 'update', 'destroy', 'show']);
        Route::post('/jurys/{jury}/membres', [JuryController::class, 'ajouterMembre']);
        Route::delete('/jurys/{jury}/membres/{membre}', [JuryController::class, 'retirerMembre']);
        Route::get('/jurys/{juryId}/membres', [MembreJuryController::class, 'index']);
    });

    // Vue jury de l'enseignant connecté.
    Route::get('/mes-jurys', [JuryController::class, 'mesJurys'])
        ->middleware('role:enseignant,administrateur');

    // ------------------------------------------------------------------
    // Planification des soutenances (BF05)
    // ------------------------------------------------------------------
    Route::middleware('role:administrateur')->group(function () {
        Route::post('/soutenances', [SoutenanceController::class, 'store']);
        Route::put('/soutenances/{soutenance}', [SoutenanceController::class, 'update']);
        Route::patch('/soutenances/{soutenance}', [SoutenanceController::class, 'update']);
        Route::delete('/soutenances/{soutenance}', [SoutenanceController::class, 'destroy']);
        Route::post('/soutenances/{soutenance}/confirmer', [SoutenanceController::class, 'confirmer']);
        Route::post('/soutenances/{soutenance}/terminer', [SoutenanceController::class, 'terminer']);
        Route::post('/soutenances/{soutenance}/ajourner', [SoutenanceController::class, 'ajourner']);
    });

    // Consultation des soutenances : acteurs du processus.
    Route::middleware('role:administrateur,responsable,enseignant,etudiant')->group(function () {
        Route::get('/soutenances', [SoutenanceController::class, 'index']);
        Route::get('/soutenances/{soutenance}', [SoutenanceController::class, 'show']);
    });

    // ------------------------------------------------------------------
    // Convocations (BF06) — regroupées par soutenance, envoi groupé
    // par e-mail réel (SMTP, un courriel individuel par destinataire).
    // ------------------------------------------------------------------
    Route::middleware('role:administrateur')->group(function () {
        Route::post('/soutenances/{soutenance}/convocations/generer', [ConvocationController::class, 'genererPourSoutenance']);
        Route::post('/soutenances/{soutenance}/convocations/envoyer', [ConvocationController::class, 'envoyerPourSoutenance'])
            ->middleware('throttle:10,1');
        Route::post('/convocations/{convocation}/envoyer', [ConvocationController::class, 'envoyer']);
    });

    Route::get('/convocations/par-soutenance', [ConvocationController::class, 'parSoutenance'])
        ->middleware('role:administrateur,responsable');

    Route::get('/convocations', [ConvocationController::class, 'index'])
        ->middleware('role:administrateur,responsable');

    Route::get('/mes-convocations', [ConvocationController::class, 'mesConvocations'])
        ->middleware('role:etudiant,enseignant');

    Route::get('/convocations/{convocation}/telecharger', [ConvocationController::class, 'telecharger'])
        ->middleware('role:administrateur,responsable,etudiant,enseignant');

    Route::get('/convocations/{convocation}/afficher', [ConvocationController::class, 'afficher'])
        ->middleware('role:administrateur,responsable,etudiant,enseignant');

    // ------------------------------------------------------------------
    // Évaluations (BF07 / RG04 / RG05)
    // ------------------------------------------------------------------
    Route::middleware('role:enseignant')->group(function () {
        Route::get('/evaluations', [EvaluationController::class, 'index']);

        Route::post(
            '/soutenances/{soutenanceId}/evaluations',
            [EvaluationController::class, 'store']
        );

        Route::post(
            '/evaluations/{evaluationId}/valider',
            [EvaluationController::class, 'valider']
        );
    });

    // ------------------------------------------------------------------
    // Délibération (BF08 / RG06 / RG07 / RG08)
    // ------------------------------------------------------------------
    Route::middleware('role:enseignant')->group(function () {
        Route::get('/soutenances/{soutenance}/deliberation', [DeliberationController::class, 'consulter']);
        Route::post('/soutenances/{soutenance}/deliberation/ouvrir', [DeliberationController::class, 'ouvrir']);
        Route::post('/soutenances/{soutenance}/deliberation/enregistrer', [DeliberationController::class, 'enregistrer']);
    });

    Route::get('/deliberations', [DeliberationController::class, 'index'])
        ->middleware('role:administrateur,responsable');

    // ------------------------------------------------------------------
    // Résultat final (BF09 / RG09 / RG10) — validation par le
    // président, contrôle du rôle président effectué dans le
    // contrôleur.
    // ------------------------------------------------------------------
    Route::middleware('role:enseignant')->group(function () {
        Route::post(
            '/resultats/{resultatId}/valider',
            [ResultatController::class, 'valider']
        );
    });

    Route::get('/resultats', [ResultatController::class, 'index'])
        ->middleware('role:administrateur,enseignant,responsable');

    // Nettoyage de la liste de travail (archivage, pas de suppression
    // de données officielles) — administrateur et responsable.
    Route::post('/resultats/nettoyer', [ResultatController::class, 'nettoyer'])
        ->middleware(['role:administrateur,responsable', 'throttle:5,1']);

    // Compte rendu PDF des résultats (données réelles du système).
    Route::get('/resultats/compte-rendu', [ResultatController::class, 'compteRendu'])
        ->middleware('role:administrateur,enseignant,responsable');

    // Résultat de l'étudiant connecté.
    Route::get('/mon-resultat', [EspaceController::class, 'monResultat'])
        ->middleware('role:etudiant');

    // ------------------------------------------------------------------
    // Procès-verbaux (BF10, BF11 / RG11 à RG16)
    // ------------------------------------------------------------------
    Route::middleware('role:enseignant')->group(function () {
        Route::post(
            '/resultats/{resultatId}/proces-verbal',
            [ProcesVerbalController::class, 'generer']
        );
    });

    // Consultation et téléchargement : administration, jury,
    // responsable (RG14). Suppression d'un PV après téléchargement
    // (administrateur / responsable uniquement).
    Route::middleware('role:administrateur,enseignant,responsable')->group(function () {
        Route::get(
            '/proces-verbaux',
            [ProcesVerbalController::class, 'index']
        );

        Route::get(
            '/proces-verbaux/{procesVerbalId}/telecharger',
            [ProcesVerbalController::class, 'telecharger']
        );

        Route::get(
            '/proces-verbaux/{procesVerbalId}/afficher',
            [ProcesVerbalController::class, 'afficher']
        );

        Route::delete(
            '/proces-verbaux/{procesVerbalId}',
            [ProcesVerbalController::class, 'supprimer']
        )->middleware(['role:administrateur,responsable', 'throttle:10,1']);
    });

    // PV de l'étudiant connecté (téléchargement — RG14).
    Route::get('/mon-proces-verbal', [ProcesVerbalController::class, 'monProcesVerbal'])
        ->middleware('role:etudiant');

    Route::get('/mes-pv/{procesVerbalId}/telecharger', [ProcesVerbalController::class, 'telecharger'])
        ->middleware('role:etudiant');

    Route::get('/mes-pv/{procesVerbalId}/afficher', [ProcesVerbalController::class, 'afficher'])
        ->middleware('role:etudiant');

    // Espace étudiant : sa soutenance (date, heure, salle, jury).
    Route::get('/ma-soutenance', [EspaceController::class, 'maSoutenance'])
        ->middleware('role:etudiant');

    // Espace enseignant : ses jurys et soutenances.
    Route::get('/mon-espace-enseignant', [EspaceController::class, 'monEspaceEnseignant'])
        ->middleware('role:enseignant');
});
