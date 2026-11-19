<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migrations de correction (non destructives) :
 *
 *  1. users.est_autorise : autorisation de compte par l'administrateur.
 *     Les comptes étudiants / enseignants créés via l'inscription
 *     démarrent avec est_autorise = false et ne peuvent accéder aux
 *     fonctionnalités protégées qu'après autorisation par un
 *     administrateur (web ou mobile — un seul et même compte).
 *
 *  2. soutenances.date_soutenance / heure_debut / heure_fin :
 *     la date et les heures de soutenance sont portées directement
 *     par la soutenance (la sélection des créneaux est intégrée à la
 *     modale de planification). Les données existantes sont
 *     synchronisées depuis la table creneaux liée.
 *
 *  3. resultats.archive_le : archivage logique utilisé par la
 *     fonctionnalité « Nettoyer » (les données officielles et les
 *     archives restent conservées).
 *
 *  4. Table historique_masques : masquage de l'historique personnel
 *     (nettoyage de l'historique de soutenances / évaluations dans
 *     l'application mobile sans supprimer les données officielles).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Autorisation des comptes -----------------------------------------------------
        if (!Schema::hasColumn('users', 'est_autorise')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('est_autorise')->default(false)->after('password');
            });

            // Les comptes existants (administrateurs, responsables,
            // enseignants et étudiants déjà présents) restent
            // utilisables : ils sont marqués autorisés. Seules les
            // NOUVELLES inscriptions démarreront en attente.
            DB::table('users')->update([
                'est_autorise' => true,
            ]);
        }

        // 2. Horaires portés par la soutenance --------------------------------------------
        if (!Schema::hasColumn('soutenances', 'date_soutenance')) {
            Schema::table('soutenances', function (Blueprint $table) {
                $table->date('date_soutenance')->nullable()->after('creneau_id');
                $table->time('heure_debut')->nullable()->after('date_soutenance');
                $table->time('heure_fin')->nullable()->after('heure_debut');

                $table->index(['date_soutenance', 'statut']);
            });

            // Synchronisation des soutenances existantes depuis leur créneau.
            DB::statement(
                'UPDATE soutenances SET date_soutenance = (SELECT c.date_soutenance FROM creneaux c WHERE c.id = soutenances.creneau_id), '
                .'heure_debut = (SELECT c.heure_debut FROM creneaux c WHERE c.id = soutenances.creneau_id), '
                .'heure_fin = (SELECT c.heure_fin FROM creneaux c WHERE c.id = soutenances.creneau_id) '
                .'WHERE creneau_id IS NOT NULL'
            );
        }

        // 3. Archivage des résultats -------------------------------------------------------
        if (!Schema::hasColumn('resultats', 'archive_le')) {
            Schema::table('resultats', function (Blueprint $table) {
                $table->timestamp('archive_le')->nullable()->after('observations');
            });
        }

        // 4. Historique personnel masqué ---------------------------------------------------
        if (!Schema::hasTable('historique_masques')) {
            Schema::create('historique_masques', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('soutenance_id')->nullable()->constrained('soutenances')->cascadeOnDelete();
                $table->foreignId('evaluation_id')->nullable()->constrained('evaluations')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['user_id', 'soutenance_id', 'evaluation_id'], 'historique_masques_unique');
            });
        }
    }

    public function down(): void
    {
        // Non destructif : on ne supprime jamais de données. Le
        // rollback retire uniquement les structures ajoutées.
        if (Schema::hasTable('historique_masques')) {
            Schema::dropIfExists('historique_masques');
        }

        if (Schema::hasColumn('resultats', 'archive_le')) {
            Schema::table('resultats', function (Blueprint $table) {
                $table->dropColumn('archive_le');
            });
        }

        if (Schema::hasColumn('soutenances', 'heure_fin')) {
            Schema::table('soutenances', function (Blueprint $table) {
                $table->dropIndex(['date_soutenance', 'statut']);
                $table->dropColumn(['date_soutenance', 'heure_debut', 'heure_fin']);
            });
        }

        if (Schema::hasColumn('users', 'est_autorise')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('est_autorise');
            });
        }
    }
};
