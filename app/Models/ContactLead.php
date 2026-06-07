<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactLead extends Model
{
    protected $fillable = [
        'chat_session_id',
        'name',
        'email',
        'phone',
        'business_type',
        'preferred_contact_method',
        'message',
        'conversation_summary',
        'source_page',
        'status',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function chatSession(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class);
    }
}
