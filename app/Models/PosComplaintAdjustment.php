<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosComplaintAdjustment extends Model
{
    use HasFactory;

    protected $fillable = [
        'restaurant_id', 'original_order_id', 'original_invoice_id', 'original_invoice_number', 'status',
        'complaint_reason', 'complaint_category', 'complaint_note', 'accounting_bucket', 'refund_amount',
        'refund_payment_method', 'affected_items', 'created_by', 'approved_by', 'approved_at', 'posted_at',
        'voided_at', 'gift_order_id',
    ];

    protected $casts = [
        'refund_amount' => 'decimal:2', 'affected_items' => 'array', 'approved_at' => 'datetime',
        'posted_at' => 'datetime', 'voided_at' => 'datetime',
    ];

    public function originalOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'original_order_id');
    }

    public function originalInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'original_invoice_id');
    }

    public function gifts(): HasMany
    {
        return $this->hasMany(PosComplaintAdjustmentGift::class);
    }
}
