<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notification applicative adressée à un utilisateur.
 *
 * Conformité au livre :
 *   - Cas d'utilisation module Étudiant : consultation de ses
 *     notifications depuis son espace personnel.
 *   - BF02 / BF06 : mise à disposition des convocations et suivi.
 */
class Notification extends Model
{
    protected $table = 'notifications';

    protected $fillable = [
        'user_id',
        'titre',
        'message',
        'type',
        'lue_le',
        'donnees',
    ];

    protected function casts(): array
    {
        return [
            'lue_le' => 'datetime',
            'donnees' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * La notification est-elle encore non lue ?
     */
    public function estNonLue(): bool
    {
        return $this->lue_le === null;
    }

    /**
     * Marque la notification comme lue.
     */
    public function marquerCommeLue(): void
    {
        if ($this->lue_le === null) {
            $this->lue_le = now();
            $this->save();
        }
    }
}
