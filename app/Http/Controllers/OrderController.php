<?php

namespace App\Http\Controllers;

use App\Models\Dish;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Models\TableSession;
use App\Models\User;
use App\Services\GuestMenuSessionService;
use App\Services\OrderInvoiceCalculator;
use App\Services\TableSessionAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function __construct(
        private readonly GuestMenuSessionService $guestMenuSessionService,
        private readonly TableSessionAccessService $tableSessionAccessService
    ) {
    }

    public function store(
        Request $request,
        string $restaurant_slug,
        OrderInvoiceCalculator $invoiceCalculator
    ): JsonResponse {
        $validated = $request->validate([
            'table_reference' => 'required|string|max:40',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.dish_id' => 'required|integer|distinct',
            'items.*.quantity' => 'required|integer|min:1|max:99',
        ]);

        $restaurant = Restaurant::query()
            ->with('tables')
            ->where('slug', $restaurant_slug)
            ->firstOrFail();

        $access = $this->tableSessionAccessService->authorizeRequestForRestaurant(
            $request,
            $restaurant,
            $validated['table_reference']
        );
        $session = $this->guestMenuSessionService->resolveActiveSession($access->table_session_id);

        $order = $this->createOrderForTableContext(
            $session->restaurant,
            $session->restaurantTable,
            $session,
            $validated,
            $invoiceCalculator
        );

        return response()->json([
            'message' => __('messages.orders.created'),
            'order' => $this->formatOrder($order),
        ], 201);
    }

    public function storeForSession(
        Request $request,
        TableSession $tableSession,
        OrderInvoiceCalculator $invoiceCalculator
    ): JsonResponse {
        $session = $this->guestMenuSessionService->resolveActiveSession($tableSession->id);

        $validated = $request->validate([
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.dish_id' => 'required|integer|distinct',
            'items.*.quantity' => 'required|integer|min:1|max:99',
        ]);

        $order = $this->createOrderForTableContext(
            $session->restaurant,
            $session->restaurantTable,
            $session,
            $validated,
            $invoiceCalculator
        );

        return response()->json([
            'message' => __('messages.orders.created'),
            'order' => $this->formatOrder($order),
        ], 201);
    }

    public function indexForSession(TableSession $tableSession): JsonResponse
    {
        $session = $this->guestMenuSessionService->resolveActiveSession($tableSession->id);

        $orders = Order::query()
            ->where('table_session_id', $session->id)
            ->with(['restaurant', 'restaurantTable', 'items', 'confirmedBy', 'cancelledBy', 'accountedBy'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'orders' => $orders->map(fn (Order $order) => $this->formatOrder($order))->values(),
        ]);
    }

    public function pendingConfirmation(Request $request): JsonResponse
    {
        $user = $request->user();
        $restaurant = $this->getRestaurantForRequest($request);

        $ordersQuery = Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('status', Order::STATUS_PENDING_STAFF_CONFIRMATION)
            ->with(['restaurant', 'restaurantTable', 'items'])
            ->latest();

        if ($user->isStaff()) {
            $assignedTableIds = $this->getAccessibleStaffTableIds($user, $restaurant);

            if ($assignedTableIds === []) {
                return response()->json([
                    'orders' => [],
                ]);
            }

            $ordersQuery->whereIn('restaurant_table_id', $assignedTableIds);
        }

        $orders = $ordersQuery->get();

        return response()->json([
            'orders' => $orders->map(fn (Order $order) => $this->formatOrder($order))->values(),
        ]);
    }

    public function publishedDishes(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        $dishes = Dish::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('status', 'published')
            ->orderBy('category')
            ->orderBy('name')
            ->get(['id', 'name', 'price', 'category'])
            ->map(fn (Dish $dish) => [
                'id' => $dish->id,
                'name' => $dish->name,
                'price' => (float) $dish->price,
                'category' => $dish->category,
            ])
            ->values();

        return response()->json([
            'dishes' => $dishes,
        ]);
    }

    public function update(
        Request $request,
        Order $order,
        OrderInvoiceCalculator $invoiceCalculator
    ): JsonResponse {
        $user = $request->user();
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertOrderBelongsToRestaurant($order, $restaurant);
        $this->assertStaffCanAccessOrder($user, $order, $restaurant);

        if ($order->status !== Order::STATUS_PENDING_STAFF_CONFIRMATION) {
            return response()->json([
                'message' => __('messages.orders.edit_only_pending'),
            ], 422);
        }

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.dish_id' => 'required|integer|distinct',
            'items.*.quantity' => 'required|integer|min:1|max:99',
        ]);

        $order->loadMissing('items', 'restaurant', 'restaurantTable');

        $preparedItems = $this->preparePendingOrderUpdateItems(
            $restaurant,
            $order,
            $validated['items']
        );

        $invoice = $invoiceCalculator->calculate($preparedItems);

        $order = DB::transaction(function () use ($order, $preparedItems, $invoice) {
            $existingItemsByDishId = $order->items
                ->filter(fn (OrderItem $item) => $item->dish_id !== null)
                ->keyBy(fn (OrderItem $item) => (string) $item->dish_id);

            $retainedItemIds = [];

            foreach ($preparedItems as $preparedItem) {
                $dishId = (int) $preparedItem['dish_id'];
                $existingItem = $existingItemsByDishId->get((string) $dishId);

                if ($existingItem) {
                    $existingItem->update([
                        'quantity' => $preparedItem['quantity'],
                        'line_subtotal' => $preparedItem['line_subtotal'],
                    ]);

                    $retainedItemIds[] = $existingItem->id;
                    continue;
                }

                $createdItem = $order->items()->create($preparedItem);
                $retainedItemIds[] = $createdItem->id;
            }

            $order->items()
                ->whereNotIn('id', $retainedItemIds)
                ->delete();

            $order->update($invoice);

            return $order->fresh(['restaurant', 'restaurantTable', 'items']);
        });

        return response()->json([
            'message' => __('messages.orders.updated'),
            'order' => $this->formatOrder($order),
        ]);
    }

    public function confirm(Request $request, Order $order): JsonResponse
    {
        $user = $request->user();
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertOrderBelongsToRestaurant($order, $restaurant);
        $this->assertStaffCanAccessOrder($user, $order, $restaurant);

        if ($order->status !== Order::STATUS_PENDING_STAFF_CONFIRMATION) {
            return response()->json([
                'message' => __('messages.orders.confirm_only_pending'),
            ], 422);
        }

        $order->update([
            'status' => Order::STATUS_STAFF_CONFIRMED,
            'confirmed_by' => $request->user()->id,
            'confirmed_at' => now(),
        ]);

        $order = $order->fresh(['restaurant', 'restaurantTable', 'items', 'confirmedBy']);

        return response()->json([
            'message' => __('messages.orders.confirmed'),
            'order' => $this->formatOrder($order),
        ]);
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        $user = $request->user();
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertOrderBelongsToRestaurant($order, $restaurant);
        $this->assertStaffCanAccessOrder($user, $order, $restaurant);

        if ($order->status !== Order::STATUS_PENDING_STAFF_CONFIRMATION) {
            return response()->json([
                'message' => __('messages.orders.cancel_only_pending'),
            ], 422);
        }

        $order->update([
            'status' => Order::STATUS_STAFF_CANCELLED,
            'cancelled_by' => $request->user()->id,
            'cancelled_at' => now(),
        ]);

        $order = $order->fresh(['restaurant', 'restaurantTable', 'items', 'cancelledBy']);

        return response()->json([
            'message' => __('messages.orders.cancelled'),
            'order' => $this->formatOrder($order),
        ]);
    }

    public function accounting(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        $orders = Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('status', Order::STATUS_STAFF_CONFIRMED)
            ->with(['restaurant', 'restaurantTable', 'items', 'confirmedBy'])
            ->orderByDesc('confirmed_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'orders' => $orders->map(fn (Order $order) => $this->formatOrder($order))->values(),
        ]);
    }

    public function account(
        Request $request,
        Order $order,
        OrderInvoiceCalculator $invoiceCalculator
    ): JsonResponse {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertOrderBelongsToRestaurant($order, $restaurant);

        if ($order->status !== Order::STATUS_STAFF_CONFIRMED) {
            return response()->json([
                'message' => __('messages.orders.account_only_confirmed'),
            ], 422);
        }

        $validated = $request->validate([
            'vat_rate' => 'nullable|numeric|min:0|max:100',
            'discount_type' => 'nullable|in:fixed,percentage',
            'discount_value' => 'nullable|numeric|min:0',
        ]);

        $discountType = $validated['discount_type'] ?? null;
        $discountValue = (float) ($validated['discount_value'] ?? 0);

        if ($discountType === null && $discountValue > 0) {
            throw ValidationException::withMessages([
                'discount_type' => __('messages.orders.discount_type_required'),
            ]);
        }

        $order->loadMissing('items', 'restaurant', 'restaurantTable', 'confirmedBy', 'accountedBy');

        $invoice = $invoiceCalculator->calculate(
            $order->items->map(fn (OrderItem $item) => [
                'unit_price' => $item->unit_price,
                'quantity' => $item->quantity,
            ]),
            (float) ($validated['vat_rate'] ?? 0),
            $discountType,
            $discountValue
        );

        $order = DB::transaction(function () use ($request, $order, $invoice) {
            $order->update([
                'status' => Order::STATUS_ACCOUNTED,
                'invoice_number' => $order->invoice_number ?: $this->formatInvoiceNumber($order),
                'accounted_by' => $request->user()->id,
                'accounted_at' => now(),
                ...$invoice,
            ]);

            return $order->fresh(['restaurant', 'restaurantTable', 'items', 'confirmedBy', 'accountedBy']);
        });

        return response()->json([
            'message' => __('messages.orders.accounted'),
            'order' => $this->formatOrder($order),
        ]);
    }

    private function preparePendingOrderUpdateItems(Restaurant $restaurant, Order $order, array $requestedItems): array
    {
        if ($order->items->contains(fn (OrderItem $item) => $item->dish_id === null)) {
            throw ValidationException::withMessages([
                'items' => 'This order contains legacy items and cannot be edited.',
            ]);
        }

        $existingItemsByDishId = $order->items
            ->keyBy(fn (OrderItem $item) => (string) $item->dish_id);

        $newDishIds = collect($requestedItems)
            ->pluck('dish_id')
            ->map(fn ($dishId) => (int) $dishId)
            ->filter(fn (int $dishId) => ! $existingItemsByDishId->has((string) $dishId))
            ->values()
            ->all();

        $publishedDishes = $newDishIds === []
            ? collect()
            : Dish::query()
                ->where('restaurant_id', $restaurant->id)
                ->where('status', 'published')
                ->whereIn('id', $newDishIds)
                ->get()
                ->keyBy('id');

        return array_map(function (array $item) use ($existingItemsByDishId, $publishedDishes): array {
            $dishId = (int) $item['dish_id'];
            $quantity = (int) $item['quantity'];
            $existingItem = $existingItemsByDishId->get((string) $dishId);

            if ($existingItem) {
                $unitPrice = (float) $existingItem->unit_price;

                return [
                    'dish_id' => $dishId,
                    'dish_name' => $existingItem->dish_name,
                    'unit_price' => number_format($unitPrice, 2, '.', ''),
                    'quantity' => $quantity,
                    'line_subtotal' => number_format($unitPrice * $quantity, 2, '.', ''),
                ];
            }

            $dish = $publishedDishes->get($dishId);

            if (! $dish) {
                throw ValidationException::withMessages([
                    'items' => 'Orders can only include existing order items or currently published dishes from this restaurant.',
                ]);
            }

            $unitPrice = (float) $dish->price;

            return [
                'dish_id' => $dish->id,
                'dish_name' => $dish->name,
                'unit_price' => number_format($unitPrice, 2, '.', ''),
                'quantity' => $quantity,
                'line_subtotal' => number_format($unitPrice * $quantity, 2, '.', ''),
            ];
        }, $requestedItems);
    }

    private function prepareOrderItems(Restaurant $restaurant, array $requestedItems): array
    {
        $dishIds = collect($requestedItems)
            ->pluck('dish_id')
            ->map(fn ($dishId) => (int) $dishId)
            ->all();

        $dishes = Dish::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('status', 'published')
            ->whereIn('id', $dishIds)
            ->get()
            ->keyBy('id');

        if (count($dishIds) !== $dishes->count()) {
            throw ValidationException::withMessages([
                'items' => 'Orders can only include published dishes from the selected restaurant.',
            ]);
        }

        return array_map(function (array $item) use ($dishes): array {
            $dish = $dishes->get((int) $item['dish_id']);
            $quantity = (int) $item['quantity'];
            $unitPrice = (float) $dish->price;

            return [
                'dish_id' => $dish->id,
                'dish_name' => $dish->name,
                'unit_price' => number_format($unitPrice, 2, '.', ''),
                'quantity' => $quantity,
                'line_subtotal' => number_format($unitPrice * $quantity, 2, '.', ''),
            ];
        }, $requestedItems);
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

    private function assertOrderBelongsToRestaurant(Order $order, Restaurant $restaurant): void
    {
        if ($order->restaurant_id !== $restaurant->id) {
            abort(404);
        }
    }

    private function assertStaffCanAccessOrder(User $user, Order $order, Restaurant $restaurant): void
    {
        if (! $user->isStaff()) {
            return;
        }

        $assignedTableIds = $this->getAccessibleStaffTableIds($user, $restaurant);

        if (
            $order->restaurant_table_id === null
            || ! in_array($order->restaurant_table_id, $assignedTableIds, true)
        ) {
            abort(403, 'This staff account is not assigned to that table.');
        }
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

    private function formatOrder(Order $order): array
    {
        $order->loadMissing('restaurant', 'restaurantTable', 'tableSession', 'items', 'confirmedBy', 'cancelledBy', 'accountedBy');

        return [
            'id' => $order->id,
            'uuid' => $order->uuid,
            'order_number' => $order->order_number,
            'invoice_number' => $order->invoice_number,
            'status' => $order->status,
            'table_session_id' => $order->table_session_id,
            'table_reference' => $order->table_reference ?: $order->guest_name,
            'table' => $order->restaurantTable ? [
                'id' => $order->restaurantTable->id,
                'name' => $order->restaurantTable->name,
            ] : null,
            'notes' => $order->notes,
            'created_at' => $order->created_at?->toIso8601String(),
            'confirmed_at' => $order->confirmed_at?->toIso8601String(),
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'accounted_at' => $order->accounted_at?->toIso8601String(),
            'restaurant' => [
                'id' => $order->restaurant->id,
                'name' => $order->restaurant->name,
                'slug' => $order->restaurant->slug,
            ],
            'items' => $order->items->map(fn (OrderItem $item) => [
                'id' => $item->id,
                'dish_id' => $item->dish_id,
                'dish_name' => $item->dish_name,
                'unit_price' => $item->unit_price,
                'quantity' => $item->quantity,
                'line_subtotal' => $item->line_subtotal,
            ])->values(),
            'invoice' => [
                'subtotal' => $order->subtotal,
                'discount_type' => $order->discount_type,
                'discount_value' => $order->discount_value,
                'discount_amount' => $order->discount_amount,
                'taxable_subtotal' => $order->taxable_subtotal,
                'vat_rate' => $order->vat_rate,
                'vat_amount' => $order->vat_amount,
                'total' => $order->total,
            ],
            'confirmed_by' => $this->formatActor($order->confirmedBy),
            'cancelled_by' => $this->formatActor($order->cancelledBy),
            'accounted_by' => $this->formatActor($order->accountedBy),
        ];
    }

    private function formatActor(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
        ];
    }

    private function formatOrderNumber(Order $order): string
    {
        return 'ORD-'.now()->format('Ymd').'-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT);
    }

    private function formatInvoiceNumber(Order $order): string
    {
        return 'INV-'.now()->format('Ymd').'-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT);
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function createOrderForTableContext(
        Restaurant $restaurant,
        RestaurantTable $restaurantTable,
        ?TableSession $tableSession,
        array $validated,
        OrderInvoiceCalculator $invoiceCalculator
    ): Order {
        $preparedItems = $this->prepareOrderItems($restaurant, $validated['items']);
        $invoice = $invoiceCalculator->calculate($preparedItems);

        return DB::transaction(function () use ($restaurant, $restaurantTable, $tableSession, $validated, $preparedItems, $invoice) {
            $order = $restaurant->orders()->create([
                'uuid' => (string) Str::uuid(),
                'restaurant_table_id' => $restaurantTable->id,
                'table_session_id' => $tableSession?->id,
                'status' => Order::STATUS_PENDING_STAFF_CONFIRMATION,
                'guest_name' => $restaurantTable->name,
                'table_reference' => $restaurantTable->name,
                'notes' => $this->normalizeOptionalString($validated['notes'] ?? null),
                ...$invoice,
            ]);

            $order->items()->createMany(array_map(
                fn (array $item) => [
                    'dish_id' => $item['dish_id'],
                    'dish_name' => $item['dish_name'],
                    'unit_price' => $item['unit_price'],
                    'quantity' => $item['quantity'],
                    'line_subtotal' => $item['line_subtotal'],
                ],
                $preparedItems
            ));

            $order->update([
                'order_number' => $this->formatOrderNumber($order),
            ]);

            return $order->fresh(['restaurant', 'restaurantTable', 'tableSession', 'items']);
        });
    }
}
