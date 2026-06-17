<?php

namespace App\Services;

use App\Models\Feature;
use App\Models\Restaurant;
use App\Models\RestaurantDomain;
use App\Models\RestaurantFeature;
use App\Support\DomainName;
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
            $restaurantFromLocalSubdomain = $this->resolveFromLocalSubdomain($request->getHost());
            if ($restaurantFromLocalSubdomain) {
                return $restaurantFromLocalSubdomain;
            }

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

        $hostWithoutWww = DomainName::stripWww($host);

        $restaurant = Restaurant::query()
            ->where(function ($query) use ($host, $hostWithoutWww): void {
                $query->where('custom_domain', $host);

                if ($hostWithoutWww !== '' && $hostWithoutWww !== $host) {
                    $query->orWhere('custom_domain', $hostWithoutWww);
                }
            })
            ->first();

        if ($restaurant) {
            if (! $this->isCustomDomainEnabledForRestaurant((int) $restaurant->id)) {
                return null;
            }

            return $restaurant;
        }

        $domain = RestaurantDomain::query()
            ->where(function ($query) use ($host, $hostWithoutWww): void {
                $query->where('domain', $host);

                if ($hostWithoutWww !== '' && $hostWithoutWww !== $host) {
                    $query->orWhere('domain', $hostWithoutWww);
                }
            })
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
        return DomainName::normalizeHost($domain);
    }

    private function isLocalHost(string $host): bool
    {
        $normalized = $this->normalizeDomain($host);

        if (in_array($normalized, self::LOCAL_HOSTS, true)) {
            return true;
        }

        return str_ends_with($normalized, '.localhost');
    }

    private function resolveFromLocalSubdomain(string $host): ?Restaurant
    {
        $normalized = $this->normalizeDomain($host);

        if (! str_ends_with($normalized, '.localhost')) {
            return null;
        }

        $slug = substr($normalized, 0, -strlen('.localhost'));
        $slug = $this->normalizeSlug($slug);

        if ($slug === null) {
            return null;
        }

        return Restaurant::query()
            ->where('slug', $slug)
            ->first();
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
