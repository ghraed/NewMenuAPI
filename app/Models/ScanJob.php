<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScanJob extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'scan_jobs';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'scan_id',
        'type',
        'status',
        'progress',
        'message',
        'meta',
    ];

    protected $casts = [
        'progress' => 'float',
        'meta' => 'array',
    ];

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }
}
