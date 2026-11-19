<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SessionSoutenance extends Model
{
    protected $table = 'sessions_soutenance';

    protected $fillable = [
        'nom',
        'date_debut',
        'date_fin',
        'annee_universitaire',
        'statut',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'date_debut' => 'date',
            'date_fin' => 'date',
        ];
    }

    public function jurys(): HasMany
    {
        return $this->hasMany(Jury::class, 'session_id');
    }

    public function soutenances(): HasMany
    {
        return $this->hasMany(Soutenance::class, 'session_id');
    }
}
