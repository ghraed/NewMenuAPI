<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    public const TYPE_OPENING_BALANCE = 'opening_balance';
    public const TYPE_RESTOCK = 'restock';
    public const TYPE_MANUAL_ADJUSTMENT = 'manual_adjustment';
    public const TYPE_ORDER_CONSUMPTION = 'order_consumption';
    public const TYPE_CANCELLATION_RESTORE = 'order_restoration';
    public const TYPE_ORDER_RESTORATION = self::TYPE_CANCELLATION_RESTORE;

    protected $fillable = [
        'restaurant_id',
        'ingredient_id',
        'order_id',
        'order_item_id',
        'performed_by',
        'movement_type',
        'unit',
        'quantity_delta',
        'quantity_before',
        'quantity_after',
        'ingredient_name_snapshot',
        'reference',
        'notes',
        'occurred_at',
    ];

    protected $casts = [
        'quantity_delta' => 'decimal:3',
        'quantity_before' => 'decimal:3',
        'quantity_after' => 'decimal:3',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
