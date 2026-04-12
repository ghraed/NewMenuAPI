<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Ingredient extends Model
{
    protected $fillable = [
        'uuid',
        'restaurant_id',
        'name',
        'name_ar',
        'storage_disk',
        'file_path',
        'source_file_name',
        'file_size',
        'mime_type',
    ];

    protected $appends = [
        'file_url',
    ];

    protected $casts = [
        'uuid' => 'string',
        'file_size' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function getFileUrlAttribute(): ?string
    {
        if (! $this->file_path) {
            return null;
        }

        $disk = $this->storage_disk ?: 'public';

        return Storage::disk($disk)->url($this->file_path);
    }
}
