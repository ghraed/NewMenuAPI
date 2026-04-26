<?php

use App\Models\Restaurant;
use App\Services\FeatureFlagService;

if (! function_exists('feature_enabled')) {
    function feature_enabled(string $featureKey, ?Restaurant $restaurant = null): bool
    {
        $service = app(FeatureFlagService::class);

        if ($restaurant) {
            return $service->isEnabled($restaurant, $featureKey);
        }

        return $service->enabled($featureKey);
    }
}
