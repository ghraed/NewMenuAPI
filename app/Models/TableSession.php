<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TableSession extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'uuid',
        'restaurant_id',
        'restaurant_table_id',
        'table_number',
        'status',
        'pin_hash',
        'pin_attempts',
        'pin_locked_until',
        'opened_at',
        'last_activity_at',
        'expires_at',
        'closed_at',
        'close_reason',
        'created_by_staff_id',
        'finalized_by_staff_id',
    ];

    protected $casts = [
        'table_number' => 'integer',
        'pin_attempts' => 'integer',
        'pin_locked_until' => 'datetime',
        'opened_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'expires_at' => 'datetime',
        'closed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function restaurantTable(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function guestAccesses(): HasMany
    {
        return $this->hasMany(TableGuestAccess::class);
    }

    public function waves(): HasMany
    {
        return $this->hasMany(TableWave::class);
    }

    public function createdByStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_staff_id');
    }

    public function finalizedByStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by_staff_id');
    }
}
