<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Evaluation extends Model
{
    protected $table = 'evaluations';

    protected $fillable = [
        'soutenance_id',
        'enseignant_id',
        'note',
        'commentaire',
        'validee',
        'validee_le',
    ];

    protected $casts = [
        'note' => 'decimal:2',
        'validee' => 'boolean',
        'validee_le' => 'datetime',
    ];

    public function soutenance(): BelongsTo
    {
        return $this->belongsTo(Soutenance::class, 'soutenance_id');
    }

    public function enseignant(): BelongsTo
    {
        return $this->belongsTo(Enseignant::class, 'enseignant_id');
    }
}