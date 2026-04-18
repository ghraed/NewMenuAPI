<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GlobalIngredient extends Model
{
    protected $fillable = [
        'uuid',
        'name',
        'name_ar',
        'normalized_name',
    ];

    protected $casts = [
        'uuid' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function ingredients(): HasMany
    {
        return $this->hasMany(Ingredient::class);
    }
}
