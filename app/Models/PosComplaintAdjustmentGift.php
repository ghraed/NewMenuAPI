<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosComplaintAdjustmentGift extends Model
{
    use HasFactory;
    protected $fillable = ['pos_complaint_adjustment_id', 'dish_id', 'dish_name_snapshot', 'quantity', 'unit_value', 'line_value'];
    protected $casts = ['quantity' => 'decimal:3', 'unit_value' => 'decimal:2', 'line_value' => 'decimal:2'];
    public function adjustment(): BelongsTo { return $this->belongsTo(PosComplaintAdjustment::class, 'pos_complaint_adjustment_id'); }
    public function dish(): BelongsTo { return $this->belongsTo(Dish::class)->withTrashed(); }
}
