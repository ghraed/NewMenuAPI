<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatOrder extends Model
{
    protected $fillable = [
        'items',
        'status',
        'user_session_id',
    ];

    protected $casts = [
        'items' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
