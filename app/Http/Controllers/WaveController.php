<?php

namespace App\Http\Controllers;

use App\Events\TableWaveCreated;
use App\Events\TableWaveResolved;
use App\Models\Restaurant;
use App\Models\TableSession;
use App\Models\TableWave;
use App\Models\User;
use App\Services\GuestMenuSessionService;
use App\Services\MobilePushNotificationService;
use App\Services\StaffCapabilityService;
use App\Services\TableSessionAccessService;
use App\Services\TenantRestaurantResolver;
use App\Services\WebPushNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WaveController extends Controller
{
    public function __construct(
        private readonly GuestMenuSessionService $guestMenuSessionService,
        private readonly TableSessionAccessService $tableSessionAccessService,
        private readonly TenantRestaurantResolver $tenantRestaurantResolver,
        private readonly StaffCapabilityService $staffCapabilityService,
    ) {
    }

    public function store(Request $request, ?string $restaurant_slug = null): JsonResponse
    {
        $validated = $request->validate([
            'table_reference' => 'required|string|max:40',
        ]);

        $restaurant = $this->tenantRestaurantResolver
            ->resolveFromSlugOrHost($restaurant_slug, $request)
            ->load('tables');

        $access = $this->tableSessionAccessService->authorizeRequestForRestaurant(
            $request,
            $restaurant,
            $validated['table_reference']
        );
        $session = $this->guestMenuSessionService->resolveActiveSession($access->table_session_id);

        if (! feature_enabled('waiter_call', $session->restaurant)) {
            return response()->json([
                'message' => 'Waiter call is disabled for this restaurant.',
            ], 403);
        }

        $result = $this->createWave(
            $session->restaurant,
            $session->restaurantTable,
            $session,
            TableWave::REQUEST_TYPE_CALL_WAITER
        );
        $formattedWave = $this->formatWave($result['wave']);

        if ($result['existing']) {
            return response()->json([
                'message' => __('messages.waves.already_pending'),
                'wave' => $formattedWave,
            ]);
        }

        return response()->json([
            'message' => __('messages.waves.sent'),
            'wave' => $formattedWave,
        ], 201);
    }

    public function storeForSession(TableSession $tableSession): JsonResponse
    {
        $session = $this->guestMenuSessionService->resolveActiveSession($tableSession->id);

        if (! feature_enabled('waiter_call', $session->restaurant)) {
            return response()->json([
                'message' => 'Waiter call is disabled for this restaurant.',
            ], 403);
        }

        $result = $this->createWave(
            $session->restaurant,
            $session->restaurantTable,
            $session,
            TableWave::REQUEST_TYPE_CALL_WAITER
        );
        $formattedWave = $this->formatWave($result['wave']);

        if ($result['existing']) {
            return response()->json([
                'message' => __('messages.waves.already_pending'),
                'wave' => $formattedWave,
            ]);
        }

        return response()->json([
            'message' => __('messages.waves.sent'),
            'wave' => $formattedWave,
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
            $assignedTableIds = $this->staffCapabilityService->assignedTableIds($user, $restaurant);

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
        $this->staffCapabilityService->assertCanAccessWave($user, $restaurant, $wave);

        if ($wave->status !== TableWave::STATUS_PENDING) {
            return response()->json([
                'message' => __('messages.waves.resolve_only_pending'),
            ], 422);
        }

        $wave->update([
            'status' => TableWave::STATUS_RESOLVED,
            'resolved_by' => $user->id,
            'resolved_at' => now(),
        ]);

        $wave = $wave->fresh(['restaurant', 'restaurantTable', 'resolvedBy']);
        $formattedWave = $this->formatWave($wave);
        try {
            event(new TableWaveResolved($wave, $formattedWave));
        } catch (\Throwable $exception) {
            Log::warning('Failed to broadcast a resolved table wave event.', [
                'wave_id' => $wave->id,
                'message' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'message' => __('messages.waves.resolved'),
            'wave' => $formattedWave,
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

    private function assertWaveBelongsToRestaurant(TableWave $wave, Restaurant $restaurant): void
    {
        if ($wave->restaurant_id !== $restaurant->id) {
            abort(404);
        }
    }

    public function formatGuestWave(TableWave $wave): array
    {
        return $this->formatWave($wave);
    }

    public function createGuestWaveForSession(
        TableSession $session,
        string $requestType = TableWave::REQUEST_TYPE_CALL_WAITER
    ): TableWave {
        return $this->createWave(
            $session->restaurant,
            $session->restaurantTable,
            $session,
            $requestType
        )['wave'];
    }

    private function formatWave(TableWave $wave): array
    {
        $wave->loadMissing('restaurant', 'restaurantTable', 'resolvedBy');

        return [
            'id' => $wave->id,
            'uuid' => $wave->uuid,
            'status' => $wave->status,
            'table_session_id' => $wave->table_session_id,
            'request_type' => $wave->request_type,
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

    private function dispatchStaffAlerts(TableWave $wave, array $formattedWave, bool $isReminder = false): void
    {
        try {
            event(new TableWaveCreated($wave, $formattedWave));
        } catch (\Throwable $exception) {
            Log::warning('Failed to broadcast a table wave event.', [
                'wave_id' => $wave->id,
                'message' => $exception->getMessage(),
            ]);
        }

        try {
            app(WebPushNotificationService::class)->notifyWaveCreated($wave, $isReminder);
        } catch (\Throwable $exception) {
            Log::warning('Failed to send web push notifications for a table wave.', [
                'wave_id' => $wave->id,
                'message' => $exception->getMessage(),
            ]);
        }

        try {
            app(MobilePushNotificationService::class)->notifyWaveCreated($wave, $isReminder);
        } catch (\Throwable $exception) {
            Log::warning('Failed to send mobile push notifications for a table wave.', [
                'wave_id' => $wave->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function createWave(
        Restaurant $restaurant,
        \App\Models\RestaurantTable $restaurantTable,
        ?TableSession $session,
        string $requestType
    ): array {
        $existingWave = TableWave::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('restaurant_table_id', $restaurantTable->id)
            ->where('status', TableWave::STATUS_PENDING)
            ->where('request_type', $requestType)
            ->when(
                $session,
                fn ($query) => $query->where('table_session_id', $session->id),
                fn ($query) => $query->whereNull('table_session_id')
            )
            ->with(['restaurant', 'restaurantTable'])
            ->latest('created_at')
            ->first();

        if ($existingWave) {
            $formattedWave = $this->formatWave($existingWave);
            $this->dispatchStaffAlerts($existingWave, $formattedWave, true);

            return [
                'wave' => $existingWave,
                'existing' => true,
            ];
        }

        $wave = TableWave::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'restaurant_table_id' => $restaurantTable->id,
            'table_session_id' => $session?->id,
            'status' => TableWave::STATUS_PENDING,
            'request_type' => $requestType,
            'table_reference' => $restaurantTable->name,
        ])->fresh(['restaurant', 'restaurantTable']);

        $this->dispatchStaffAlerts($wave, $this->formatWave($wave));

        return [
            'wave' => $wave,
            'existing' => false,
        ];
    }
}
