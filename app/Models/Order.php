<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    public const STATUS_PENDING_STAFF_CONFIRMATION = 'pending_staff_confirmation';
    public const STATUS_STAFF_CONFIRMED = 'staff_confirmed';
    public const STATUS_STAFF_CANCELLED = 'staff_cancelled';
    public const STATUS_ACCOUNTED = 'accounted';

    protected $fillable = [
        'uuid',
        'restaurant_id',
        'restaurant_table_id',
        'order_number',
        'invoice_number',
        'status',
        'guest_name',
        'guest_phone',
        'guest_email',
        'table_reference',
        'notes',
        'vat_rate',
        'subtotal',
        'discount_type',
        'discount_value',
        'discount_amount',
        'taxable_subtotal',
        'vat_amount',
        'total',
        'confirmed_by',
        'confirmed_at',
        'cancelled_by',
        'cancelled_at',
        'accounted_by',
        'accounted_at',
    ];

    protected $casts = [
        'vat_rate' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'taxable_subtotal' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'accounted_at' => 'datetime',
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

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function accountedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accounted_by');
    }
}
