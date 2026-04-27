<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RoomPlanItem extends Model
{
    use SoftDeletes;

    public const TYPE_TABLE = 'table';
    public const TYPE_WINDOW = 'window';
    public const TYPE_COUNTER = 'counter';
    public const TYPE_BAR = 'bar';
    public const TYPE_KITCHEN = 'kitchen';
    public const TYPE_CASHIER = 'cashier';
    public const TYPE_FRIDGE = 'fridge';
    public const TYPE_SOFA = 'sofa';
    public const TYPE_PLANT = 'plant';
    public const TYPE_WC = 'wc';

    public const CONTAINER_ROOM = 'room';
    public const CONTAINER_WRAPPER = 'wrapper';

    /**
     * @return array<int, string>
     */
    public static function supportedTypes(): array
    {
        return [
            self::TYPE_TABLE,
            self::TYPE_WINDOW,
            self::TYPE_COUNTER,
            self::TYPE_BAR,
            self::TYPE_KITCHEN,
            self::TYPE_CASHIER,
            self::TYPE_FRIDGE,
            self::TYPE_SOFA,
            self::TYPE_PLANT,
            self::TYPE_WC,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function supportedContainers(): array
    {
        return [self::CONTAINER_ROOM, self::CONTAINER_WRAPPER];
    }

    protected $fillable = [
        'room_plan_id',
        'restaurant_table_id',
        'type',
        'label',
        'x',
        'y',
        'width',
        'height',
        'rotation',
        'seats',
        'z_index',
        'container',
        'is_active',
    ];

    protected $casts = [
        'x' => 'float',
        'y' => 'float',
        'width' => 'float',
        'height' => 'float',
        'rotation' => 'float',
        'seats' => 'integer',
        'z_index' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function roomPlan(): BelongsTo
    {
        return $this->belongsTo(RoomPlan::class);
    }

    public function restaurantTable(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function isTable(): bool
    {
        return $this->type === self::TYPE_TABLE;
    }
}
