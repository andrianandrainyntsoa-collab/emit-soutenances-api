<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Enseignant extends Model
{
    protected $table = 'enseignants';

    protected $fillable = [
        'user_id',
        'matricule',
        'grade',
        'specialite',
        'telephone',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class, 'enseignant_id');
    }

    public function membresJury(): HasMany
    {
        return $this->hasMany(MembreJury::class, 'enseignant_id');
    }

    public function disponibilites(): HasMany
    {
        return $this->hasMany(DisponibiliteJury::class, 'enseignant_id');
    }
}