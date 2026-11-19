<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Creneau extends Model
{
    protected $table = 'creneaux';

    protected $fillable = [
        'date_soutenance',
        'heure_debut',
        'heure_fin',
        'statut',
    ];

    protected function casts(): array
    {
        return [
            'date_soutenance' => 'date',
        ];
    }

    public function soutenances(): HasMany
    {
        return $this->hasMany(Soutenance::class, 'creneau_id');
    }
}
