<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Restaurant extends Model
{
    protected $fillable = [
        'uuid',
        'user_id',
        'name',
        'slug',
        'status',
        'description',
        'address',
        'currency',
        'dollar_rate',
        'manual_table_count',
    ];

    protected $casts = [
        'uuid' => 'string',
        'status' => 'string',
        'created_at' => 'datetime',
        'dollar_rate' => 'decimal:2',
        'manual_table_count' => 'integer',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dishes(): HasMany
    {
        return $this->hasMany(Dish::class);
    }

    public function staffUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withTimestamps();
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(Ingredient::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function tables(): HasMany
    {
        return $this->hasMany(RestaurantTable::class);
    }

    public function tableWaves(): HasMany
    {
        return $this->hasMany(TableWave::class);
    }

    public function tableSessions(): HasMany
    {
        return $this->hasMany(TableSession::class);
    }

    public function roomPlans(): HasMany
    {
        return $this->hasMany(RoomPlan::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(RestaurantDomain::class);
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'restaurant_features')
            ->withPivot('enabled')
            ->withTimestamps();
    }

    public function restaurantFeatures(): HasMany
    {
        return $this->hasMany(RestaurantFeature::class);
    }

    public function featureFlagAuditLogs(): HasMany
    {
        return $this->hasMany(FeatureFlagAuditLog::class);
    }

}
