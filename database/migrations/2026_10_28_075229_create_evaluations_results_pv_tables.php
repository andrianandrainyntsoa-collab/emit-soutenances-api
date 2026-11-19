<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('soutenance_id')
                ->constrained('soutenances')
                ->cascadeOnDelete();

            $table->foreignId('enseignant_id')
                ->constrained('enseignants')
                ->restrictOnDelete();

            $table->decimal('note', 5, 2);
            $table->text('commentaire')->nullable();
            $table->boolean('validee')->default(false);
            $table->timestamp('validee_le')->nullable();

            $table->timestamps();

            $table->unique(
                ['soutenance_id', 'enseignant_id'],
                'evaluations_soutenance_enseignant_unique'
            );
        });

        Schema::create('deliberations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('soutenance_id')
                ->unique()
                ->constrained('soutenances')
                ->cascadeOnDelete();

            $table->foreignId('president_enseignant_id')
                ->constrained('enseignants')
                ->restrictOnDelete();

            $table->text('observations')->nullable();
            $table->string('decision', 30)->default('en_attente');
            $table->timestamp('deliberee_le')->nullable();

            $table->timestamps();
        });

        Schema::create('resultats', function (Blueprint $table) {
            $table->id();

            $table->foreignId('soutenance_id')
                ->unique()
                ->constrained('soutenances')
                ->cascadeOnDelete();

            $table->decimal('moyenne_finale', 5, 2)->nullable();
            $table->string('decision', 30)->default('en_attente');

            $table->foreignId('valide_par')
                ->nullable()
                ->constrained('enseignants')
                ->nullOnDelete();

            $table->timestamp('valide_le')->nullable();
            $table->text('observations')->nullable();

            $table->timestamps();
        });

        Schema::create('proces_verbaux', function (Blueprint $table) {
        $table->id();

        $table->foreignId('resultat_id')
            ->unique()
            ->constrained('resultats')
            ->restrictOnDelete();

        $table->string('numero_pv', 100)->unique();
        $table->string('type_master', 30);
        $table->string('fichier_path')->nullable();

        $table->foreignId('genere_par')
            ->constrained('enseignants')
            ->restrictOnDelete();

        $table->timestamp('genere_le');
        $table->string('statut', 30)->default('genere');
        $table->timestamps();
    });
    }

    public function down(): void
    {
        Schema::dropIfExists('proces_verbaux');
        Schema::dropIfExists('resultats');
        Schema::dropIfExists('deliberations');
        Schema::dropIfExists('evaluations');
    }
};
