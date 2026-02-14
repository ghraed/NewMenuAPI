<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsEvent extends Model
{
    protected $fillable = [
        'uuid',
        'dish_id',
        'restaurant_id',
        'event_type',
        'device_type',
        'platform',
        'user_agent',
        'ip_address',
    ];

    protected $casts = [
        'uuid' => 'string',
        'created_at' => 'datetime',
    ];

    protected $table = 'analytics_events';

    public function dish(): BelongsTo
    {
        return $this->belongsTo(Dish::class);
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
