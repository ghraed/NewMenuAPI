<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PAID = 'paid';
    public const STATUS_VOID = 'void';

    protected $fillable = [
        'uuid',
        'restaurant_id',
        'expense_category_id',
        'vendor_id',
        'expense_date',
        'amount_cents',
        'tax_amount_cents',
        'currency',
        'status',
        'payment_method',
        'reference_no',
        'description',
        'notes',
        'due_date',
        'paid_at',
        'created_by',
        'approved_by',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount_cents' => 'integer',
        'tax_amount_cents' => 'integer',
        'due_date' => 'date',
        'paid_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $appends = [
        'total_cents',
    ];

    public function getTotalCentsAttribute(): int
    {
        return (int) ($this->amount_cents ?? 0) + (int) ($this->tax_amount_cents ?? 0);
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ExpenseAttachment::class);
    }

    public function linkedStockMovement(): HasOne
    {
        return $this->hasOne(StockMovement::class, 'linked_expense_id');
    }
}
