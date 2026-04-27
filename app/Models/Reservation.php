<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Reservation extends Model
{
    use SoftDeletes;

    public const STATUS_RESERVED = 'reserved';
    public const STATUS_BUSY = 'busy';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_NO_SHOW = 'no_show';

    /**
     * @return array<int, string>
     */
    public static function supportedStatuses(): array
    {
        return [
            self::STATUS_RESERVED,
            self::STATUS_BUSY,
            self::STATUS_CANCELLED,
            self::STATUS_COMPLETED,
            self::STATUS_NO_SHOW,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function blockingStatuses(): array
    {
        return [
            self::STATUS_RESERVED,
            self::STATUS_BUSY,
        ];
    }

    protected $fillable = [
        'restaurant_id',
        'room_plan_id',
        'room_plan_item_id',
        'customer_name',
        'customer_phone',
        'customer_email',
        'reservation_date',
        'start_time',
        'end_time',
        'start_at',
        'end_at',
        'status',
        'notes',
    ];

    protected $casts = [
        'reservation_date' => 'date',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function roomPlan(): BelongsTo
    {
        return $this->belongsTo(RoomPlan::class);
    }

    public function roomPlanItem(): BelongsTo
    {
        return $this->belongsTo(RoomPlanItem::class);
    }
}
