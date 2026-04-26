<?php

namespace App\Services;

use App\Models\Restaurant;

class RestaurantFeatureFlagScope
{
    public function __construct(
        private readonly FeatureFlagService $featureFlagService,
        private readonly Restaurant $restaurant,
    ) {
    }

    public function enabled(string $featureKey): bool
    {
        return $this->featureFlagService->isEnabled($this->restaurant, $featureKey);
    }

    public function enable(string $featureKey): void
    {
        $this->featureFlagService->enable($this->restaurant, $featureKey);
    }

    public function disable(string $featureKey): void
    {
        $this->featureFlagService->disable($this->restaurant, $featureKey);
    }

    public function sync(array $features): void
    {
        $this->featureFlagService->syncFeatures($this->restaurant, $features);
    }
}
