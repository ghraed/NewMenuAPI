<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaasOwner extends Model
{
    protected $table = 'saas_owners';

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
