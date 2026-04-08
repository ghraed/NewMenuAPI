<?php

namespace App\Http\Controllers;

use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RestaurantController extends Controller
{
    public function indexStaff(Request $request): JsonResponse
    {
        $restaurant = $this->getOwnedRestaurant($request);

        $staffMembers = $restaurant->staffUsers()
            ->where('role', User::ROLE_STAFF)
            ->with(['assignedTables' => function ($query) use ($restaurant) {
                $query->where('restaurant_id', $restaurant->id)
                    ->orderBy('name');
            }])
            ->orderBy('name')
            ->get();

        return response()->json([
            'staff' => $staffMembers->map(fn (User $staff) => $this->formatStaffMember($staff))->values(),
        ]);
    }

    public function updateName(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $restaurant = $this->getOwnedRestaurant($request);

        $restaurant->update([
            'name' => trim($validated['name']),
        ]);

        return response()->json([
            'message' => 'Restaurant name updated successfully.',
            'restaurant' => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'slug' => $restaurant->slug,
            ],
        ]);
    }

    public function storeStaff(Request $request): JsonResponse
    {
        $request->merge([
            'name' => trim((string) $request->input('name', '')),
            'email' => $this->normalizeOptionalString($request->input('email')),
            'phone' => $this->normalizeOptionalString($request->input('phone')),
        ]);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255|required_without:phone|unique:users,email',
            'phone' => 'nullable|string|max:40|required_without:email|unique:users,phone',
            'table_ids' => 'nullable|array',
            'table_ids.*' => 'integer|distinct',
        ]);

        $restaurant = $this->getOwnedRestaurant($request);
        $tableIds = $this->resolveAssignedTableIds($restaurant, $validated['table_ids'] ?? []);

        $temporaryPassword = Str::random(12);

        $staff = DB::transaction(function () use ($restaurant, $validated, $temporaryPassword, $tableIds) {
            $staff = User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'role' => User::ROLE_STAFF,
                'password' => $temporaryPassword,
            ]);

            $restaurant->staffUsers()->syncWithoutDetaching([$staff->id]);
            $staff->assignedTables()->sync($tableIds);

            return $staff->fresh('assignedTables');
        });

        return response()->json([
            'message' => 'Staff member created successfully.',
            'staff' => $this->formatStaffMember($staff),
            'temporary_password' => $temporaryPassword,
        ], 201);
    }

    public function updateStaffTables(Request $request, User $staff): JsonResponse
    {
        $restaurant = $this->getOwnedRestaurant($request);

        if (! $staff->isStaff()) {
            abort(404);
        }

        $isLinkedToRestaurant = $restaurant->staffUsers()
            ->where('users.id', $staff->id)
            ->exists();

        if (! $isLinkedToRestaurant) {
            abort(404);
        }

        $validated = $request->validate([
            'table_ids' => 'nullable|array',
            'table_ids.*' => 'integer|distinct',
        ]);

        $tableIds = $this->resolveAssignedTableIds($restaurant, $validated['table_ids'] ?? []);
        $staff->assignedTables()->sync($tableIds);
        $staff->load(['assignedTables' => function ($query) use ($restaurant) {
            $query->where('restaurant_id', $restaurant->id)
                ->orderBy('name');
        }]);

        return response()->json([
            'message' => 'Staff table assignments updated successfully.',
            'staff' => $this->formatStaffMember($staff),
        ]);
    }

    private function getOwnedRestaurant(Request $request): Restaurant
    {
        $user = $request->user();
        $user->loadMissing('restaurant');

        if (! $user->restaurant) {
            abort(403, 'No restaurant is linked to this account');
        }

        return $user->restaurant;
    }

    private function resolveAssignedTableIds(Restaurant $restaurant, array $tableIds): array
    {
        if ($tableIds === []) {
            return [];
        }

        $resolvedTableIds = RestaurantTable::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('id', $tableIds)
            ->pluck('id')
            ->map(fn ($tableId) => (int) $tableId)
            ->all();

        if (count(array_unique($tableIds)) !== count($resolvedTableIds)) {
            throw ValidationException::withMessages([
                'table_ids' => 'One or more selected tables are invalid for this restaurant.',
            ]);
        }

        sort($resolvedTableIds);

        return $resolvedTableIds;
    }

    private function formatStaffMember(User $staff): array
    {
        $staff->loadMissing(['assignedTables' => function ($query) {
            $query->orderBy('name');
        }]);

        return [
            'id' => $staff->id,
            'name' => $staff->name,
            'email' => $staff->email,
            'phone' => $staff->phone,
            'role' => $staff->role,
            'assigned_tables' => $staff->assignedTables
                ->map(fn (RestaurantTable $table) => [
                    'id' => $table->id,
                    'name' => $table->name,
                ])
                ->values(),
            'created_at' => $staff->created_at?->toIso8601String(),
        ];
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }
}
