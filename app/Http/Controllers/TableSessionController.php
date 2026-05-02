<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RestaurantTable;
use App\Models\RoomPlanItem;
use App\Models\TableSession;
use App\Models\TableWave;
use App\Models\User;
use App\Services\GuestMenuSessionService;
use App\Services\InvoiceSplitService;
use App\Services\TableSessionAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TableSessionController extends Controller
{
    public function __construct(
        private readonly GuestMenuSessionService $guestMenuSessionService,
        private readonly TableSessionAccessService $tableSessionAccessService,
        private readonly InvoiceSplitService $invoiceSplitService,
    ) {
    }

    public function requestBill(TableSession $tableSession, WaveController $waveController): JsonResponse
    {
        $session = $this->guestMenuSessionService->resolveActiveSession($tableSession->id);

        if (! feature_enabled('request_bill', $session->restaurant)) {
            return response()->json([
                'message' => 'Bill request is disabled for this restaurant.',
            ], 403);
        }

        $wave = $waveController->createGuestWaveForSession($session, TableWave::REQUEST_TYPE_REQUEST_BILL);

        return response()->json([
            'message' => __('messages.table_sessions.bill_requested'),
            'wave' => $waveController->formatGuestWave($wave),
            'invoice_preview' => $this->buildInvoicePreviewPayload($session),
        ], 201);
    }

    public function guestInvoiceSplit(TableSession $tableSession): JsonResponse
    {
        $session = $this->guestMenuSessionService->resolveActiveSession($tableSession->id);
        $orders = $this->loadInvoiceOrdersForSession($session);

        return response()->json([
            'invoice_split' => $this->invoiceSplitService->buildPayload($session, $orders, true),
        ]);
    }

    public function updateGuestInvoiceSplit(Request $request, TableSession $tableSession): JsonResponse
    {
        $session = $this->guestMenuSessionService->resolveActiveSession($tableSession->id);
        $orders = $this->loadInvoiceOrdersForSession($session);

        $validated = $request->validate([
            'mode' => 'required|in:none,equal,by_person_order',
            'split_count' => 'nullable|integer|min:1|max:99',
            'people' => 'nullable|array',
        ]);

        $mode = $this->invoiceSplitService->normalizeMode((string) $validated['mode']);
        $splitCount = isset($validated['split_count']) ? (int) $validated['split_count'] : null;
        $people = isset($validated['people']) && is_array($validated['people'])
            ? $validated['people']
            : null;

        $this->invoiceSplitService->applySplitSettings($session, $orders, $mode, $splitCount, $people);
        $session->refresh();

        return response()->json([
            'message' => 'Invoice split settings updated successfully.',
            'invoice_split' => $this->invoiceSplitService->buildPayload($session, $orders, true),
        ]);
    }

    public function invoiceSplit(Request $request, TableSession $tableSession): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertSessionBelongsToRestaurant($tableSession, $restaurant);
        $this->assertStaffCanAccessSession($request->user(), $tableSession, $restaurant);

        $orders = $this->loadInvoiceOrdersForSession($tableSession);

        return response()->json([
            'invoice_split' => $this->invoiceSplitService->buildPayload($tableSession, $orders, true),
        ]);
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

    public function activate(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $user = $request->user();

        $validated = $request->validate([
            'table_id' => 'required|integer',
        ]);

        $table = RestaurantTable::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereKey($validated['table_id'])
            ->firstOrFail();

        if ($user->isStaff()) {
            $assignedTableIds = $this->getAccessibleStaffTableIds($user, $restaurant);

            if (! in_array($table->id, $assignedTableIds, true)) {
                abort(403, 'This staff account is not assigned to that table.');
            }
        }

        $tableNumber = $this->guestMenuSessionService->resolveTableNumberForTable($restaurant, $table);
        $session = $this->guestMenuSessionService->getOrCreateActiveSession(
            $restaurant,
            $table,
            $tableNumber,
            $user->id
        );

        $this->markCurrentReservedReservationAsBusy($restaurant, $table->id);

        return response()->json([
            'message' => __('messages.table_sessions.activated'),
            'table_session' => $this->guestMenuSessionService->formatSession($session),
            'current_pin' => $this->guestMenuSessionService->currentPlainPin($session),
            'table' => [
                'id' => $table->id,
                'name' => $table->name,
            ],
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
        $this->markBusyReservationsAsCompletedForSession($restaurant, $session);

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

    private function markCurrentReservedReservationAsBusy(Restaurant $restaurant, int $restaurantTableId): void
    {
        $roomPlanItemIds = $this->tableRoomPlanItemIds($restaurantTableId);

        if ($roomPlanItemIds === []) {
            return;
        }

        $now = now();

        Reservation::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('room_plan_item_id', $roomPlanItemIds)
            ->where('status', Reservation::STATUS_RESERVED)
            ->where('start_at', '<=', $now)
            ->where('end_at', '>', $now)
            ->update([
                'status' => Reservation::STATUS_BUSY,
                'updated_at' => $now,
            ]);
    }

    private function markBusyReservationsAsCompletedForSession(Restaurant $restaurant, TableSession $session): void
    {
        if (! $session->restaurant_table_id) {
            return;
        }

        $roomPlanItemIds = $this->tableRoomPlanItemIds((int) $session->restaurant_table_id);

        if ($roomPlanItemIds === []) {
            return;
        }

        $windowStart = $session->opened_at ?? now();
        $windowEnd = $session->closed_at ?? now();

        Reservation::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('room_plan_item_id', $roomPlanItemIds)
            ->where('status', Reservation::STATUS_BUSY)
            ->where('start_at', '<', $windowEnd)
            ->where('end_at', '>', $windowStart)
            ->update([
                'status' => Reservation::STATUS_COMPLETED,
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array<int>
     */
    private function tableRoomPlanItemIds(int $restaurantTableId): array
    {
        return RoomPlanItem::query()
            ->where('restaurant_table_id', $restaurantTableId)
            ->whereIn('type', [RoomPlanItem::TYPE_TABLE, RoomPlanItem::TYPE_TABLE_CIRCLE])
            ->pluck('id')
            ->map(fn ($itemId) => (int) $itemId)
            ->all();
    }

    private function buildInvoicePreviewPayload(TableSession $session): array
    {
        $orders = $this->loadInvoiceOrdersForSession($session);

        $lineItems = $orders
            ->flatMap(fn (Order $order) => $order->items->map(fn ($item) => [
                'key' => 'order-'.$order->id.'-item-'.$item->id,
                'order_item_id' => $item->id,
                'dish_name' => $item->dish_name,
                'dish_name_ar' => $this->resolveArabicDishName($item),
                'quantity' => $item->quantity,
                'unit_price' => number_format((float) $item->unit_price, 2, '.', ''),
                'line_subtotal' => number_format((float) $item->line_subtotal, 2, '.', ''),
            ]))
            ->values();

        $notes = $orders
            ->pluck('notes')
            ->filter(fn ($note) => is_string($note) && trim($note) !== '')
            ->map(fn ($note) => trim((string) $note))
            ->values();

        return [
            'restaurant_name' => $session->restaurant->name,
            'table_name' => $session->restaurantTable?->name ?? ('T'.$session->table_number),
            'generated_at' => now()->toIso8601String(),
            'notes' => $notes,
            'items' => $lineItems,
            'included_orders' => $orders
                ->map(fn (Order $order) => $order->order_number ?: 'ORD-'.$order->id)
                ->values(),
            'summary' => [
                'subtotal' => number_format((float) $orders->sum(fn (Order $order) => (float) $order->subtotal), 2, '.', ''),
                'discount_type' => null,
                'discount_value' => '0.00',
                'discount_amount' => number_format((float) $orders->sum(fn (Order $order) => (float) $order->discount_amount), 2, '.', ''),
                'taxable_subtotal' => number_format((float) $orders->sum(fn (Order $order) => (float) $order->taxable_subtotal), 2, '.', ''),
                'vat_rate' => number_format((float) $orders->max(fn (Order $order) => (float) $order->vat_rate), 2, '.', ''),
                'vat_amount' => number_format((float) $orders->sum(fn (Order $order) => (float) $order->vat_amount), 2, '.', ''),
                'total' => number_format((float) $orders->sum(fn (Order $order) => (float) $order->total), 2, '.', ''),
            ],
            'invoice_split' => $this->invoiceSplitService->buildPayload(
                $session,
                $orders,
                feature_enabled('invoice_splitting', $session->restaurant)
            ),
        ];
    }

    private function loadInvoiceOrdersForSession(TableSession $session)
    {
        return Order::query()
            ->where('table_session_id', $session->id)
            ->whereIn('status', [Order::STATUS_STAFF_CONFIRMED, Order::STATUS_ACCOUNTED])
            ->with(['items.dish', 'restaurant', 'restaurantTable'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    private function resolveArabicDishName(OrderItem $item): ?string
    {
        if (! $item->dish) {
            return null;
        }

        $localizedDish = $item->dish->toLocalizedArray('ar');

        return $item->dish->name_ar
            ?: ($localizedDish['name'] ?? null);
    }
}
