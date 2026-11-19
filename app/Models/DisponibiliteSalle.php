<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisponibiliteSalle extends Model
{
    protected $table = 'disponibilites_salle';

    protected $fillable = [
        'salle_id',
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

    public function salle(): BelongsTo
    {
        return $this->belongsTo(Salle::class, 'salle_id');
    }
}
