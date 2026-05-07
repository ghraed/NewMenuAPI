<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PayrollPeriod extends Model
{
    public const TYPE_REGULAR = 'regular';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'restaurant_id',
        'employee_id',
        'period_start',
        'period_end',
        'period_type',
        'adjustment_of_period_id',
        'status',
        'approved_at',
        'paid_at',
        'processed_by',
        'notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'adjustment_of_period_id' => 'integer',
        'employee_id' => 'integer',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(PayrollEntry::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function mirroredExpense(): HasOne
    {
        return $this->hasOne(Expense::class, 'payroll_period_id');
    }

    public function adjustmentOfPeriod(): BelongsTo
    {
        return $this->belongsTo(self::class, 'adjustment_of_period_id');
    }

    public function adjustmentPeriods(): HasMany
    {
        return $this->hasMany(self::class, 'adjustment_of_period_id');
    }
}
