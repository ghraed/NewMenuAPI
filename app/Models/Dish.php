<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Dish extends Model
{
    use SoftDeletes;

    protected $appends = [
        'model_state',
        'is_model_ready',
    ];

    protected $fillable = [
        'uuid',
        'restaurant_id',
        'name',
        'description',
        'price',
        'category',
        'status',
        'image_url',
    ];

    protected $casts = [
        'uuid' => 'string',
        'price' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(DishAsset::class);
    }

    public function scans(): HasMany
    {
        return $this->hasMany(Scan::class);
    }

    public function latestScan(): HasOne
    {
        return $this->hasOne(Scan::class)->latestOfMany();
    }

    public function qrCodes(): HasMany
    {
        return $this->hasMany(QrCode::class);
    }

    public function suggestedDishes(): BelongsToMany
    {
        return $this->belongsToMany(
            Dish::class,
            'dish_suggestions',
            'dish_id',
            'suggested_dish_id'
        )->withTimestamps();
    }

    public function suggestedByDishes(): BelongsToMany
    {
        return $this->belongsToMany(
            Dish::class,
            'dish_suggestions',
            'suggested_dish_id',
            'dish_id'
        )->withTimestamps();
    }

    public function relatedDishes(): BelongsToMany
    {
        return $this->belongsToMany(
            Dish::class,
            'dish_related_dishes',
            'dish_id',
            'related_dish_id'
        )->withTimestamps();
    }

    public function relatedByDishes(): BelongsToMany
    {
        return $this->belongsToMany(
            Dish::class,
            'dish_related_dishes',
            'related_dish_id',
            'dish_id'
        )->withTimestamps();
    }

    public function resolveRouteBinding($value, $field = null)
    {
        return $this->withTrashed()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->firstOrFail();
    }

    public function getIsModelReadyAttribute(): bool
    {
        $assets = $this->relationLoaded('assets')
            ? $this->assets
            : $this->assets()->get();

        return $assets->contains(fn (DishAsset $asset) => $asset->asset_type === 'glb');
    }

    public function getModelStateAttribute(): string
    {
        if ($this->is_model_ready) {
            return 'ready';
        }

        $latestScan = $this->relationLoaded('latestScan')
            ? $this->latestScan
            : $this->latestScan()->first();

        if (! $latestScan) {
            return 'none';
        }

        return match ($latestScan->status) {
            'draft', 'uploaded', 'uploading', 'processing' => 'processing',
            'error', 'canceled' => 'error',
            default => 'none',
        };
    }
}
