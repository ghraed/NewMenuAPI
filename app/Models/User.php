<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_STAFF = 'staff';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'role',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function restaurant(): HasOne
    {
        return $this->hasOne(Restaurant::class);
    }

    public function staffRestaurants(): BelongsToMany
    {
        return $this->belongsToMany(Restaurant::class)
            ->withTimestamps();
    }

    public function assignedTables(): BelongsToMany
    {
        return $this->belongsToMany(RestaurantTable::class)
            ->withTimestamps();
    }

    public function resolvedTableWaves(): HasMany
    {
        return $this->hasMany(TableWave::class, 'resolved_by');
    }

    public function currentRestaurant(): ?Restaurant
    {
        if ($this->relationLoaded('restaurant') && $this->restaurant) {
            return $this->restaurant;
        }

        $ownedRestaurant = $this->restaurant()->first();
        if ($ownedRestaurant) {
            return $ownedRestaurant;
        }

        if ($this->relationLoaded('staffRestaurants')) {
            return $this->staffRestaurants->first();
        }

        return $this->staffRestaurants()->first();
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role ?? self::ROLE_ADMIN, $roles, true);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(self::ROLE_ADMIN);
    }

    public function isStaff(): bool
    {
        return $this->hasRole(self::ROLE_STAFF);
    }

    public function hasTableAssignmentFor(int $tableId): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        if ($this->relationLoaded('assignedTables')) {
            return $this->assignedTables->contains('id', $tableId);
        }

        return $this->assignedTables()
            ->where('restaurant_tables.id', $tableId)
            ->exists();
    }
}
