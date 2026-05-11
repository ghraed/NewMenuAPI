<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventMenuItem extends Model
{
    protected $fillable = [
        'event_reservation_id',
        'dish_id',
        'planned_quantity',
        'prep_notes',
        'dish_name_snapshot',
        'category_snapshot',
    ];

    protected $casts = [
        'planned_quantity' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function eventReservation(): BelongsTo
    {
        return $this->belongsTo(EventReservation::class);
    }

    public function dish(): BelongsTo
    {
        return $this->belongsTo(Dish::class);
    }
}

