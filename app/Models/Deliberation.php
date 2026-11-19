<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deliberation extends Model
{
    protected $table = 'deliberations';

    protected $fillable = [
        'soutenance_id', 'president_enseignant_id',
        'observations', 'decision', 'deliberee_le',
        'note_finale_retenue', 'mention',
    ];

    protected function casts(): array
    {
        return ['deliberee_le' => 'datetime'];
    }

    public function soutenance(): BelongsTo
    {
        return $this->belongsTo(Soutenance::class);
    }

    public function president(): BelongsTo
    {
        return $this->belongsTo(Enseignant::class, 'president_enseignant_id');
    }
}
