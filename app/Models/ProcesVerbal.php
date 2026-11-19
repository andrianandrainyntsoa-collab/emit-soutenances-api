<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcesVerbal extends Model
{
    protected $table = 'proces_verbaux';

    protected $fillable = [
        'resultat_id',
        'numero_pv',
        'type_master',
        'fichier_path',
        'genere_par',
        'genere_le',
        'statut',
    ];

    protected $casts = [
        'genere_le' => 'datetime',
    ];

    public function resultat(): BelongsTo
    {
        return $this->belongsTo(Resultat::class, 'resultat_id');
    }

    public function generePar(): BelongsTo
    {
        return $this->belongsTo(Enseignant::class, 'genere_par');
    }
}