<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Models\RestaurantDomain;
use App\Support\DomainName;
use Illuminate\Validation\ValidationException;

class RestaurantCustomDomainService
{
    public function normalize(mixed $value): ?string
    {
        return DomainName::normalize($value);
    }

    public function validateOrFail(mixed $value, ?Restaurant $ignoreRestaurant = null): ?string
    {
        $normalized = $this->normalize($value);

        if ($normalized === null) {
            return null;
        }

        $errors = [];

        if (! DomainName::isValidCustomDomain($value)) {
            $errors[] = 'Enter a valid custom domain without protocol, wildcard, IP address, localhost, or port.';
        }

        $restaurantConflict = Restaurant::query()
            ->where('custom_domain', $normalized)
            ->when($ignoreRestaurant, fn ($query) => $query->whereKeyNot($ignoreRestaurant->id))
            ->exists();

        if ($restaurantConflict) {
            $errors[] = 'This custom domain is already assigned to another restaurant.';
        }

        $domainConflict = RestaurantDomain::query()
            ->where('domain', $normalized)
            ->when($ignoreRestaurant, fn ($query) => $query->where('restaurant_id', '!=', $ignoreRestaurant->id))
            ->exists();

        if ($domainConflict) {
            $errors[] = 'This custom domain is already in use.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages([
                'custom_domain' => $errors,
            ]);
        }

        return $normalized;
    }

    public function syncCustomDomain(Restaurant $restaurant, ?string $domain = null): void
    {
        $normalized = $this->normalize($domain ?? $restaurant->custom_domain);

        $customDomains = $restaurant->domains()
            ->where('kind', 'custom')
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get();

        if ($normalized === null) {
            if ($customDomains->isNotEmpty()) {
                $restaurant->domains()
                    ->where('kind', 'custom')
                    ->delete();
            }

            return;
        }

        $primary = $customDomains->first();

        if ($primary === null) {
            $primary = new RestaurantDomain([
                'restaurant_id' => $restaurant->id,
                'kind' => 'custom',
            ]);
        }

        $verifiedAt = $primary->domain === $normalized ? $primary->verified_at : null;

        $primary->fill([
            'domain' => $normalized,
            'kind' => 'custom',
            'is_primary' => true,
            'verified_at' => $verifiedAt,
        ]);
        $primary->restaurant_id = $restaurant->id;
        $primary->save();

        $restaurant->domains()
            ->where('kind', 'custom')
            ->whereKeyNot($primary->id)
            ->delete();
    }

    public function markProvisioned(Restaurant $restaurant): void
    {
        $domain = $this->normalize($restaurant->custom_domain);

        if ($domain === null) {
            return;
        }

        $this->syncCustomDomain($restaurant, $domain);

        $restaurant->domains()
            ->where('kind', 'custom')
            ->where('domain', $domain)
            ->update([
                'verified_at' => now(),
                'is_primary' => true,
            ]);
    }
}
