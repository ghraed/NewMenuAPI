<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QRCode extends Model
{
    protected $fillable = [
        'uuid',
        'dish_id',
        'code_url',
        'qr_data',
    ];

    protected $casts = [
        'uuid' => 'string',
        'qr_data' => 'array', // If you store QR as base64 or array data
        'created_at' => 'datetime',
    ];

    protected $table = 'qr_codes';

    public function dish(): BelongsTo
    {
        return $this->belongsTo(Dish::class);
    }
}
