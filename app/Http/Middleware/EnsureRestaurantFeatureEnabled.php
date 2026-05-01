<?php

namespace App\Http\Middleware;

use App\Models\Dish;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Models\TableSession;
use App\Models\TableWave;
use App\Models\User;
use App\Services\FeatureFlagService;
use App\Services\TenantRestaurantResolver;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRestaurantFeatureEnabled
{
    public function __construct(
        private readonly FeatureFlagService $featureFlagService,
        private readonly TenantRestaurantResolver $tenantRestaurantResolver,
    ) {
    }

    public function handle(Request $request, Closure $next, string $featureKey): Response
    {
        $restaurant = $this->resolveRestaurant($request);

        if (! $restaurant) {
            return $this->forbiddenResponse("Unable to resolve restaurant for feature [{$featureKey}].");
        }

        if (! $this->featureFlagService->isEnabled($restaurant, $featureKey)) {
            return $this->forbiddenResponse("Feature [{$featureKey}] is disabled for this restaurant.");
        }

        return $next($request);
    }

    private function resolveRestaurant(Request $request): ?Restaurant
    {
        $requestUser = $request->user();
        if ($requestUser instanceof User && method_exists($requestUser, 'currentRestaurant')) {
            $restaurantFromUser = $requestUser->currentRestaurant();
            if ($restaurantFromUser) {
                return $restaurantFromUser;
            }
        }

        $restaurantParam = $request->route('restaurant');
        if ($restaurantParam instanceof Restaurant) {
            return $restaurantParam;
        }

        $tableSessionParam = $request->route('tableSession');
        if ($tableSessionParam instanceof TableSession) {
            return $tableSessionParam->restaurant()->first();
        }

        $restaurantTableParam = $request->route('restaurantTable');
        if ($restaurantTableParam instanceof RestaurantTable) {
            return $restaurantTableParam->restaurant()->first();
        }

        $waveParam = $request->route('wave');
        if ($waveParam instanceof TableWave) {
            return $waveParam->restaurant()->first();
        }

        $orderParam = $request->route('order');
        if ($orderParam instanceof Order) {
            return $orderParam->restaurant()->first();
        }

        $dishParam = $request->route('dish');
        if ($dishParam instanceof Dish) {
            return $dishParam->restaurant()->first();
        }

        $tableIdParam = $request->route('table_id');
        if (is_numeric($tableIdParam)) {
            $table = RestaurantTable::query()->find((int) $tableIdParam);
            if ($table) {
                return $table->restaurant()->first();
            }
        }

        $slug = $request->route('restaurant_slug');
        $slug = is_string($slug) ? $slug : null;

        try {
            return $this->tenantRestaurantResolver->resolveFromSlugOrHost($slug, $request);
        } catch (ModelNotFoundException) {
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function forbiddenResponse(string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
        ], 404);
    }
}
