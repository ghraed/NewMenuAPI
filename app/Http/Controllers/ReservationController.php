<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\RoomPlanItem;
use App\Services\ReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReservationController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservationService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        $validated = $request->validate([
            'reservation_date' => 'nullable|date_format:Y-m-d',
            'room_plan_id' => 'nullable|integer',
        ]);

        $query = Reservation::query()
            ->where('restaurant_id', $restaurant->id)
            ->with(['roomPlan', 'roomPlanItem'])
            ->orderBy('reservation_date')
            ->orderBy('start_time');

        if (! empty($validated['reservation_date'])) {
            $query->where('reservation_date', $validated['reservation_date']);
        }

        if (! empty($validated['room_plan_id'])) {
            $query->where('room_plan_id', (int) $validated['room_plan_id']);
        }

        return response()->json([
            'reservations' => $query->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $validated = $this->validateReservationPayload($request, true);

        $reservation = $this->reservationService->create($restaurant, $validated);

        return response()->json([
            'message' => 'Reservation created successfully.',
            'reservation' => $reservation,
        ], 201);
    }

    public function update(Request $request, Reservation $reservation): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $validated = $this->validateReservationPayload($request, false);

        $updated = $this->reservationService->update($restaurant, $reservation, $validated);

        return response()->json([
            'message' => 'Reservation updated successfully.',
            'reservation' => $updated,
        ]);
    }

    public function cancel(Request $request, Reservation $reservation): JsonResponse
    {
        return $this->updateStatus($request, $reservation, Reservation::STATUS_CANCELLED, 'Reservation cancelled successfully.');
    }

    public function markBusy(Request $request, Reservation $reservation): JsonResponse
    {
        return $this->updateStatus($request, $reservation, Reservation::STATUS_BUSY, 'Reservation marked as busy successfully.');
    }

    public function markCompleted(Request $request, Reservation $reservation): JsonResponse
    {
        return $this->updateStatus($request, $reservation, Reservation::STATUS_COMPLETED, 'Reservation marked as completed successfully.');
    }

    public function markNoShow(Request $request, Reservation $reservation): JsonResponse
    {
        return $this->updateStatus($request, $reservation, Reservation::STATUS_NO_SHOW, 'Reservation marked as no-show successfully.');
    }

    private function updateStatus(Request $request, Reservation $reservation, string $status, string $message): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $updated = $this->reservationService->updateStatus($restaurant, $reservation, $status);

        return response()->json([
            'message' => $message,
            'reservation' => $updated,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateReservationPayload(Request $request, bool $isCreate): array
    {
        $prefix = $isCreate ? 'required' : 'sometimes|required';

        return $request->validate([
            'room_plan_id' => $prefix.'|integer',
            'room_plan_item_id' => [
                $prefix,
                'integer',
                Rule::exists('room_plan_items', 'id')->where(function ($query): void {
                    $query->where('type', RoomPlanItem::TYPE_TABLE)
                        ->where('is_active', true);
                }),
            ],
            'customer_name' => $prefix.'|string|max:120',
            'customer_phone' => $prefix.'|string|max:40',
            'customer_email' => 'sometimes|nullable|email|max:255',
            'reservation_date' => $prefix.'|date_format:Y-m-d',
            'start_time' => $prefix.'|date_format:H:i',
            'end_time' => $prefix.'|date_format:H:i',
            'status' => 'sometimes|string|in:reserved,busy,cancelled,completed,no_show',
            'notes' => 'sometimes|nullable|string|max:2000',
        ]);
    }

    private function getRestaurantForRequest(Request $request): Restaurant
    {
        $user = $request->user();

        if (! $user || ! method_exists($user, 'currentRestaurant')) {
            abort(403, 'No restaurant linked to user.');
        }

        $restaurant = $user->currentRestaurant();

        if (! $restaurant) {
            abort(403, 'No restaurant linked to user.');
        }

        return $restaurant;
    }
}
