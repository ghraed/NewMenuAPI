<?php

namespace App\Services;

use App\Models\Feature;
use App\Models\Restaurant;
use App\Models\RestaurantFeature;

class TableManagementModeService
{
    public const MODE_ROOM_PLAN = 'ROOM_PLAN';
    public const MODE_MANUAL = 'MANUAL';

    public function resolveMode(Restaurant $restaurant): string
    {
        $features = Feature::query()
            ->select(['id', 'key', 'is_active_by_default'])
            ->whereIn('key', ['room_plan_editor', 'table_reservations'])
            ->get()
            ->keyBy('key');

        $featureIds = $features->pluck('id')->all();
        $overridesByFeatureId = RestaurantFeature::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('feature_id', $featureIds)
            ->pluck('enabled', 'feature_id');

        $roomPlanEnabled = $this->resolveFeatureState($features->get('room_plan_editor'), $overridesByFeatureId);
        $reservationsEnabled = $this->resolveFeatureState($features->get('table_reservations'), $overridesByFeatureId);

        return $roomPlanEnabled && $reservationsEnabled
            ? self::MODE_ROOM_PLAN
            : self::MODE_MANUAL;
    }

    private function resolveFeatureState(?Feature $feature, \Illuminate\Support\Collection $overridesByFeatureId): bool
    {
        if (! $feature) {
            return false;
        }

        $override = $overridesByFeatureId->get($feature->id);

        return $override === null
            ? (bool) $feature->is_active_by_default
            : (bool) $override;
    }
}

