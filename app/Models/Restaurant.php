<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Restaurant extends Model
{
    protected $fillable = [
        'uuid',
        'user_id',
        'name',
        'slug',
        'description',
        'address',
        'currency',
        'dollar_rate',
    ];

    protected $casts = [
        'uuid' => 'string',
        'created_at' => 'datetime',
        'dollar_rate' => 'decimal:2',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::created(function (Restaurant $restaurant) {
            $restaurant->ensureDefaultTables();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dishes(): HasMany
    {
        return $this->hasMany(Dish::class);
    }

    public function staffUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withTimestamps();
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(Ingredient::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function tables(): HasMany
    {
        return $this->hasMany(RestaurantTable::class);
    }

    public function tableWaves(): HasMany
    {
        return $this->hasMany(TableWave::class);
    }

    public function tableSessions(): HasMany
    {
        return $this->hasMany(TableSession::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(RestaurantDomain::class);
    }

    public function ensureDefaultTables(): void
    {
        $existingTableNames = $this->tables()
            ->pluck('name')
            ->all();

        $missingTableNames = array_values(array_diff(self::defaultTableNames(), $existingTableNames));

        if ($missingTableNames === []) {
            return;
        }

        $this->tables()->createMany(array_map(
            fn (string $tableName) => ['name' => $tableName],
            $missingTableNames
        ));
    }

    public static function defaultTableNames(): array
    {
        return array_map(
            fn (int $number) => sprintf('T%02d', $number),
            range(1, 10)
        );
    }
}
