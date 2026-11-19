<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisponibiliteJury extends Model
{
    protected $table = 'disponibilites_jury';

    protected $fillable = [
        'enseignant_id',
        'date_disponible',
        'heure_debut',
        'heure_fin',
    ];

    protected function casts(): array
    {
        return [
            'date_disponible' => 'date',
        ];
    }

    public function enseignant(): BelongsTo
    {
        return $this->belongsTo(Enseignant::class, 'enseignant_id');
    }
}
