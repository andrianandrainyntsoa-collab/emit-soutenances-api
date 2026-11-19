<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Jury extends Model
{
    protected $table = 'jurys';

    protected $fillable = [
        'session_id',
        'code',
        'nom',
    ];

    /**
     * La relation membresJury est exposée en camelCase « membresJury »
     * en plus du snake_case généré par Laravel : corrige l'affichage
     * vide de la composition du jury côté web/mobile (les interfaces
     * lisent la clé camelCase).
     */
    protected $appends = ['membresJury'];

    public function getMembresJuryAttribute()
    {
        if ($this->relationLoaded('membresJury')) {
            return $this->getRelation('membresJury');
        }

        return $this->getRelationValue('membresJury');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(SessionSoutenance::class, 'session_id');
    }

    public function membresJury(): HasMany
    {
        return $this->hasMany(MembreJury::class, 'jury_id');
    }

    public function soutenances(): HasMany
    {
        return $this->hasMany(Soutenance::class, 'jury_id');
    }
}
