<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'name',
        'quantity',
        'unit_price',
        'line_total',
        'order_index',
        'order_item_id',
        'status',
        'compensation_type',
        'compensation_reason',
        'complaint_category',
        'operational_loss_category',
        'adjustment_action_type',
        'compensation_note',
        'approved_by_staff_name',
        'approved_by_staff_role',
        'approved_at',
        'original_unit_price',
        'final_unit_price',
        'original_line_total',
        'partial_discount_percentage',
        'partial_discount_type',
        'partial_discount_value',
        'is_complimentary',
        'accounting_bucket',
        'customer_satisfaction_rating',
        'evidence_photo_url',
    ];

    protected $casts = [
        'order_item_id' => 'integer',
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
        'original_unit_price' => 'decimal:2',
        'final_unit_price' => 'decimal:2',
        'original_line_total' => 'decimal:2',
        'partial_discount_percentage' => 'decimal:2',
        'partial_discount_value' => 'decimal:2',
        'is_complimentary' => 'boolean',
        'customer_satisfaction_rating' => 'integer',
        'approved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
