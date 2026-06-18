<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\FormatsGuestDishes;
use App\Models\AnalyticsEvent;
use App\Models\Dish;
use App\Services\DishAlternativeSuggestionService;
use App\Services\FeatureFlagService;
use App\Services\TenantRestaurantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

    public function showDish(Request $request, string $dish_id): JsonResponse
    {
        return $this->showDishForRestaurant($request, null, $dish_id);
    }

    public function showDishBySlug(Request $request, string $restaurant_slug, string $dish_id): JsonResponse
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
        $includeDishes = $this->resolveIncludeDishesMode($request);
        $includeIndex = $request->boolean('include_index', false);
        $limit = $this->resolvePageLimit($request);
        $offset = max(0, (int) $request->query('offset', 0));

        $response = [
            'restaurant' => $this->formatGuestRestaurant($restaurant),
        ];

        if ($includeIndex) {
            $response['dish_index'] = $this->buildDishIndex($restaurant->id);
        }

        if ($includeDishes === 'page') {
            $total = Dish::query()
                ->where('restaurant_id', $restaurant->id)
                ->where('status', 'published')
                ->count();
            $pageDishes = Dish::query()
                ->where('restaurant_id', $restaurant->id)
                ->where('status', 'published')
                ->with(['assets', 'dishIngredients.ingredient'])
                ->orderBy('name')
                ->skip($offset)
                ->take($limit)
                ->get();
            $loadedCount = $pageDishes->count();
            $hasMore = ($offset + $loadedCount) < $total;

            $response['dishes_page'] = $this->localizeDishes($pageDishes, $ar3dEnabled, $animatedIngredientsEnabled);
            $response['dishes_meta'] = [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => $hasMore,
                'next_offset' => $hasMore ? ($offset + $loadedCount) : null,
            ];
        } elseif ($includeDishes === 'all') {
            $dishes = Dish::query()
                ->where('restaurant_id', $restaurant->id)
                ->where('status', 'published')
                ->with(['assets', 'dishIngredients.ingredient'])
                ->orderBy('name')
                ->get();
            $response['dishes'] = $this->localizeDishes($dishes, $ar3dEnabled, $animatedIngredientsEnabled);
        }

        return response()->json($response);
    }

    private function resolveIncludeDishesMode(Request $request): string
    {
        $mode = strtolower((string) $request->query('include_dishes', 'all'));

        if (! in_array($mode, ['all', 'page', 'none'], true)) {
            return 'all';
        }

        return $mode;
    }

    private function resolvePageLimit(Request $request): int
    {
        $limit = (int) $request->query('limit', 20);

        if ($limit <= 0) {
            return 20;
        }

        return min($limit, 100);
    }

    private function buildDishIndex(int $restaurantId): array
    {
        $dishes = Dish::query()
            ->where('restaurant_id', $restaurantId)
            ->where('status', 'published')
            ->select([
                'id',
                'uuid',
                'name',
                'name_ar',
                'description',
                'description_ar',
                'category',
                'category_ar',
                'is_anchor',
                'is_profitable',
                'image_url',
                'item_type',
            ])
            ->with([
                'dishIngredients' => fn ($query) => $query
                    ->select([
                        'id',
                        'dish_id',
                        'ingredient_id',
                        'quantity',
                        'unit',
                        'order_index',
                    ])
                    ->orderBy('order_index'),
                'dishIngredients.ingredient' => fn ($query) => $query->select([
                    'id',
                    'name',
                    'name_ar',
                    'is_active',
                    'current_stock_quantity',
                    'stock_unit',
                ]),
            ])
            ->orderBy('name')
            ->get();

        return $dishes->map(function (Dish $dish): array {
            $isOrderable = $dish->isOrderable();
            $ingredients = [];
            $seenIngredients = [];

            foreach ($dish->dishIngredients as $row) {
                $ingredient = $row->ingredient;
                if (! $ingredient) {
                    continue;
                }

                $normalized = trim(mb_strtolower((string) $ingredient->name));
                if ($normalized === '' || isset($seenIngredients[$normalized])) {
                    continue;
                }

                $seenIngredients[$normalized] = true;
                $ingredients[] = [
                    'name' => $ingredient->name,
                    'name_ar' => $ingredient->name_ar,
                ];
            }

            return [
                'id' => $dish->id,
                'uuid' => $dish->uuid,
                'name' => $dish->name,
                'name_ar' => $dish->name_ar,
                'description' => $dish->description,
                'description_ar' => $dish->description_ar,
                'category' => $dish->category,
                'category_ar' => $dish->category_ar,
                'is_anchor' => (bool) $dish->is_anchor,
                'is_profitable' => (bool) $dish->is_profitable,
                'item_type' => $dish->item_type ?? Dish::ITEM_TYPE_PREPARED_DISH,
                'is_orderable' => $isOrderable,
                'is_out_of_stock' => ! $isOrderable,
                'image_url' => $dish->image_url,
                'ingredients' => $ingredients,
            ];
        })->values()->all();
    }

    private function showDishForRestaurant(Request $request, ?string $restaurantSlug, string $dishId): JsonResponse
    {
        $restaurant = $this->tenantRestaurantResolver->resolveFromSlugOrHost($restaurantSlug, $request);
        $ar3dEnabled = $this->featureFlagService->isEnabled($restaurant, 'ar_3d_dishes');
        $animatedIngredientsEnabled = $this->featureFlagService->isEnabled($restaurant, 'animated_ingredients');

        $dish = $this->resolvePublishedDishReference(
            Dish::query()
                ->where('restaurant_id', $restaurant->id)
                ->where('status', 'published')
                ->with(['assets', 'dishIngredients.ingredient']),
            $dishId
        );

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

    private function resolvePublishedDishReference(Builder $query, string $dishReference): Dish
    {
        $normalizedReference = trim($dishReference);

        if ($normalizedReference !== '' && ctype_digit($normalizedReference)) {
            $byId = (clone $query)->where('id', (int) $normalizedReference)->first();
            if ($byId instanceof Dish) {
                return $byId;
            }
        }

        $normalizedSlug = Str::slug($normalizedReference);
        $dish = (clone $query)
            ->orderBy('name')
            ->get()
            ->first(fn (Dish $candidate): bool => Str::slug((string) $candidate->name) === $normalizedSlug);

        if ($dish instanceof Dish) {
            return $dish;
        }

        abort(404);
    }

    private function listTablesForRestaurant(Request $request, ?string $restaurantSlug): JsonResponse
    {
        $restaurant = $this->tenantRestaurantResolver->resolveFromSlugOrHost($restaurantSlug, $request);

        $tables = $restaurant->tables()
            ->where('is_active', true)
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
            'logo_url' => $restaurant->logo_url,
            'profile' => $restaurant->profile,
            'currency' => $restaurant->currency,
            'other_currency' => $restaurant->other_currency,
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
