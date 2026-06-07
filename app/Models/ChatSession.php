<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ChatSession extends Model
{
    protected $fillable = [
        'uuid',
        'visitor_name',
        'visitor_email',
        'visitor_phone',
        'business_type',
        'preferred_contact_method',
        'status',
        'source_page',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function contactLead(): HasOne
    {
        return $this->hasOne(ContactLead::class);
    }
}
