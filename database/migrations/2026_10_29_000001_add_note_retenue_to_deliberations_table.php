<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enrichit la table deliberations avec la note finale
     * retenue par le jury et la mention correspondante.
     *
     * Conformité au livre :
     *   - RG07 / RM02 : la note finale officielle est une note
     *     retenue par le jury lors de la délibération et non une
     *     note calculée automatiquement par le système.
     *   - BF09 : enregistrer la note finale retenue par le jury.
     */
    public function up(): void
    {
        Schema::table('deliberations', function (Blueprint $table) {
            $table->decimal('note_finale_retenue', 5, 2)
                ->nullable()
                ->after('observations')
                ->comment('Note finale retenue par le jury lors de la délibération');

            $table->string('mention', 100)
                ->nullable()
                ->after('note_finale_retenue')
                ->comment('Mention retenue par le jury (ex : Bien, Très bien...)');
        });
    }

    public function down(): void
    {
        Schema::table('deliberations', function (Blueprint $table) {
            $table->dropColumn(['note_finale_retenue', 'mention']);
        });
    }
};
