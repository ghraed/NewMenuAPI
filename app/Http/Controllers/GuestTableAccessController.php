<?php

namespace App\Http\Controllers;

use App\Services\GuestMenuSessionService;
use App\Services\TableSessionAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GuestTableAccessController extends Controller
{
    public function __construct(
        private readonly GuestMenuSessionService $guestMenuSessionService,
        private readonly TableSessionAccessService $tableSessionAccessService
    ) {
    }

    public function verifyPin(Request $request, int $table_id): JsonResponse
    {
        $validated = $request->validate([
            'pin' => 'required|string|size:4',
        ]);

        $result = $this->tableSessionAccessService->verifyPinForTable($request, $table_id, $validated['pin']);

        return response()->json([
            'message' => __('messages.table_sessions.pin_verified'),
            'restaurant' => $this->guestMenuSessionService->formatRestaurant($result['restaurant']),
            'table' => $this->guestMenuSessionService->formatTable($result['table'], $table_id),
            'table_session' => $this->guestMenuSessionService->formatSession($result['session']),
            'guest_access' => $this->tableSessionAccessService->buildGuestAccessPayload($result['guest_access'], $result['token']),
            'protected_actions' => [
                'ordering_unlocked' => true,
                'can_place_order' => true,
                'can_call_waiter' => true,
                'can_request_bill' => true,
            ],
        ]);
    }
}
