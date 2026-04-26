<?php

namespace App\Services;

use App\Models\Feature;
use App\Models\Restaurant;
use App\Models\RestaurantDomain;
use App\Models\RestaurantFeature;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class TenantRestaurantResolver
{
    private const LOCAL_HOSTS = [
        'localhost',
        '127.0.0.1',
        '::1',
    ];

    public function resolveFromSlugOrHost(?string $restaurantSlug = null, ?Request $request = null): Restaurant
    {
        $normalizedSlug = $this->normalizeSlug($restaurantSlug);

        if ($normalizedSlug !== null) {
            return Restaurant::query()
                ->where('slug', $normalizedSlug)
                ->firstOrFail();
        }

        if ($request !== null) {
            $restaurantFromHost = $this->resolveFromRequestHost($request);
            if ($restaurantFromHost) {
                return $restaurantFromHost;
            }

            if (! $this->isLocalHost($request->getHost())) {
                throw (new ModelNotFoundException())->setModel(Restaurant::class);
            }
        }

        return $this->resolveLocalFallback();
    }

    private function resolveFromRequestHost(Request $request): ?Restaurant
    {
        $host = $this->normalizeDomain($request->getHost());

        if ($host === '') {
            return null;
        }

        $domain = RestaurantDomain::query()
            ->where('domain', $host)
            ->with('restaurant')
            ->first();

        if (! $domain?->restaurant) {
            return null;
        }

        if (! $this->isCustomDomainEnabledForRestaurant((int) $domain->restaurant->id)) {
            return null;
        }

        return $domain->restaurant;
    }

    private function resolveLocalFallback(): Restaurant
    {
        $configuredSlug = trim((string) config('app.guest_restaurant_slug', ''));

        $query = Restaurant::query();

        if ($configuredSlug !== '') {
            $configuredRestaurant = $query->where('slug', $configuredSlug)->first();
            if ($configuredRestaurant) {
                return $configuredRestaurant;
            }
        }

        $restaurant = Restaurant::query()->orderBy('id')->first();

        if (! $restaurant) {
            throw (new ModelNotFoundException())->setModel(Restaurant::class);
        }

        return $restaurant;
    }

    private function normalizeSlug(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeDomain(string $domain): string
    {
        $normalized = strtolower(trim($domain));

        return rtrim($normalized, '.');
    }

    private function isLocalHost(string $host): bool
    {
        return in_array($this->normalizeDomain($host), self::LOCAL_HOSTS, true);
    }

    private function isCustomDomainEnabledForRestaurant(int $restaurantId): bool
    {
        $feature = Feature::query()
            ->select(['id', 'is_active_by_default'])
            ->where('key', 'custom_domain')
            ->first();

        if (! $feature) {
            return false;
        }

        $override = RestaurantFeature::query()
            ->select(['enabled'])
            ->where('restaurant_id', $restaurantId)
            ->where('feature_id', $feature->id)
            ->first();

        if ($override) {
            return (bool) $override->enabled;
        }

        return (bool) $feature->is_active_by_default;
    }
}
