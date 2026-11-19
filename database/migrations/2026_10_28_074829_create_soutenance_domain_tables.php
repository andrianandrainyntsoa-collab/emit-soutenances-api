<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')
                ->nullable()
                ->constrained('roles')
                ->nullOnDelete();
        });

        Schema::create('formations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('nom', 150);
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('formation_id')
                ->constrained('formations')->cascadeOnDelete();
            $table->string('nom', 100);
            $table->string('annee_universitaire', 20);
            $table->timestamps();

            $table->unique(['formation_id', 'nom', 'annee_universitaire']);
        });

        Schema::create('etudiants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('promotion_id')
                ->constrained('promotions')->restrictOnDelete();
            $table->string('numero_etudiant', 50)->unique();
            $table->string('telephone', 30)->nullable();
            $table->boolean('autorise_soutenance')->default(false);
            $table->timestamps();
        });

        Schema::create('enseignants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->unique()->constrained('users')->cascadeOnDelete();
            $table->string('matricule', 50)->nullable()->unique();
            $table->string('grade', 100)->nullable();
            $table->string('specialite', 150)->nullable();
            $table->string('telephone', 30)->nullable();
            $table->timestamps();
        });

        Schema::create('sessions_soutenance', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 150);
            $table->date('date_debut');
            $table->date('date_fin');
            $table->string('annee_universitaire', 20);
            $table->string('statut', 30)->default('planification');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['date_debut', 'date_fin']);
        });

        Schema::create('salles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('nom', 100);
            $table->unsignedSmallInteger('capacite')->default(1);
            $table->string('localisation', 150)->nullable();
            $table->boolean('disponible')->default(true);
            $table->timestamps();
        });

        Schema::create('creneaux', function (Blueprint $table) {
            $table->id();
            $table->date('date_soutenance');
            $table->time('heure_debut');
            $table->time('heure_fin');
            $table->string('statut', 30)->default('disponible');
            $table->timestamps();

            $table->index(['date_soutenance', 'heure_debut', 'heure_fin']);
        });

        Schema::create('jurys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')
                ->constrained('sessions_soutenance')->cascadeOnDelete();
            $table->string('code', 50)->unique();
            $table->string('nom', 150)->nullable();
            $table->timestamps();
        });

        Schema::create('membres_jury', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jury_id')
                ->constrained('jurys')->cascadeOnDelete();
            $table->foreignId('enseignant_id')
                ->constrained('enseignants')->restrictOnDelete();
            $table->string('fonction', 30);
            $table->timestamps();

            $table->unique(['jury_id', 'enseignant_id']);
        });

        Schema::create('disponibilites_jury', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enseignant_id')
                ->constrained('enseignants')->cascadeOnDelete();
            $table->date('date_disponible');
            $table->time('heure_debut');
            $table->time('heure_fin');
            $table->timestamps();

            $table->index(['enseignant_id', 'date_disponible']);
        });

        Schema::create('disponibilites_salle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salle_id')
                ->constrained('salles')->cascadeOnDelete();
            $table->date('date_disponible');
            $table->time('heure_debut');
            $table->time('heure_fin');
            $table->timestamps();

            $table->index(['salle_id', 'date_disponible']);
        });

        Schema::create('soutenances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etudiant_id')
                ->constrained('etudiants')->restrictOnDelete();
            $table->foreignId('session_id')
                ->constrained('sessions_soutenance')->restrictOnDelete();
            $table->foreignId('jury_id')
                ->nullable()->constrained('jurys')->nullOnDelete();
            $table->foreignId('salle_id')
                ->nullable()->constrained('salles')->nullOnDelete();
            $table->foreignId('creneau_id')
                ->nullable()->constrained('creneaux')->nullOnDelete();
            $table->string('titre_memoire', 255);
            $table->string('statut', 30)->default('a_planifier');
            $table->text('observations')->nullable();
            $table->timestamps();

            $table->unique(['etudiant_id', 'session_id']);
            $table->index(['session_id', 'statut']);
        });

        Schema::create('convocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('soutenance_id')
                ->constrained('soutenances')->cascadeOnDelete();
            $table->string('type_destinataire', 30);
            $table->foreignId('destinataire_user_id')
                ->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('date_generation')->nullable();
            $table->timestamp('date_envoi')->nullable();
            $table->string('statut', 30)->default('a_generer');
            $table->string('fichier_path')->nullable();
            $table->timestamps();

            $table->index(['soutenance_id', 'statut']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')->cascadeOnDelete();
            $table->string('titre', 180);
            $table->text('message');
            $table->string('type', 50)->nullable();
            $table->timestamp('lue_le')->nullable();
            $table->jsonb('donnees')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'lue_le']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100);
            $table->string('entite', 100)->nullable();
            $table->unsignedBigInteger('entite_id')->nullable();
            $table->text('description')->nullable();
            $table->string('adresse_ip', 45)->nullable();
            $table->jsonb('details')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entite', 'entite_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('convocations');
        Schema::dropIfExists('soutenances');
        Schema::dropIfExists('disponibilites_salle');
        Schema::dropIfExists('disponibilites_jury');
        Schema::dropIfExists('membres_jury');
        Schema::dropIfExists('jurys');
        Schema::dropIfExists('creneaux');
        Schema::dropIfExists('salles');
        Schema::dropIfExists('sessions_soutenance');
        Schema::dropIfExists('enseignants');
        Schema::dropIfExists('etudiants');
        Schema::dropIfExists('promotions');
        Schema::dropIfExists('formations');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
        });

        Schema::dropIfExists('roles');
    }
};
