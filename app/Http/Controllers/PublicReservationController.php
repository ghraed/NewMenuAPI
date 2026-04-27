<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Models\RoomPlan;
use App\Services\ReservationAvailabilityService;
use App\Services\ReservationService;
use App\Services\TenantRestaurantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicReservationController extends Controller
{
    public function __construct(
        private readonly TenantRestaurantResolver $tenantRestaurantResolver,
        private readonly ReservationAvailabilityService $availabilityService,
        private readonly ReservationService $reservationService,
    ) {
    }

    public function listRoomPlans(Request $request): JsonResponse
    {
        $restaurant = $this->tenantRestaurantResolver->resolveFromSlugOrHost(null, $request);

        $roomPlans = RoomPlan::query()
            ->where('restaurant_id', $restaurant->id)
            ->orderBy('name')
            ->get();

        return response()->json([
            'restaurant' => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'slug' => $restaurant->slug,
            ],
            'room_plans' => $roomPlans,
        ]);
    }

    public function showRoomPlan(Request $request, RoomPlan $roomPlan): JsonResponse
    {
        $restaurant = $this->tenantRestaurantResolver->resolveFromSlugOrHost(null, $request);
        $this->assertPlanBelongsToRestaurant($roomPlan, $restaurant->id);

        $roomPlan->load(['items' => function ($query): void {
            $query->where('is_active', true)
                ->orderBy('z_index')
                ->orderBy('id');
        }]);

        return response()->json([
            'room_plan' => $roomPlan,
        ]);
    }

    public function availability(Request $request): JsonResponse
    {
        $restaurant = $this->tenantRestaurantResolver->resolveFromSlugOrHost(null, $request);

        $validated = $request->validate([
            'room_plan_id' => 'required|integer',
            'reservation_date' => 'required|date_format:Y-m-d',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
        ]);

        $roomPlan = RoomPlan::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereKey((int) $validated['room_plan_id'])
            ->firstOrFail();

        $availability = $this->availabilityService->availabilityForPlan(
            $roomPlan,
            (string) $validated['reservation_date'],
            (string) $validated['start_time'],
            (string) $validated['end_time'],
        );

        return response()->json([
            'room_plan_id' => $roomPlan->id,
            'availability' => $availability,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $restaurant = $this->tenantRestaurantResolver->resolveFromSlugOrHost(null, $request);

        $validated = $request->validate([
            'room_plan_id' => 'required|integer',
            'room_plan_item_id' => 'required|integer',
            'customer_name' => 'required|string|max:120',
            'customer_phone' => 'required|string|max:40',
            'customer_email' => 'nullable|email|max:255',
            'reservation_date' => 'required|date_format:Y-m-d',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'notes' => 'nullable|string|max:2000',
        ]);

        $validated['status'] = Reservation::STATUS_RESERVED;

        $reservation = $this->reservationService->create($restaurant, $validated);

        return response()->json([
            'message' => 'Reservation created successfully.',
            'reservation' => $reservation,
        ], 201);
    }

    private function assertPlanBelongsToRestaurant(RoomPlan $roomPlan, int $restaurantId): void
    {
        if ($roomPlan->restaurant_id !== $restaurantId) {
            abort(404);
        }
    }
}
