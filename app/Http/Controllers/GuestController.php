<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\FormatsGuestDishes;
use App\Models\AnalyticsEvent;
use App\Models\Dish;
use App\Services\DishAlternativeSuggestionService;
use App\Services\FeatureFlagService;
use App\Services\TenantRestaurantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class GuestController extends Controller
{
    use FormatsGuestDishes;

    public function __construct(
        private readonly DishAlternativeSuggestionService $dishAlternativeSuggestionService,
        private readonly TenantRestaurantResolver $tenantRestaurantResolver,
        private readonly FeatureFlagService $featureFlagService,
    ) {
    }

    public function listDishes(Request $request): JsonResponse
    {
        return $this->listDishesForRestaurant($request, null);
    }

    public function listDishesBySlug(Request $request, string $restaurant_slug): JsonResponse
    {
        return $this->listDishesForRestaurant($request, $restaurant_slug);
    }

    public function showDish(Request $request, int $dish_id): JsonResponse
    {
        return $this->showDishForRestaurant($request, null, $dish_id);
    }

    public function showDishBySlug(Request $request, string $restaurant_slug, int $dish_id): JsonResponse
    {
        return $this->showDishForRestaurant($request, $restaurant_slug, $dish_id);
    }

    public function listTables(Request $request): JsonResponse
    {
        return $this->listTablesForRestaurant($request, null);
    }

    public function listTablesBySlug(Request $request, string $restaurant_slug): JsonResponse
    {
        return $this->listTablesForRestaurant($request, $restaurant_slug);
    }

    private function listDishesForRestaurant(Request $request, ?string $restaurantSlug): JsonResponse
    {
        $restaurant = $this->tenantRestaurantResolver->resolveFromSlugOrHost($restaurantSlug, $request);
        $ar3dEnabled = $this->featureFlagService->isEnabled($restaurant, 'ar_3d_dishes');
        $animatedIngredientsEnabled = $this->featureFlagService->isEnabled($restaurant, 'animated_ingredients');

        $dishes = Dish::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('status', 'published')
            ->with(['assets', 'dishIngredients.ingredient'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'restaurant' => $this->formatGuestRestaurant($restaurant),
            'dishes' => $this->localizeDishes($dishes, $ar3dEnabled, $animatedIngredientsEnabled),
        ]);
    }

    private function showDishForRestaurant(Request $request, ?string $restaurantSlug, int $dishId): JsonResponse
    {
        $restaurant = $this->tenantRestaurantResolver->resolveFromSlugOrHost($restaurantSlug, $request);
        $ar3dEnabled = $this->featureFlagService->isEnabled($restaurant, 'ar_3d_dishes');
        $animatedIngredientsEnabled = $this->featureFlagService->isEnabled($restaurant, 'animated_ingredients');

        $dish = Dish::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('id', $dishId)
            ->where('status', 'published')
            ->with(['assets', 'dishIngredients.ingredient'])
            ->firstOrFail();

        AnalyticsEvent::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'dish_id' => $dish->id,
            'restaurant_id' => $dish->restaurant_id,
            'event_type' => 'page_view',
            'device_type' => $this->getDeviceType($request),
            'platform' => $this->getPlatform($request),
            'user_agent' => $request->userAgent(),
            'ip_address' => $request->ip(),
        ]);

        $aiRecommendationsEnabled = $this->featureFlagService->isEnabled($restaurant, 'ai_recommendations');

        if ($aiRecommendationsEnabled) {
            $dish->load([
                'suggestedDishes' => function ($query) {
                    $query->where('status', 'published')
                        ->with(['assets', 'dishIngredients.ingredient'])
                        ->orderBy('name');
                },
                'relatedDishes' => function ($query) {
                    $query->where('status', 'published')
                        ->with(['assets', 'dishIngredients.ingredient'])
                        ->orderBy('name');
                },
            ]);

            if (! $dish->isOrderable()) {
                $dish->setRelation(
                    'alternativeDishes',
                    $this->dishAlternativeSuggestionService->suggestForDish($dish, 4)
                );
            }
        } else {
            $dish->setRelation('suggestedDishes', collect());
            $dish->setRelation('relatedDishes', collect());
            $dish->setRelation('alternativeDishes', collect());
        }

        $payload = $this->localizeDish($dish, $ar3dEnabled, $animatedIngredientsEnabled);
        $payload['restaurant'] = $this->formatGuestRestaurant($restaurant);

        return response()->json($payload);
    }

    private function listTablesForRestaurant(Request $request, ?string $restaurantSlug): JsonResponse
    {
        $restaurant = $this->tenantRestaurantResolver->resolveFromSlugOrHost($restaurantSlug, $request);

        $tables = $restaurant->tables()
            ->orderBy('name')
            ->get()
            ->map(fn ($table) => [
                'id' => $table->id,
                'name' => $table->name,
            ])
            ->values();

        return response()->json([
            'restaurant' => $this->formatGuestRestaurant($restaurant),
            'tables' => $tables,
        ]);
    }

    private function formatGuestRestaurant(\App\Models\Restaurant $restaurant): array
    {
        return [
            'id' => $restaurant->id,
            'name' => $restaurant->name,
            'slug' => $restaurant->slug,
            'currency' => $restaurant->currency,
            'dollar_rate' => $restaurant->dollar_rate,
            'feature_flags' => $this->featureFlagService->flagsForRestaurant($restaurant),
        ];
    }

    private function getDeviceType(Request $request): string
    {
        $ua = $request->userAgent();
        if (strpos($ua, 'iPad') !== false) {
            return 'tablet';
        }
        if (strpos($ua, 'Mobile') !== false) {
            return 'mobile';
        }

        return 'desktop';
    }

    private function getPlatform(Request $request): string
    {
        $ua = $request->userAgent();
        if (strpos($ua, 'iPhone') !== false) {
            return 'ios';
        }
        if (strpos($ua, 'Android') !== false) {
            return 'android';
        }

        return 'unknown';
    }

    public function test(): int
    {
        return 2;
    }

    public function showTestDish(int $dishId)
    {
        $path = "dishes/{$dishId}/models/model.glb";
        if (! Storage::disk('public')->exists($path)) {
            abort(404);
        }
        $fullPath = Storage::disk('public')->path($path);

        return response()->download($fullPath, "dish_{$dishId}.glb", [
            'Content-Type' => 'model/gltf-binary',
            'ngrok-skip-browser-warning' => 'true',
        ]);
    }
}
