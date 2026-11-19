<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Convocation extends Model
{
    protected $table = 'convocations';

    protected $fillable = [
        'soutenance_id',
        'type_destinataire',
        'destinataire_user_id',
        'date_generation',
        'date_envoi',
        'statut',
        'fichier_path',
    ];

    protected function casts(): array
    {
        return [
            'date_generation' => 'datetime',
            'date_envoi' => 'datetime',
        ];
    }

    public function soutenance(): BelongsTo
    {
        return $this->belongsTo(Soutenance::class);
    }

    public function destinataire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'destinataire_user_id');
    }
}
