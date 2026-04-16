<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Models\TableGuestAccess;
use App\Models\TableSession;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class GuestMenuSessionService
{
    private const PIN_CACHE_PREFIX = 'table-session-pin:';

    public function resolveGuestRestaurant(): Restaurant
    {
        $configuredSlug = config('app.guest_restaurant_slug');

        $query = Restaurant::query()->with(['tables' => fn ($builder) => $builder->orderBy('name')]);

        $restaurant = $configuredSlug
            ? $query->where('slug', $configuredSlug)->first()
            : $query->first();

        if (! $restaurant) {
            throw (new ModelNotFoundException())->setModel(Restaurant::class);
        }

        return $restaurant;
    }

    public function resolveTableContext(int $tableNumber): array
    {
        $restaurant = $this->resolveGuestRestaurant();
        $table = $this->resolveTableByNumber($restaurant, $tableNumber);
        $session = $this->getOrCreateActiveSession($restaurant, $table, $tableNumber);

        return [
            'restaurant' => $restaurant,
            'table' => $table,
            'table_number' => $tableNumber,
            'session' => $session,
        ];
    }

    public function resolveTableByNumber(Restaurant $restaurant, int $tableNumber): RestaurantTable
    {
        if ($tableNumber <= 0) {
            throw new ModelNotFoundException();
        }

        $tables = $restaurant->relationLoaded('tables')
            ? $restaurant->tables
            : $restaurant->tables()->orderBy('name')->get();

        $maxTables = $tables->count();

        if ($tableNumber > $maxTables) {
            throw new ModelNotFoundException();
        }

        $table = $this->matchTableByNumber($tables, $tableNumber);

        if (! $table) {
            throw new ModelNotFoundException();
        }

        return $table;
    }

    public function getOrCreateActiveSession(
        Restaurant $restaurant,
        RestaurantTable $table,
        int $tableNumber
    ): TableSession {
        $now = now();

        return DB::transaction(function () use ($restaurant, $table, $tableNumber, $now) {
            $lockedTable = RestaurantTable::query()
                ->whereKey($table->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedTable) {
                throw new ModelNotFoundException();
            }

            $this->expireActiveSessionsForRestaurant($restaurant->id, $now);

            $session = TableSession::query()
                ->where('restaurant_id', $restaurant->id)
                ->where('restaurant_table_id', $lockedTable->id)
                ->where('status', TableSession::STATUS_ACTIVE)
                ->where(function ($query) use ($now) {
                    $query->whereNull('expires_at')
                        ->orWhere('expires_at', '>', $now);
                })
                ->with('restaurantTable')
                ->lockForUpdate()
                ->latest('opened_at')
                ->latest('id')
                ->first();

            if ($session) {
                if (! $session->pin_hash) {
                    $pin = $this->generatePin();

                    $session->update([
                        'pin_hash' => Hash::make($pin),
                        'pin_attempts' => 0,
                        'pin_locked_until' => null,
                    ]);

                    $this->rememberPlainPin($session, $pin);
                }

                return $session;
            }

            $pin = $this->generatePin();

            return tap(TableSession::query()->create([
                'uuid' => (string) Str::uuid(),
                'restaurant_id' => $restaurant->id,
                'restaurant_table_id' => $lockedTable->id,
                'table_number' => $tableNumber,
                'status' => TableSession::STATUS_ACTIVE,
                'pin_hash' => Hash::make($pin),
                'pin_attempts' => 0,
                'opened_at' => $now,
                'last_activity_at' => $now,
                'expires_at' => null,
            ]), function (TableSession $createdSession) use ($pin): void {
                $this->rememberPlainPin($createdSession, $pin);
            })->fresh(['restaurantTable']);
        });
    }

    public function resolveActiveSession(int $sessionId): TableSession
    {
        $session = TableSession::query()
            ->with(['restaurant.tables' => fn ($builder) => $builder->orderBy('name'), 'restaurantTable'])
            ->findOrFail($sessionId);

        if ($this->expireSessionIfNeeded($session) || $session->status !== TableSession::STATUS_ACTIVE) {
            throw new ModelNotFoundException();
        }

        return $session;
    }

    public function formatRestaurant(Restaurant $restaurant): array
    {
        $tables = $restaurant->relationLoaded('tables')
            ? $restaurant->tables
            : $restaurant->tables()->orderBy('name')->get();

        return [
            'id' => $restaurant->id,
            'name' => $restaurant->name,
            'slug' => $restaurant->slug,
            'max_tables' => $tables->count(),
        ];
    }

    public function formatTable(RestaurantTable $table, int $tableNumber): array
    {
        return [
            'id' => $table->id,
            'number' => $tableNumber,
            'name' => $table->name,
        ];
    }

    public function formatSession(TableSession $session): array
    {
        return [
            'id' => $session->id,
            'uuid' => $session->uuid,
            'status' => $session->status,
            'table_id' => $session->table_number,
            'table_reference' => $session->restaurantTable?->name,
            'opened_at' => $session->opened_at?->toIso8601String(),
            'last_activity_at' => $session->last_activity_at?->toIso8601String(),
            'expires_at' => $session->expires_at?->toIso8601String(),
            'closed_at' => $session->closed_at?->toIso8601String(),
            'close_reason' => $session->close_reason,
            'pin_locked_until' => $session->pin_locked_until?->toIso8601String(),
        ];
    }

    public function formatGuestAccess(?TableGuestAccess $guestAccess): array
    {
        return [
            'verified' => $guestAccess !== null,
            'joined_at' => $guestAccess?->joined_at?->toIso8601String(),
            'last_seen_at' => $guestAccess?->last_seen_at?->toIso8601String(),
            'expires_at' => $guestAccess?->expires_at?->toIso8601String(),
        ];
    }

    public function currentPlainPin(TableSession $session): ?string
    {
        return Cache::get($this->pinCacheKey($session->id));
    }

    public function rememberPlainPin(TableSession $session, string $pin): void
    {
        if (! $session->expires_at) {
            Cache::forever($this->pinCacheKey($session->id), $pin);
            return;
        }

        Cache::put($this->pinCacheKey($session->id), $pin, $session->expires_at);
    }

    public function forgetPlainPin(TableSession $session): void
    {
        Cache::forget($this->pinCacheKey($session->id));
    }

    public function expireSessionIfNeeded(TableSession $session, string $reason = 'expired'): bool
    {
        if ($session->status !== TableSession::STATUS_ACTIVE) {
            return false;
        }

        if (! $session->expires_at || $session->expires_at->isFuture()) {
            return false;
        }

        DB::transaction(function () use ($session, $reason) {
            $lockedSession = TableSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedSession || $lockedSession->status !== TableSession::STATUS_ACTIVE) {
                return;
            }

            if (! $lockedSession->expires_at || $lockedSession->expires_at->isFuture()) {
                return;
            }

            $now = now();

            $lockedSession->update([
                'status' => TableSession::STATUS_EXPIRED,
                'pin_hash' => null,
                'pin_attempts' => 0,
                'pin_locked_until' => null,
                'closed_at' => $lockedSession->closed_at ?: $now,
                'close_reason' => $reason,
            ]);

            $lockedSession->guestAccesses()
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => $now,
                    'revoke_reason' => $reason,
                ]);

            $this->forgetPlainPin($lockedSession);
            $session->refresh();
        });

        return true;
    }

    public function generatePin(): string
    {
        return str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    private function expireActiveSessionsForRestaurant(int $restaurantId, $now): void
    {
        $expiredSessionIds = TableSession::query()
            ->where('restaurant_id', $restaurantId)
            ->where('status', TableSession::STATUS_ACTIVE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->lockForUpdate()
            ->pluck('id');

        if ($expiredSessionIds->isEmpty()) {
            return;
        }

        TableSession::query()
            ->whereIn('id', $expiredSessionIds)
            ->update([
                'status' => TableSession::STATUS_EXPIRED,
                'pin_hash' => null,
                'pin_attempts' => 0,
                'pin_locked_until' => null,
                'closed_at' => $now,
                'close_reason' => 'expired',
            ]);

        TableGuestAccess::query()
            ->whereIn('table_session_id', $expiredSessionIds)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => $now,
                'revoke_reason' => 'expired',
            ]);

        foreach ($expiredSessionIds as $expiredSessionId) {
            Cache::forget($this->pinCacheKey((int) $expiredSessionId));
        }
    }

    private function matchTableByNumber(Collection $tables, int $tableNumber): ?RestaurantTable
    {
        $table = $tables->first(function (RestaurantTable $table) use ($tableNumber) {
            return $this->extractTableNumber($table->name) === $tableNumber;
        });

        if ($table) {
            return $table;
        }

        $index = $tableNumber - 1;

        return $tables->values()->get($index);
    }

    private function extractTableNumber(string $name): ?int
    {
        if (! preg_match('/(\d+)/', $name, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    private function pinCacheKey(int $sessionId): string
    {
        return self::PIN_CACHE_PREFIX.$sessionId;
    }
}
