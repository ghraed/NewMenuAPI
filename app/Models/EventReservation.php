<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventReservation extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_COMPLETED = 'completed';

    public const NOTIFICATION_IMMEDIATE_UPDATE = 'immediate_update';
    public const NOTIFICATION_T_MINUS_1D = 't_minus_1d';

    /**
     * @return array<int, string>
     */
    public static function supportedStatuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_CONFIRMED,
            self::STATUS_CANCELLED,
            self::STATUS_COMPLETED,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function blockingStatuses(): array
    {
        return [
            self::STATUS_CONFIRMED,
        ];
    }

    protected $fillable = [
        'restaurant_id',
        'room_plan_id',
        'invoice_id',
        'title',
        'customer_name',
        'customer_phone',
        'customer_email',
        'start_at',
        'end_at',
        'status',
        'notes',
    ];

    protected $casts = [
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

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function menuItems(): HasMany
    {
        return $this->hasMany(EventMenuItem::class);
    }

    public function notificationLogs(): HasMany
    {
        return $this->hasMany(EventNotificationLog::class);
    }

    public function orderLinks(): HasMany
    {
        return $this->hasMany(EventOrderLink::class);
    }
}

