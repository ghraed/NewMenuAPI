<?php

namespace App\Services;

use App\Models\Feature;
use App\Models\FeatureFlagAuditLog;
use App\Models\Restaurant;
use App\Models\RestaurantFeature;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class FeatureFlagService
{
    public function __construct(
        private readonly TenantRestaurantResolver $tenantRestaurantResolver,
    ) {
    }

    public function enabled(string $featureKey): bool
    {
        $restaurant = $this->resolveCurrentRestaurant();

        if (! $restaurant) {
            return false;
        }

        return $this->isEnabled($restaurant, $featureKey);
    }

    public function forRestaurant(Restaurant $restaurant): RestaurantFeatureFlagScope
    {
        return new RestaurantFeatureFlagScope($this, $restaurant);
    }

    /**
     * @return array<string, bool>
     */
    public function flagsForRestaurant(Restaurant $restaurant): array
    {
        $features = Feature::query()
            ->select(['id', 'key', 'is_active_by_default'])
            ->orderBy('id')
            ->get();

        $overridesByFeatureId = RestaurantFeature::query()
            ->where('restaurant_id', $restaurant->id)
            ->pluck('enabled', 'feature_id');

        $flags = [];

        foreach ($features as $feature) {
            $override = $overridesByFeatureId->get($feature->id);
            $flags[$feature->key] = $override === null
                ? (bool) $feature->is_active_by_default
                : (bool) $override;
        }

        return $flags;
    }

    public function isEnabled(Restaurant $restaurant, string $featureKey): bool
    {
        $feature = Feature::query()
            ->where('key', trim($featureKey))
            ->first();

        if (! $feature) {
            return false;
        }

        $override = RestaurantFeature::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('feature_id', $feature->id)
            ->first();

        if ($override) {
            return (bool) $override->enabled;
        }

        return (bool) $feature->is_active_by_default;
    }

    public function enable(Restaurant $restaurant, string $featureKey): void
    {
        $feature = $this->resolveFeatureByKey($featureKey);

        $this->setFeatureState($restaurant, $feature, true);
    }

    public function disable(Restaurant $restaurant, string $featureKey): void
    {
        $feature = $this->resolveFeatureByKey($featureKey);

        $this->setFeatureState($restaurant, $feature, false);
    }

    /**
     * @param array<int, array{key:string, enabled:bool}> $features
     */
    public function syncFeatures(Restaurant $restaurant, array $features): void
    {
        DB::transaction(function () use ($restaurant, $features): void {
            foreach ($features as $featureInput) {
                if (! isset($featureInput['key'], $featureInput['enabled'])) {
                    continue;
                }

                $feature = $this->resolveFeatureByKey((string) $featureInput['key']);
                $enabled = (bool) $featureInput['enabled'];

                $this->setFeatureState($restaurant, $feature, $enabled);
            }
        });
    }

    private function resolveFeatureByKey(string $featureKey): Feature
    {
        $normalizedKey = trim($featureKey);

        $feature = Feature::query()
            ->where('key', $normalizedKey)
            ->first();

        if (! $feature) {
            throw (new ModelNotFoundException())->setModel(Feature::class, [$normalizedKey]);
        }

        return $feature;
    }

    private function setFeatureState(Restaurant $restaurant, Feature $feature, bool $enabled): void
    {
        DB::transaction(function () use ($restaurant, $feature, $enabled): void {
            $override = RestaurantFeature::query()
                ->where('restaurant_id', $restaurant->id)
                ->where('feature_id', $feature->id)
                ->first();

            $oldValue = $override
                ? (bool) $override->enabled
                : (bool) $feature->is_active_by_default;

            if ($override && (bool) $override->enabled === $enabled) {
                return;
            }

            if (! $override && $oldValue === $enabled) {
                return;
            }

            RestaurantFeature::query()->updateOrCreate(
                [
                    'restaurant_id' => $restaurant->id,
                    'feature_id' => $feature->id,
                ],
                [
                    'enabled' => $enabled,
                ]
            );

            $this->recordAuditLog($restaurant, $feature, $oldValue, $enabled);
        });
    }

    private function recordAuditLog(Restaurant $restaurant, Feature $feature, bool $oldValue, bool $newValue): void
    {
        FeatureFlagAuditLog::query()->create([
            'restaurant_id' => $restaurant->id,
            'feature_id' => $feature->id,
            'changed_by_user_id' => Auth::id(),
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'created_at' => now(),
        ]);
    }

    private function resolveCurrentRestaurant(): ?Restaurant
    {
        $request = request();

        if (! $request) {
            return null;
        }

        $requestUser = $request->user();
        if ($requestUser && method_exists($requestUser, 'currentRestaurant')) {
            $restaurant = $requestUser->currentRestaurant();
            if ($restaurant) {
                return $restaurant;
            }
        }

        try {
            $slug = $request->route('restaurant_slug');
            $slug = is_string($slug) ? $slug : null;

            return $this->tenantRestaurantResolver->resolveFromSlugOrHost($slug, $request);
        } catch (\Throwable) {
            return null;
        }
    }
}
