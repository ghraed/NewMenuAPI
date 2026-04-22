<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantDomain extends Model
{
    protected $fillable = [
        'restaurant_id',
        'domain',
        'kind',
        'is_primary',
        'verified_at',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'verified_at' => 'datetime',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function setDomainAttribute(string $value): void
    {
        $normalized = strtolower(trim($value));
        $normalized = rtrim($normalized, '.');

        $this->attributes['domain'] = $normalized;
    }
}
