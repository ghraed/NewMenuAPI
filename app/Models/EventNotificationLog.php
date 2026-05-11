<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventNotificationLog extends Model
{
    protected $fillable = [
        'event_reservation_id',
        'notification_type',
        'channel',
        'sent_to_role',
        'dedupe_key',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function eventReservation(): BelongsTo
    {
        return $this->belongsTo(EventReservation::class);
    }
}

