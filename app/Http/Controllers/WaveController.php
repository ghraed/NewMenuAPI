<?php

namespace App\Http\Controllers;

use App\Models\Restaurant;
use App\Models\TableWave;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WaveController extends Controller
{
    public function store(Request $request, string $restaurant_slug): JsonResponse
    {
        $validated = $request->validate([
            'table_reference' => 'required|string|max:40',
        ]);

        $restaurant = Restaurant::query()
            ->with('tables')
            ->where('slug', $restaurant_slug)
            ->firstOrFail();

        $restaurantTable = $restaurant->tables
            ->firstWhere('name', trim($validated['table_reference']));

        if (! $restaurantTable) {
            throw ValidationException::withMessages([
                'table_reference' => 'Select a valid table reference for this restaurant.',
            ]);
        }

        $existingWave = TableWave::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('restaurant_table_id', $restaurantTable->id)
            ->where('status', TableWave::STATUS_PENDING)
            ->with(['restaurant', 'restaurantTable'])
            ->latest('created_at')
            ->first();

        if ($existingWave) {
            return response()->json([
                'message' => 'A wave from this table is already waiting for staff.',
                'wave' => $this->formatWave($existingWave),
            ]);
        }

        $wave = TableWave::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'restaurant_table_id' => $restaurantTable->id,
            'status' => TableWave::STATUS_PENDING,
            'table_reference' => $restaurantTable->name,
        ])->fresh(['restaurant', 'restaurantTable']);

        return response()->json([
            'message' => 'Wave sent to the staff team.',
            'wave' => $this->formatWave($wave),
        ], 201);
    }

    public function pending(Request $request): JsonResponse
    {
        $user = $request->user();
        $restaurant = $this->getRestaurantForRequest($request);

        $wavesQuery = TableWave::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('status', TableWave::STATUS_PENDING)
            ->with(['restaurant', 'restaurantTable'])
            ->latest('created_at');

        if ($user->isStaff()) {
            $assignedTableIds = $this->getAccessibleStaffTableIds($user, $restaurant);

            if ($assignedTableIds === []) {
                return response()->json([
                    'waves' => [],
                ]);
            }

            $wavesQuery->whereIn('restaurant_table_id', $assignedTableIds);
        }

        return response()->json([
            'waves' => $wavesQuery->get()->map(fn (TableWave $wave) => $this->formatWave($wave))->values(),
        ]);
    }

    public function resolve(Request $request, TableWave $wave): JsonResponse
    {
        $user = $request->user();
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertWaveBelongsToRestaurant($wave, $restaurant);
        $this->assertStaffCanAccessWave($user, $wave, $restaurant);

        if ($wave->status !== TableWave::STATUS_PENDING) {
            return response()->json([
                'message' => 'Only pending waves can be resolved.',
            ], 422);
        }

        $wave->update([
            'status' => TableWave::STATUS_RESOLVED,
            'resolved_by' => $user->id,
            'resolved_at' => now(),
        ]);

        $wave = $wave->fresh(['restaurant', 'restaurantTable', 'resolvedBy']);

        return response()->json([
            'message' => 'Wave marked as handled.',
            'wave' => $this->formatWave($wave),
        ]);
    }

    private function getRestaurantForRequest(Request $request): Restaurant
    {
        $user = $request->user();
        $user->loadMissing('restaurant', 'staffRestaurants');

        $restaurant = $user->currentRestaurant();

        if (! $restaurant) {
            abort(403, 'No restaurant is linked to this account');
        }

        return $restaurant;
    }

    private function getAccessibleStaffTableIds(User $user, Restaurant $restaurant): array
    {
        $user->loadMissing(['assignedTables' => function ($query) use ($restaurant) {
            $query->where('restaurant_id', $restaurant->id);
        }]);

        return $user->assignedTables
            ->pluck('id')
            ->map(fn ($tableId) => (int) $tableId)
            ->all();
    }

    private function assertWaveBelongsToRestaurant(TableWave $wave, Restaurant $restaurant): void
    {
        if ($wave->restaurant_id !== $restaurant->id) {
            abort(404);
        }
    }

    private function assertStaffCanAccessWave(User $user, TableWave $wave, Restaurant $restaurant): void
    {
        if (! $user->isStaff()) {
            return;
        }

        $assignedTableIds = $this->getAccessibleStaffTableIds($user, $restaurant);

        if (
            $wave->restaurant_table_id === null
            || ! in_array($wave->restaurant_table_id, $assignedTableIds, true)
        ) {
            abort(403, 'This staff account is not assigned to that table.');
        }
    }

    private function formatWave(TableWave $wave): array
    {
        $wave->loadMissing('restaurant', 'restaurantTable', 'resolvedBy');

        return [
            'id' => $wave->id,
            'uuid' => $wave->uuid,
            'status' => $wave->status,
            'table_reference' => $wave->table_reference,
            'table' => $wave->restaurantTable ? [
                'id' => $wave->restaurantTable->id,
                'name' => $wave->restaurantTable->name,
            ] : null,
            'restaurant' => [
                'id' => $wave->restaurant->id,
                'name' => $wave->restaurant->name,
                'slug' => $wave->restaurant->slug,
            ],
            'created_at' => $wave->created_at?->toIso8601String(),
            'resolved_at' => $wave->resolved_at?->toIso8601String(),
            'resolved_by' => $wave->resolvedBy ? [
                'id' => $wave->resolvedBy->id,
                'name' => $wave->resolvedBy->name,
                'email' => $wave->resolvedBy->email,
                'phone' => $wave->resolvedBy->phone,
                'role' => $wave->resolvedBy->role,
            ] : null,
        ];
    }
}
