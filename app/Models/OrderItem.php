<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'dish_id',
        'dish_name',
        'unit_price',
        'quantity',
        'line_subtotal',
        'status',
        'compensation_type',
        'compensation_reason',
        'complaint_category',
        'operational_loss_category',
        'adjustment_action_type',
        'compensation_note',
        'approved_by_staff_id',
        'approved_by_staff_name',
        'approved_by_staff_role',
        'approved_at',
        'original_unit_price',
        'final_unit_price',
        'partial_discount_percentage',
        'partial_discount_type',
        'partial_discount_value',
        'is_complimentary',
        'accounting_bucket',
        'customer_satisfaction_rating',
        'evidence_photo_url',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'line_subtotal' => 'decimal:2',
        'original_unit_price' => 'decimal:2',
        'final_unit_price' => 'decimal:2',
        'partial_discount_percentage' => 'decimal:2',
        'partial_discount_value' => 'decimal:2',
        'is_complimentary' => 'boolean',
        'customer_satisfaction_rating' => 'integer',
        'approved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function dish(): BelongsTo
    {
        return $this->belongsTo(Dish::class)->withTrashed();
    }

    public function ingredientUsages(): HasMany
    {
        return $this->hasMany(OrderItemIngredientUsage::class);
    }
}
