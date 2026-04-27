<?php

namespace App\Http\Controllers;

use App\Models\Restaurant;
use App\Models\RoomPlan;
use App\Services\RoomPlanItemService;
use App\Services\RoomPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoomPlanController extends Controller
{
    public function __construct(
        private readonly RoomPlanService $roomPlanService,
        private readonly RoomPlanItemService $roomPlanItemService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        $roomPlans = RoomPlan::query()
            ->where('restaurant_id', $restaurant->id)
            ->withCount(['items' => function ($query): void {
                $query->where('is_active', true);
            }])
            ->orderBy('name')
            ->get();

        return response()->json([
            'room_plans' => $roomPlans,
        ]);
    }

    public function show(Request $request, RoomPlan $roomPlan): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertPlanBelongsToRestaurant($roomPlan, $restaurant);

        $roomPlan->load(['items' => function ($query): void {
            $query->where('is_active', true)
                ->orderBy('z_index')
                ->orderBy('id');
        }]);

        return response()->json([
            'room_plan' => $roomPlan,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'width' => 'required|integer|min:100|max:10000',
            'height' => 'required|integer|min:100|max:10000',
        ]);

        $roomPlan = $this->roomPlanService->createPlan($restaurant, $validated);

        return response()->json([
            'message' => 'Room plan created successfully.',
            'room_plan' => $roomPlan,
        ], 201);
    }

    public function update(Request $request, RoomPlan $roomPlan): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertPlanBelongsToRestaurant($roomPlan, $restaurant);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'width' => 'sometimes|required|integer|min:100|max:10000',
            'height' => 'sometimes|required|integer|min:100|max:10000',
        ]);

        $updated = $this->roomPlanService->updatePlan($roomPlan, $validated);

        return response()->json([
            'message' => 'Room plan updated successfully.',
            'room_plan' => $updated,
        ]);
    }

    public function destroy(Request $request, RoomPlan $roomPlan): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertPlanBelongsToRestaurant($roomPlan, $restaurant);

        $this->roomPlanService->deletePlan($roomPlan);

        return response()->json([
            'message' => 'Room plan deleted successfully.',
        ]);
    }

    public function uploadBackground(Request $request, RoomPlan $roomPlan): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertPlanBelongsToRestaurant($roomPlan, $restaurant);

        $validated = $request->validate([
            'file' => 'required|file|max:10240|mimes:jpg,jpeg,png,webp,heic,heif',
        ]);

        $updated = $this->roomPlanService->uploadBackgroundImage($roomPlan, $validated['file']);

        return response()->json([
            'message' => 'Room plan background uploaded successfully.',
            'room_plan' => $updated,
        ]);
    }

    public function saveItems(Request $request, RoomPlan $roomPlan): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertPlanBelongsToRestaurant($roomPlan, $restaurant);

        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'nullable|integer',
            'items.*.type' => 'required|string|max:40',
            'items.*.label' => 'required|string|max:120',
            'items.*.x' => 'required|numeric',
            'items.*.y' => 'required|numeric',
            'items.*.width' => 'required|numeric',
            'items.*.height' => 'required|numeric',
            'items.*.rotation' => 'nullable|numeric',
            'items.*.seats' => 'nullable|integer|min:1|max:99',
            'items.*.z_index' => 'nullable|integer|min:-5000|max:5000',
            'items.*.container' => 'nullable|string|max:20',
            'items.*.is_active' => 'nullable|boolean',
        ]);

        $items = $this->roomPlanItemService->saveBulk($roomPlan, $validated['items']);

        return response()->json([
            'message' => 'Room plan items saved successfully.',
            'items' => $items,
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

    private function assertPlanBelongsToRestaurant(RoomPlan $roomPlan, Restaurant $restaurant): void
    {
        if ($roomPlan->restaurant_id !== $restaurant->id) {
            abort(404);
        }
    }
}
