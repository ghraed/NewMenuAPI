<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventOrderLink extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'event_reservation_id',
        'order_id',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function eventReservation(): BelongsTo
    {
        return $this->belongsTo(EventReservation::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

