<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DishAsset extends Model
{
    public const TYPE_USDZ = 'usdz';
    public const TYPE_GLB = 'glb';
    public const TYPE_PREVIEW_IMAGE = 'preview_image';
    public const TYPE_INGREDIENT_IMAGE = 'ingredient_image';

    protected $fillable = [
        'uuid',
        'dish_id',
        'asset_type',
        'storage_disk',
        'file_path',
        'glb_path',
        'usdz_path',
        'file_url',
        'file_size',
        'mime_type',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'uuid' => 'string',
        'metadata' => 'array', // This tells Laravel to convert array ↔ JSON automatically
        'file_size' => 'integer',
        'created_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    /**
     * Get the dish that owns the asset.
     */
    public function dish(): BelongsTo
    {
        return $this->belongsTo(Dish::class);
    }

    public function getFileUrlAttribute(?string $value): ?string
    {
        if (! $this->exists || ! $this->getKey()) {
            return $value;
        }

        return route('api.assets.show', ['asset' => $this->getKey()], false);
    }
}
