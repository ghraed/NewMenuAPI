<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\FormatsGuestDishes;
use App\Models\AnalyticsEvent;
use App\Models\Dish;
use App\Services\DishAlternativeSuggestionService;
use App\Services\TenantRestaurantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class GuestController extends Controller
{
    use FormatsGuestDishes;

    public function __construct(
        private readonly DishAlternativeSuggestionService $dishAlternativeSuggestionService,
        private readonly TenantRestaurantResolver $tenantRestaurantResolver
    ) {
    }

    public function listDishes(Request $request, ?string $restaurant_slug = null): JsonResponse
    {
        $restaurant = $this->tenantRestaurantResolver->resolveFromSlugOrHost($restaurant_slug, $request);

        $dishes = Dish::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('status', 'published')
            ->with(['assets', 'dishIngredients.ingredient'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'restaurant' => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'slug' => $restaurant->slug,
            ],
            'dishes' => $this->localizeDishes($dishes),
        ]);
    }

    public function showDish(Request $request, int $dish_id, ?string $restaurant_slug = null): JsonResponse
    {
        $restaurant = $this->tenantRestaurantResolver->resolveFromSlugOrHost($restaurant_slug, $request);

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

        $payload = $this->localizeDish($dish);
        $payload['restaurant'] = [
            'id' => $restaurant->id,
            'name' => $restaurant->name,
            'slug' => $restaurant->slug,
        ];

        return response()->json($payload);
    }

    public function listTables(Request $request, ?string $restaurant_slug = null): JsonResponse
    {
        $restaurant = $this->tenantRestaurantResolver->resolveFromSlugOrHost($restaurant_slug, $request);

        $tables = $restaurant->tables()
            ->orderBy('name')
            ->get()
            ->map(fn ($table) => [
                'id' => $table->id,
                'name' => $table->name,
            ])
            ->values();

        return response()->json([
            'restaurant' => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'slug' => $restaurant->slug,
            ],
            'tables' => $tables,
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
