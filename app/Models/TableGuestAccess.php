<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TableGuestAccess extends Model
{
    protected $fillable = [
        'table_session_id',
        'token_hash',
        'device_fingerprint',
        'joined_at',
        'last_seen_at',
        'expires_at',
        'revoked_at',
        'revoke_reason',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tableSession(): BelongsTo
    {
        return $this->belongsTo(TableSession::class);
    }
}
