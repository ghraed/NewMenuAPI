<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Restaurant extends Model
{
    protected $fillable = [
        'uuid',
        'user_id',
        'name',
        'slug',
        'status',
        'description',
        'address',
        'currency',
        'other_currency',
        'dollar_rate',
        'logo_path',
        'profile',
        'manual_table_count',
    ];

    protected $appends = ['logo_url'];

    protected $casts = [
        'uuid' => 'string',
        'status' => 'string',
        'profile' => 'array',
        'created_at' => 'datetime',
        'dollar_rate' => 'decimal:2',
        'manual_table_count' => 'integer',
        'updated_at' => 'datetime',
    ];

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

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function expenseCategories(): HasMany
    {
        return $this->hasMany(ExpenseCategory::class);
    }

    public function vendors(): HasMany
    {
        return $this->hasMany(Vendor::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function staffShifts(): HasMany
    {
        return $this->hasMany(StaffShift::class);
    }

    public function payrollPeriods(): HasMany
    {
        return $this->hasMany(PayrollPeriod::class);
    }

    public function payrollEntries(): HasMany
    {
        return $this->hasMany(PayrollEntry::class);
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

    public function roomPlans(): HasMany
    {
        return $this->hasMany(RoomPlan::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function eventReservations(): HasMany
    {
        return $this->hasMany(EventReservation::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(RestaurantDomain::class);
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'restaurant_features')
            ->withPivot('enabled')
            ->withTimestamps();
    }

    public function restaurantFeatures(): HasMany
    {
        return $this->hasMany(RestaurantFeature::class);
    }

    public function featureFlagAuditLogs(): HasMany
    {
        return $this->hasMany(FeatureFlagAuditLog::class);
    }

    public function getLogoUrlAttribute(): ?string
    {
        $path = $this->logo_path;

        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }

}
