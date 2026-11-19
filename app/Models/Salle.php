<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Salle extends Model
{
    protected $table = 'salles';

    protected $fillable = [
        'code',
        'batiment',
        'etage',
        'nom',
        'capacite',
        'localisation',
        'disponible',
    ];

    protected function casts(): array
    {
        return [
            'etage' => 'integer',
            'capacite' => 'integer',
            'disponible' => 'boolean',
        ];
    }

    public function soutenances(): HasMany
    {
        return $this->hasMany(Soutenance::class, 'salle_id');
    }

    public function disponibilites(): HasMany
    {
        return $this->hasMany(DisponibiliteSalle::class, 'salle_id');
    }
}
