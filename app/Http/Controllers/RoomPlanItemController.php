<?php

namespace App\Http\Controllers;

use App\Models\Restaurant;
use App\Models\RoomPlan;
use App\Models\RoomPlanItem;
use App\Services\RoomPlanItemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoomPlanItemController extends Controller
{
    public function __construct(
        private readonly RoomPlanItemService $roomPlanItemService,
    ) {
    }

    public function store(Request $request, RoomPlan $roomPlan): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertPlanBelongsToRestaurant($roomPlan, $restaurant);

        $validated = $request->validate([
            'type' => 'required|string|max:40',
            'label' => 'required|string|max:120',
            'x' => 'required|numeric',
            'y' => 'required|numeric',
            'width' => 'required|numeric',
            'height' => 'required|numeric',
            'rotation' => 'nullable|numeric',
            'seats' => 'nullable|integer|min:1|max:99',
            'z_index' => 'nullable|integer|min:-5000|max:5000',
            'container' => 'nullable|string|max:20',
            'is_active' => 'nullable|boolean',
        ]);

        $item = $this->roomPlanItemService->createItem($roomPlan, $validated);

        return response()->json([
            'message' => 'Room plan item created successfully.',
            'item' => $item,
        ], 201);
    }

    public function update(Request $request, RoomPlan $roomPlan, RoomPlanItem $roomPlanItem): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertPlanBelongsToRestaurant($roomPlan, $restaurant);

        $validated = $request->validate([
            'type' => 'sometimes|required|string|max:40',
            'label' => 'sometimes|required|string|max:120',
            'x' => 'sometimes|required|numeric',
            'y' => 'sometimes|required|numeric',
            'width' => 'sometimes|required|numeric',
            'height' => 'sometimes|required|numeric',
            'rotation' => 'sometimes|nullable|numeric',
            'seats' => 'sometimes|nullable|integer|min:1|max:99',
            'z_index' => 'sometimes|nullable|integer|min:-5000|max:5000',
            'container' => 'sometimes|nullable|string|max:20',
            'is_active' => 'sometimes|boolean',
        ]);

        $item = $this->roomPlanItemService->updateItem($roomPlan, $roomPlanItem, $validated);

        return response()->json([
            'message' => 'Room plan item updated successfully.',
            'item' => $item,
        ]);
    }

    public function destroy(Request $request, RoomPlan $roomPlan, RoomPlanItem $roomPlanItem): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertPlanBelongsToRestaurant($roomPlan, $restaurant);

        $this->roomPlanItemService->softDeleteItem($roomPlan, $roomPlanItem);

        return response()->json([
            'message' => 'Room plan item deleted successfully.',
        ]);
    }

    public function duplicate(Request $request, RoomPlan $roomPlan, RoomPlanItem $roomPlanItem): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertPlanBelongsToRestaurant($roomPlan, $restaurant);

        $item = $this->roomPlanItemService->duplicateItem($roomPlan, $roomPlanItem);

        return response()->json([
            'message' => 'Room plan item duplicated successfully.',
            'item' => $item,
        ], 201);
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

    private function assertPlanBelongsToRestaurant(RoomPlan $roomPlan, Restaurant $restaurant): void
    {
        if ($roomPlan->restaurant_id !== $restaurant->id) {
            abort(404);
        }
    }
}
