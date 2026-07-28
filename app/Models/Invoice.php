<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ISSUED = 'issued';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'uuid',
        'restaurant_id',
        'invoice_number',
        'invoice_date',
        'status',
        'subtotal',
        'discount_type',
        'discount_value',
        'discount_amount',
        'taxable_subtotal',
        'service_charge_rate',
        'service_charge_amount',
        'vat_rate',
        'vat_amount',
        'total',
        'currency',
        'exchange_rate',
        'payment_method',
        'payment_reference',
        'pdf_disk',
        'pdf_path',
        'pdf_generated_at',
        'notes',
        'paid_at',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'subtotal' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'taxable_subtotal' => 'decimal:2',
        'service_charge_rate' => 'decimal:2',
        'service_charge_amount' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'exchange_rate' => 'decimal:4',
        'paid_at' => 'datetime',
        'pdf_generated_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }
}
