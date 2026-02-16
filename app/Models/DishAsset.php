<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DishAsset extends Model
{
    protected $fillable = [
        'uuid',
        'dish_id',
        'asset_type',
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

    /**
     * Get the dish that owns the asset.
     */
    public function dish(): BelongsTo
    {
        return $this->belongsTo(Dish::class);
    }
}
