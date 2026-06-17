<?php

namespace App\Http\Controllers;

use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Services\TableManagementModeService;
use App\Services\TableProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RestaurantController extends Controller
{
    public function __construct(
        private readonly TableManagementModeService $tableManagementModeService,
        private readonly TableProvisioningService $tableProvisioningService,
    ) {
    }

    public function indexStaff(Request $request): JsonResponse
    {
        $restaurant = $this->getOwnedRestaurant($request);

        $staffMembers = $restaurant->staffUsers()
            ->where('role', User::ROLE_STAFF)
            ->with(['assignedTables' => function ($query) use ($restaurant) {
                $query->where('restaurant_id', $restaurant->id)
                    ->where('is_active', true)
                    ->orderBy('name');
            }])
            ->orderBy('name')
            ->get();

        return response()->json([
            'staff' => $staffMembers->map(fn (User $staff) => $this->formatStaffMember($staff))->values(),
        ]);
    }

    public function showProfile(Request $request): JsonResponse
    {
        $restaurant = $this->getOwnedRestaurant($request);

        return response()->json([
            'restaurant' => $this->formatRestaurant($restaurant),
            'profile' => $restaurant->profile,
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'legal_business_name' => 'nullable|string|max:255',
            'cuisine_specialty' => 'nullable|string|max:120',
            'primary_phone' => 'nullable|string|max:40',
            'whatsapp_phone' => 'nullable|string|max:40',
            'contact_email' => 'nullable|email|max:255',
            'website_url' => 'nullable|string|max:255',
            'address_line_1' => 'nullable|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:120',
            'state_province' => 'nullable|string|max:120',
            'postal_code' => 'nullable|string|max:40',
            'country' => 'nullable|string|max:120',
            'tax_registration_number' => 'nullable|string|max:120',
            'vat_registration_number' => 'nullable|string|max:120',
            'service_hours' => 'nullable|string|max:255',
            'short_description' => 'nullable|string|max:1200',
        ]);

        $restaurant = $this->getOwnedRestaurant($request);
        $existingProfile = is_array($restaurant->profile) ? $restaurant->profile : [];

        $profile = [
            'legal_business_name' => $this->normalizeOptionalString($validated['legal_business_name'] ?? null),
            'cuisine_specialty' => $this->normalizeOptionalString($validated['cuisine_specialty'] ?? null),
            'primary_phone' => $this->normalizeOptionalString($validated['primary_phone'] ?? null),
            'whatsapp_phone' => $this->normalizeOptionalString($validated['whatsapp_phone'] ?? null),
            'contact_email' => $this->normalizeOptionalString($validated['contact_email'] ?? null),
            'website_url' => $this->normalizeOptionalString($validated['website_url'] ?? null),
            'address_line_1' => $this->normalizeOptionalString($validated['address_line_1'] ?? null),
            'address_line_2' => $this->normalizeOptionalString($validated['address_line_2'] ?? null),
            'city' => $this->normalizeOptionalString($validated['city'] ?? null),
            'state_province' => $this->normalizeOptionalString($validated['state_province'] ?? null),
            'postal_code' => $this->normalizeOptionalString($validated['postal_code'] ?? null),
            'country' => $this->normalizeOptionalString($validated['country'] ?? null),
            'tax_registration_number' => $this->normalizeOptionalString($validated['tax_registration_number'] ?? null),
            'vat_registration_number' => $this->normalizeOptionalString($validated['vat_registration_number'] ?? null),
            'service_hours' => $this->normalizeOptionalString($validated['service_hours'] ?? null),
            'short_description' => $this->normalizeOptionalString($validated['short_description'] ?? null),
            'menu_categories' => array_values(array_filter(
                $existingProfile['menu_categories'] ?? [],
                fn ($value): bool => is_string($value) && trim($value) !== ''
            )),
        ];

        $restaurant->update([
            'name' => trim($validated['name']),
            'profile' => $profile,
        ]);

        $restaurant->refresh();

        return response()->json([
            'message' => 'Restaurant profile updated successfully.',
            'restaurant' => $this->formatRestaurant($restaurant),
            'profile' => $restaurant->profile,
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
            'restaurant' => $this->formatRestaurant($restaurant),
            'profile' => $restaurant->profile,
        ]);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'logo' => 'required|file|max:3072|mimes:png,jpg,jpeg,webp',
        ]);

        $restaurant = $this->getOwnedRestaurant($request);
        /** @var UploadedFile $logoFile */
        $logoFile = $validated['logo'];

        $oldPath = $restaurant->logo_path;
        $extension = strtolower($logoFile->getClientOriginalExtension() ?: $logoFile->extension() ?: 'png');
        if (! in_array($extension, ['png', 'jpg', 'jpeg', 'webp'], true)) {
            $extension = 'png';
        }

        $fileName = 'logo-'.Str::uuid().'.'.$extension;
        $path = $logoFile->storeAs("restaurants/{$restaurant->id}/branding", $fileName, 'public');

        $restaurant->update([
            'logo_path' => $path,
        ]);

        if (is_string($oldPath) && $oldPath !== '' && $oldPath !== $path) {
            try {
                Storage::disk('public')->delete($oldPath);
            } catch (\Throwable) {
                // Keep API success even if old file cleanup fails.
            }
        }

        $restaurant->refresh();

        return response()->json([
            'message' => 'Restaurant logo uploaded successfully.',
            'restaurant' => $this->formatRestaurant($restaurant),
            'profile' => $restaurant->profile,
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
            'role' => 'nullable|in:staff,chef',
            'table_ids' => 'nullable|array',
            'table_ids.*' => 'integer|distinct',
        ]);

        $restaurant = $this->getOwnedRestaurant($request);
        $mode = $this->tableManagementModeService->resolveMode($restaurant);
        if ($mode === TableManagementModeService::MODE_MANUAL && ! $restaurant->manual_table_count) {
            throw ValidationException::withMessages([
                'manual_table_count' => 'Set a manual table count before assigning tables.',
            ]);
        }

        if (($validated['role'] ?? User::ROLE_STAFF) === User::ROLE_CHEF && ! empty($validated['table_ids'])) {
            throw ValidationException::withMessages([
                'table_ids' => 'Tables can be assigned to waiters only.',
            ]);
        }

        $tableIds = $this->resolveAssignedTableIds($restaurant, $validated['table_ids'] ?? []);

        $temporaryPassword = Str::random(12);

        $staff = DB::transaction(function () use ($restaurant, $validated, $temporaryPassword, $tableIds) {
            $staff = User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'role' => $validated['role'] ?? User::ROLE_STAFF,
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
        $mode = $this->tableManagementModeService->resolveMode($restaurant);

        if (! $staff->hasRole(User::ROLE_STAFF, User::ROLE_CHEF)) {
            abort(404);
        }

        if (! $staff->hasRole(User::ROLE_STAFF)) {
            throw ValidationException::withMessages([
                'staff' => 'Tables can be assigned to waiters only.',
            ]);
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

        if ($mode === TableManagementModeService::MODE_MANUAL && ! $restaurant->manual_table_count) {
            throw ValidationException::withMessages([
                'manual_table_count' => 'Set a manual table count before assigning tables.',
            ]);
        }

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

    public function tableManagement(Request $request): JsonResponse
    {
        $restaurant = $this->getOwnedRestaurant($request);
        $mode = $this->tableManagementModeService->resolveMode($restaurant);
        $this->tableProvisioningService->removeChefAssignments($restaurant);

        if ($mode === TableManagementModeService::MODE_ROOM_PLAN) {
            $this->tableProvisioningService->provisionFromRoomPlan($restaurant);
        }

        $tables = RestaurantTable::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'mode' => $mode,
            'manual_table_count' => $restaurant->manual_table_count ? (int) $restaurant->manual_table_count : null,
            'active_tables' => $tables->map(fn (RestaurantTable $table) => [
                'id' => $table->id,
                'name' => $table->name,
            ])->values(),
        ]);
    }

    public function updateManualTableCount(Request $request): JsonResponse
    {
        $restaurant = $this->getOwnedRestaurant($request);
        $mode = $this->tableManagementModeService->resolveMode($restaurant);

        if ($mode !== TableManagementModeService::MODE_MANUAL) {
            abort(404);
        }

        $validated = $request->validate([
            'count' => 'required|integer|min:1|max:500',
        ]);

        $count = (int) $validated['count'];
        $this->tableProvisioningService->resetAssignments($restaurant);
        $this->tableProvisioningService->removeChefAssignments($restaurant);
        $this->tableProvisioningService->provisionManualCount($restaurant, $count);

        $tables = RestaurantTable::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'message' => 'Manual tables updated successfully.',
            'mode' => $mode,
            'manual_table_count' => $count,
            'active_tables' => $tables->map(fn (RestaurantTable $table) => [
                'id' => $table->id,
                'name' => $table->name,
            ])->values(),
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
            ->where('is_active', true)
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

    private function formatRestaurant(Restaurant $restaurant): array
    {
        return [
            'id' => $restaurant->id,
            'name' => $restaurant->name,
            'slug' => $restaurant->slug,
            'logo_url' => $restaurant->logo_url,
            'currency' => $restaurant->currency,
            'other_currency' => $restaurant->other_currency,
            'dollar_rate' => $restaurant->dollar_rate,
            'custom_domain' => $restaurant->domains()->where('kind', 'custom')->orderByDesc('is_primary')->value('domain'),
            'menu_categories' => array_values(array_filter(
                (is_array($restaurant->profile) ? ($restaurant->profile['menu_categories'] ?? []) : []),
                fn ($value): bool => is_string($value) && trim($value) !== ''
            )),
            'manual_table_count' => $restaurant->manual_table_count,
            'profile' => $restaurant->profile,
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
