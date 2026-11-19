<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Resultat extends Model
{
    protected $table = 'resultats';

    protected $fillable = [
        'soutenance_id',
        'moyenne_finale',
        'decision',
        'valide_par',
        'valide_le',
        'observations',
        'archive_le',
    ];

    protected $casts = [
        'moyenne_finale' => 'decimal:2',
        'valide_le' => 'datetime',
        'archive_le' => 'datetime',
    ];

    public function soutenance(): BelongsTo
    {
        return $this->belongsTo(Soutenance::class, 'soutenance_id');
    }

    public function procesVerbal(): HasOne
    {
        return $this->hasOne(ProcesVerbal::class, 'resultat_id');
    }

    public function validePar(): BelongsTo
    {
        return $this->belongsTo(Enseignant::class, 'valide_par');
    }
    public function getMentionAttribute(): ?string
    {
        if ($this->moyenne_finale === null) {
            return null;
        }

        $moyenne = (float) $this->moyenne_finale;

        return match (true) {
            $moyenne >= 18 && $moyenne <= 20 => 'Honorable',
            $moyenne >= 17 && $moyenne < 18 => 'Très bien',
            $moyenne >= 14 && $moyenne < 17 => 'Bien',
            $moyenne >= 12 && $moyenne < 14 => 'Assez bien',
            $moyenne >= 10 && $moyenne < 12 => 'Passable',
            default => null,
        };
    }
}