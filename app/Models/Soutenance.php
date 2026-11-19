<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Soutenance extends Model
{
    public const STATUTS_ACTIFS = ['a_planifier', 'planifiee', 'confirmee'];

    protected $casts = [
        'date_soutenance' => 'date:Y-m-d',
    ];

    protected $table = 'soutenances';

    protected $fillable = [
        'etudiant_id',
        'session_id',
        'jury_id',
        'salle_id',
        'creneau_id',
        'date_soutenance',
        'heure_debut',
        'heure_fin',
        'titre_memoire',
        'statut',
        'observations',
    ];

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class, 'etudiant_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(SessionSoutenance::class, 'session_id');
    }

    public function jury(): BelongsTo
    {
        return $this->belongsTo(Jury::class, 'jury_id');
    }

    public function salle(): BelongsTo
    {
        return $this->belongsTo(Salle::class, 'salle_id');
    }

    public function creneau(): BelongsTo
    {
        return $this->belongsTo(Creneau::class, 'creneau_id');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class, 'soutenance_id');
    }

    public function resultat(): HasOne
    {
        return $this->hasOne(Resultat::class, 'soutenance_id');
    }

    public function deliberation(): HasOne
    {
        return $this->hasOne(Deliberation::class, 'soutenance_id');
    }

    public function convocations(): HasMany
    {
        return $this->hasMany(Convocation::class, 'soutenance_id');
    }
}
