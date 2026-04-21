<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class GlobalIngredient extends Model
{
    protected $fillable = [
        'uuid',
        'name',
        'name_ar',
        'normalized_name',
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

    public function ingredients(): HasMany
    {
        return $this->hasMany(Ingredient::class);
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
