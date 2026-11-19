<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Etudiant extends Model
{
    protected $table = 'etudiants';

    protected $fillable = [
        'user_id',
        'promotion_id',
        'numero_etudiant',
        'telephone',
        'autorise_soutenance',
    ];

    protected $casts = [
        'autorise_soutenance' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class, 'promotion_id');
    }

    public function soutenances(): HasMany
    {
        return $this->hasMany(Soutenance::class, 'etudiant_id');
    }
}