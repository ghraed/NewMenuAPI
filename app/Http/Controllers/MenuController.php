<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\FormatsGuestDishes;
use App\Models\AnalyticsEvent;
use App\Models\Dish;
use App\Services\DishAlternativeSuggestionService;
use App\Services\FeatureFlagService;
use App\Services\GuestMenuSessionService;
use App\Services\TableSessionAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    use FormatsGuestDishes;

    public function __construct(
        private readonly GuestMenuSessionService $guestMenuSessionService,
        private readonly TableSessionAccessService $tableSessionAccessService,
        private readonly DishAlternativeSuggestionService $dishAlternativeSuggestionService,
        private readonly FeatureFlagService $featureFlagService,
    ) {
    }

    public function showTableMenu(int $table_id, Request $request): JsonResponse
    {
        $context = $this->guestMenuSessionService->resolveTableContext($table_id, $request);
        $restaurant = $context['restaurant'];
        $table = $context['table'];
        $session = $context['session'];
        $guestAccess = $session
            ? $this->tableSessionAccessService->findRequestGuestAccess($request, $session)
            : null;
        $waiterCallEnabled = $this->featureFlagService->isEnabled($restaurant, 'waiter_call');
        $requestBillEnabled = $this->featureFlagService->isEnabled($restaurant, 'request_bill');
        $ar3dEnabled = $this->featureFlagService->isEnabled($restaurant, 'ar_3d_dishes');
        $animatedIngredientsEnabled = $this->featureFlagService->isEnabled($restaurant, 'animated_ingredients');
        $tableOrderingEnabled = $this->featureFlagService->isEnabled($restaurant, 'table_ordering');
        $includeDishes = $this->resolveIncludeDishesMode($request);
        $includeIndex = $request->boolean('include_index', false);
        $limit = $this->resolvePageLimit($request);
        $offset = max(0, (int) $request->query('offset', 0));

        $response = [
            'restaurant' => $this->guestMenuSessionService->formatRestaurant($restaurant),
            'table' => $this->guestMenuSessionService->formatTable($table, $table_id),
            'table_session' => $session ? $this->guestMenuSessionService->formatSession($session) : null,
            'guest_access' => $this->guestMenuSessionService->formatGuestAccess($guestAccess),
            'protected_actions' => [
                'ordering_unlocked' => $session !== null && $guestAccess !== null && $tableOrderingEnabled,
                'can_place_order' => $session !== null && $guestAccess !== null && $tableOrderingEnabled,
                'can_call_waiter' => $session !== null && $guestAccess !== null && $waiterCallEnabled,
                'can_request_bill' => $session !== null && $guestAccess !== null && $requestBillEnabled,
            ],
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
                'is_orderable' => $isOrderable,
                'is_out_of_stock' => ! $isOrderable,
                'image_url' => $dish->image_url,
                'ingredients' => $ingredients,
            ];
        })->values()->all();
    }

    public function showTableDish(int $table_id, int $dish_id, Request $request): JsonResponse
    {
        $context = $this->guestMenuSessionService->resolveTableContext($table_id, $request);
        $restaurant = $context['restaurant'];
        $table = $context['table'];
        $session = $context['session'];
        $guestAccess = $session
            ? $this->tableSessionAccessService->findRequestGuestAccess($request, $session)
            : null;
        $waiterCallEnabled = $this->featureFlagService->isEnabled($restaurant, 'waiter_call');
        $requestBillEnabled = $this->featureFlagService->isEnabled($restaurant, 'request_bill');
        $ar3dEnabled = $this->featureFlagService->isEnabled($restaurant, 'ar_3d_dishes');
        $animatedIngredientsEnabled = $this->featureFlagService->isEnabled($restaurant, 'animated_ingredients');
        $tableOrderingEnabled = $this->featureFlagService->isEnabled($restaurant, 'table_ordering');

        $dish = Dish::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('id', $dish_id)
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

        return response()->json([
            'restaurant' => $this->guestMenuSessionService->formatRestaurant($restaurant),
            'table' => $this->guestMenuSessionService->formatTable($table, $table_id),
            'table_session' => $session ? $this->guestMenuSessionService->formatSession($session) : null,
            'guest_access' => $this->guestMenuSessionService->formatGuestAccess($guestAccess),
            'protected_actions' => [
                'ordering_unlocked' => $session !== null && $guestAccess !== null && $tableOrderingEnabled,
                'can_place_order' => $session !== null && $guestAccess !== null && $tableOrderingEnabled,
                'can_call_waiter' => $session !== null && $guestAccess !== null && $waiterCallEnabled,
                'can_request_bill' => $session !== null && $guestAccess !== null && $requestBillEnabled,
            ],
            'dish' => $this->localizeDish($dish, $ar3dEnabled, $animatedIngredientsEnabled),
        ]);
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
}
