<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollEntry extends Model
{
    protected $fillable = [
        'payroll_period_id',
        'restaurant_id',
        'user_id',
        'base_amount_cents',
        'overtime_amount_cents',
        'bonus_amount_cents',
        'deduction_amount_cents',
        'tax_amount_cents',
        'net_amount_cents',
        'currency',
        'notes',
    ];

    protected $casts = [
        'base_amount_cents' => 'integer',
        'overtime_amount_cents' => 'integer',
        'bonus_amount_cents' => 'integer',
        'deduction_amount_cents' => 'integer',
        'tax_amount_cents' => 'integer',
        'net_amount_cents' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class);
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
