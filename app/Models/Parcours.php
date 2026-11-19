<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Parcours extends Model
{
    protected $table = 'parcours';

    protected $fillable = [
        'formation_id',
        'code',
        'nom',
        'description',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function formation(): BelongsTo
    {
        return $this->belongsTo(Formation::class, 'formation_id');
    }

    public function promotions(): HasMany
    {
        return $this->hasMany(Promotion::class, 'parcours_id');
    }
}
