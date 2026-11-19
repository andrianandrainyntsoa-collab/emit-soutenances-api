<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Créer la table des parcours
        Schema::create('parcours', function (Blueprint $table) {
            $table->id();

            $table->foreignId('formation_id')
                ->constrained('formations')
                ->restrictOnDelete();

            $table->string('code', 30)->unique();
            $table->string('nom', 180);
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->unique(
                ['formation_id', 'nom'],
                'parcours_formation_nom_unique'
            );
        });

        // 2. Supprimer l'ancienne contrainte des promotions.
        // Elle ne tient pas compte du parcours.
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropUnique(
                'promotions_formation_id_nom_annee_universitaire_unique'
            );
        });

        // 3. Convertir l'année numérique en chaîne sans perdre les valeurs.
        // Exemple : 2026 devient "2026".
        // PostgreSQL : ALTER COLUMN ... TYPE ... USING
        // SQLite : pas de modification de colonne nécessaire,
        //          le type est dynamique et accepte déjà les chaînes.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE promotions
                 ALTER COLUMN annee_universitaire TYPE VARCHAR(20)
                 USING annee_universitaire::text'
            );
        }

        // 4. Ajouter le parcours, sans rendre les anciennes promotions invalides.
        Schema::table('promotions', function (Blueprint $table) {
            $table->foreignId('parcours_id')
                ->nullable()
                ->constrained('parcours')
                ->restrictOnDelete();
        });

        // 5. Nouvelle unicité : parcours + nom + année universitaire.
        Schema::table('promotions', function (Blueprint $table) {
            $table->unique(
                ['parcours_id', 'nom', 'annee_universitaire'],
                'promotions_parcours_nom_annee_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropUnique('promotions_parcours_nom_annee_unique');
            $table->dropConstrainedForeignId('parcours_id');
        });

        // Restaurer l'ancien type numérique (PostgreSQL uniquement).
        // Cette opération peut échouer si des années au format
        // "2026-2027" ont été enregistrées entre-temps.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE promotions
                 ALTER COLUMN annee_universitaire TYPE SMALLINT
                 USING annee_universitaire::smallint'
            );
        }

        Schema::table('promotions', function (Blueprint $table) {
            $table->unique(
                ['formation_id', 'nom', 'annee_universitaire'],
                'promotions_formation_id_nom_annee_universitaire_unique'
            );
        });

        Schema::dropIfExists('parcours');
    }
};
