<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Feature extends Model
{
    protected $fillable = [
        'key',
        'name',
        'description',
        'category',
        'is_active_by_default',
    ];

    protected $casts = [
        'is_active_by_default' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function restaurants(): BelongsToMany
    {
        return $this->belongsToMany(Restaurant::class, 'restaurant_features')
            ->withPivot('enabled')
            ->withTimestamps();
    }

    public function restaurantFeatureOverrides(): HasMany
    {
        return $this->hasMany(RestaurantFeature::class);
    }
}
