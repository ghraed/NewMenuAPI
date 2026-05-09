<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Models\Restaurant;
use App\Models\RestaurantFeature;
use App\Services\FeatureFlagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class SuperAdminFeatureFlagController extends Controller
{
    public function __construct(
        private readonly FeatureFlagService $featureFlagService,
    ) {
    }

    public function restaurants(): JsonResponse
    {
        $features = Feature::query()
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        $restaurants = Restaurant::query()
            ->with(['restaurantFeatures' => function ($query): void {
                $query->select(['id', 'restaurant_id', 'feature_id', 'enabled']);
            }])
            ->orderBy('name')
            ->get();

        $restaurantsPayload = $restaurants->map(function (Restaurant $restaurant) use ($features): array {
            $overridesByFeatureId = $restaurant->restaurantFeatures->keyBy('feature_id');

            return [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'slug' => $restaurant->slug,
                'status' => $restaurant->status,
                'features' => $this->formatFeatureStateList($features, $overridesByFeatureId),
            ];
        })->values();

        return response()->json([
            'restaurants' => $restaurantsPayload,
        ]);
    }

    public function features(): JsonResponse
    {
        $features = Feature::query()
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        $flat = $features->map(fn (Feature $feature): array => $this->formatFeature($feature))->values();

        $grouped = $features
            ->groupBy(fn (Feature $feature): string => $feature->category ?: 'General')
            ->map(fn (Collection $groupedFeatures, string $category): array => [
                'category' => $category,
                'features' => $groupedFeatures->map(fn (Feature $feature): array => $this->formatFeature($feature))->values(),
            ])
            ->values();

        return response()->json([
            'features' => $flat,
            'grouped' => $grouped,
        ]);
    }

    public function restaurantFeatures(Restaurant $restaurant): JsonResponse
    {
        $features = Feature::query()
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        $overridesByFeatureId = RestaurantFeature::query()
            ->where('restaurant_id', $restaurant->id)
            ->get()
            ->keyBy('feature_id');

        return response()->json([
            'restaurant' => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'slug' => $restaurant->slug,
                'status' => $restaurant->status,
            ],
            'features' => $this->formatFeatureStateList($features, $overridesByFeatureId),
        ]);
    }

    public function updateFeature(Request $request, Restaurant $restaurant, Feature $feature): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => 'required|boolean',
        ]);

        if ((bool) $validated['enabled']) {
            $this->featureFlagService->enable($restaurant, $feature->key);
        } else {
            $this->featureFlagService->disable($restaurant, $feature->key);
        }

        $enabled = $this->featureFlagService->isEnabled($restaurant, $feature->key);

        return response()->json([
            'message' => 'Feature updated successfully.',
            'restaurant_id' => $restaurant->id,
            'feature' => [
                ...$this->formatFeature($feature),
                'enabled' => $enabled,
            ],
        ]);
    }

    public function bulkUpdate(Request $request, Restaurant $restaurant): JsonResponse
    {
        $validated = $request->validate([
            'features' => 'required|array|min:1',
            'features.*.key' => 'required|string|exists:features,key',
            'features.*.enabled' => 'required|boolean',
        ]);

        /** @var array<int, array{key:string, enabled:bool}> $featuresInput */
        $featuresInput = collect($validated['features'])
            ->map(fn (array $feature): array => [
                'key' => strtolower(trim((string) $feature['key'])),
                'enabled' => (bool) $feature['enabled'],
            ])
            ->unique('key')
            ->values()
            ->all();

        $this->featureFlagService->syncFeatures($restaurant, $featuresInput);

        return response()->json([
            'message' => 'Features updated successfully.',
        ]);
    }

    /**
     * @param Collection<int, Feature> $features
     * @param Collection<int|string, RestaurantFeature> $overridesByFeatureId
     * @return array<int, array<string, mixed>>
     */
    private function formatFeatureStateList(Collection $features, Collection $overridesByFeatureId): array
    {
        return $features->map(function (Feature $feature) use ($overridesByFeatureId): array {
            /** @var RestaurantFeature|null $override */
            $override = $overridesByFeatureId->get($feature->id);
            $enabled = $override ? (bool) $override->enabled : (bool) $feature->is_active_by_default;

            return [
                ...$this->formatFeature($feature),
                'enabled' => $enabled,
                'source' => $override ? 'override' : 'default',
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatFeature(Feature $feature): array
    {
        return [
            'id' => $feature->id,
            'key' => $feature->key,
            'name' => $feature->name,
            'description' => $feature->description,
            'category' => $feature->category,
            'is_active_by_default' => (bool) $feature->is_active_by_default,
        ];
    }
}
