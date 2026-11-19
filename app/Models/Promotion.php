<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Promotion extends Model
{
    protected $table = 'promotions';

    protected $fillable = [
        'formation_id',
        'parcours_id',
        'nom',
        'annee_universitaire',
    ];

    protected function casts(): array
    {
        return [
            'formation_id' => 'integer',
            'parcours_id' => 'integer',
        ];
    }

    public function formation(): BelongsTo
    {
        return $this->belongsTo(Formation::class, 'formation_id');
    }

    public function parcours(): BelongsTo
    {
        return $this->belongsTo(Parcours::class, 'parcours_id');
    }

    public function etudiants(): HasMany
    {
        return $this->hasMany(Etudiant::class, 'promotion_id');
    }
}
