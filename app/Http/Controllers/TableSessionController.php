<?php

namespace App\Http\Controllers;

use App\Models\Restaurant;
use App\Models\TableSession;
use App\Models\TableWave;
use App\Models\User;
use App\Services\GuestMenuSessionService;
use App\Services\TableSessionAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TableSessionController extends Controller
{
    public function __construct(
        private readonly GuestMenuSessionService $guestMenuSessionService,
        private readonly TableSessionAccessService $tableSessionAccessService
    ) {
    }

    public function requestBill(TableSession $tableSession, WaveController $waveController): JsonResponse
    {
        $session = $this->guestMenuSessionService->resolveActiveSession($tableSession->id);
        $wave = $waveController->createGuestWaveForSession($session, TableWave::REQUEST_TYPE_REQUEST_BILL);

        return response()->json([
            'message' => __('messages.table_sessions.bill_requested'),
            'wave' => $waveController->formatGuestWave($wave),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $user = $request->user();

        $sessions = TableSession::query()
            ->with('restaurantTable')
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('status', [TableSession::STATUS_ACTIVE, TableSession::STATUS_SUSPENDED])
            ->orderBy('table_number')
            ->get()
            ->filter(function (TableSession $session) use ($user, $restaurant) {
                if (! $user->isStaff()) {
                    return true;
                }

                $assignedTableIds = $this->getAccessibleStaffTableIds($user, $restaurant);

                return in_array($session->restaurant_table_id, $assignedTableIds, true);
            })
            ->values();

        return response()->json([
            'table_sessions' => $sessions->map(fn (TableSession $session) => [
                ...$this->guestMenuSessionService->formatSession($session),
                'current_pin' => $this->guestMenuSessionService->currentPlainPin($session),
                'table' => $session->restaurantTable ? [
                    'id' => $session->restaurantTable->id,
                    'name' => $session->restaurantTable->name,
                ] : null,
            ])->values(),
        ]);
    }

    public function resetPin(Request $request, TableSession $tableSession): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertSessionBelongsToRestaurant($tableSession, $restaurant);
        $this->assertStaffCanAccessSession($request->user(), $tableSession, $restaurant);

        $result = $this->tableSessionAccessService->resetPin($tableSession, $request->user()->id);

        return response()->json([
            'message' => __('messages.table_sessions.pin_reset'),
            'table_session' => $this->guestMenuSessionService->formatSession($result['session']),
            'current_pin' => $result['pin'],
        ]);
    }

    public function finalize(Request $request, TableSession $tableSession): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertSessionBelongsToRestaurant($tableSession, $restaurant);
        $this->assertStaffCanAccessSession($request->user(), $tableSession, $restaurant);

        $session = $this->tableSessionAccessService->finalize($tableSession, $request->user()->id);

        return response()->json([
            'message' => __('messages.table_sessions.finalized'),
            'table_session' => $this->guestMenuSessionService->formatSession($session),
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

    private function assertSessionBelongsToRestaurant(TableSession $tableSession, Restaurant $restaurant): void
    {
        if ($tableSession->restaurant_id !== $restaurant->id) {
            abort(404);
        }
    }

    private function assertStaffCanAccessSession(User $user, TableSession $tableSession, Restaurant $restaurant): void
    {
        if (! $user->isStaff()) {
            return;
        }

        $assignedTableIds = $this->getAccessibleStaffTableIds($user, $restaurant);

        if (
            $tableSession->restaurant_table_id === null
            || ! in_array($tableSession->restaurant_table_id, $assignedTableIds, true)
        ) {
            abort(403, 'This staff account is not assigned to that table.');
        }
    }
}
