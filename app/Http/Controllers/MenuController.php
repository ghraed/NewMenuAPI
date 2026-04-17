<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\FormatsGuestDishes;
use App\Models\AnalyticsEvent;
use App\Models\Dish;
use App\Services\DishAlternativeSuggestionService;
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
        private readonly DishAlternativeSuggestionService $dishAlternativeSuggestionService
    ) {
    }

    public function showTableMenu(int $table_id, Request $request): JsonResponse
    {
        $context = $this->guestMenuSessionService->resolveTableContext($table_id);
        $restaurant = $context['restaurant'];
        $table = $context['table'];
        $session = $context['session'];
        $guestAccess = $this->tableSessionAccessService->findRequestGuestAccess($request, $session);

        $dishes = Dish::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('status', 'published')
            ->with(['assets', 'dishIngredients.ingredient'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'restaurant' => $this->guestMenuSessionService->formatRestaurant($restaurant),
            'table' => $this->guestMenuSessionService->formatTable($table, $table_id),
            'table_session' => $this->guestMenuSessionService->formatSession($session),
            'guest_access' => $this->guestMenuSessionService->formatGuestAccess($guestAccess),
            'protected_actions' => [
                'ordering_unlocked' => $guestAccess !== null,
                'can_place_order' => $guestAccess !== null,
                'can_call_waiter' => $guestAccess !== null,
                'can_request_bill' => $guestAccess !== null,
            ],
            'dishes' => $this->localizeDishes($dishes),
        ]);
    }

    public function showTableDish(int $table_id, int $dish_id, Request $request): JsonResponse
    {
        $context = $this->guestMenuSessionService->resolveTableContext($table_id);
        $restaurant = $context['restaurant'];
        $table = $context['table'];
        $session = $context['session'];
        $guestAccess = $this->tableSessionAccessService->findRequestGuestAccess($request, $session);

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

        return response()->json([
            'restaurant' => $this->guestMenuSessionService->formatRestaurant($restaurant),
            'table' => $this->guestMenuSessionService->formatTable($table, $table_id),
            'table_session' => $this->guestMenuSessionService->formatSession($session),
            'guest_access' => $this->guestMenuSessionService->formatGuestAccess($guestAccess),
            'protected_actions' => [
                'ordering_unlocked' => $guestAccess !== null,
                'can_place_order' => $guestAccess !== null,
                'can_call_waiter' => $guestAccess !== null,
                'can_request_bill' => $guestAccess !== null,
            ],
            'dish' => $this->localizeDish($dish),
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
