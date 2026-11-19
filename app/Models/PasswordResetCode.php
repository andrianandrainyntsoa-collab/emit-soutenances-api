<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Code de réinitialisation de mot de passe (envoyé par e-mail).
 *
 * Le code est stocké haché (Hash::make) ; il expire après 30 minutes
 * et ne peut être utilisé qu'une seule fois.
 */
class PasswordResetCode extends Model
{
    protected $table = 'password_reset_codes';

    protected $fillable = [
        'email',
        'code',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];
}
