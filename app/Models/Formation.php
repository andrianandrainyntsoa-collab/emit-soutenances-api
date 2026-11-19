<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Formation extends Model
{
    protected $table = 'formations';

    protected $fillable = [
        'code',
        'nom',
        'description',
        'active',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function promotions(): HasMany
    {
        return $this->hasMany(Promotion::class);
    }

    public function parcours(): HasMany
    {
        return $this->hasMany(Parcours::class, 'formation_id');
    }  
}
