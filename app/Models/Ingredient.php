<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Ingredient extends Model
{
    public const UNIT_GRAM = 'g';
    public const UNIT_MILLILITER = 'ml';
    public const UNIT_PIECE = 'piece';

    protected $fillable = [
        'uuid',
        'restaurant_id',
        'name',
        'name_ar',
        'storage_disk',
        'file_path',
        'source_file_name',
        'file_size',
        'mime_type',
        'stock_unit',
        'current_stock_quantity',
        'low_stock_threshold',
        'is_active',
    ];

    protected $appends = [
        'file_url',
    ];

    protected $casts = [
        'uuid' => 'string',
        'file_size' => 'integer',
        'current_stock_quantity' => 'decimal:3',
        'low_stock_threshold' => 'decimal:3',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function stockUnits(): array
    {
        return [
            self::UNIT_GRAM,
            self::UNIT_MILLILITER,
            self::UNIT_PIECE,
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function dishes(): BelongsToMany
    {
        return $this->belongsToMany(Dish::class, 'dish_ingredients')
            ->withPivot(['quantity', 'unit'])
            ->withTimestamps();
    }

    public function dishIngredients(): HasMany
    {
        return $this->hasMany(DishIngredient::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function orderItemUsages(): HasMany
    {
        return $this->hasMany(OrderItemIngredientUsage::class);
    }

    public function getFileUrlAttribute(): ?string
    {
        if (! $this->file_path) {
            return null;
        }

        $disk = $this->storage_disk ?: 'public';

        return Storage::disk($disk)->url($this->file_path);
    }
}
