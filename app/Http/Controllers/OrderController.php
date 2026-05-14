<?php

namespace App\Http\Controllers;

use App\Events\AccountingOrderCreated;
use App\Events\AccountingOrderRemoved;
use App\Events\KitchenOrderCreated;
use App\Events\KitchenOrderReady;
use App\Events\KitchenOrderUpdated;
use App\Models\Dish;
use App\Models\ChatOrder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Models\TableSession;
use App\Models\User;
use App\Services\DishAlternativeSuggestionService;
use App\Services\GuestMenuSessionService;
use App\Services\MobilePushNotificationService;
use App\Services\OrderInventoryDeductionService;
use App\Services\OrderInvoiceCalculator;
use App\Services\StaffCapabilityService;
use App\Services\TenantRestaurantResolver;
use App\Services\TableSessionAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function __construct(
        private readonly GuestMenuSessionService $guestMenuSessionService,
        private readonly TableSessionAccessService $tableSessionAccessService,
        private readonly OrderInventoryDeductionService $orderInventoryDeductionService,
        private readonly DishAlternativeSuggestionService $dishAlternativeSuggestionService,
        private readonly TenantRestaurantResolver $tenantRestaurantResolver,
        private readonly StaffCapabilityService $staffCapabilityService,
    ) {
    }

    public function store(
        Request $request,
        OrderInvoiceCalculator $invoiceCalculator,
        ?string $restaurant_slug = null
    ): JsonResponse {
        $validated = $request->validate([
            'table_reference' => 'required|string|max:40',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.dish_id' => 'required|integer|distinct',
            'items.*.quantity' => 'required|integer|min:1|max:99',
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

        $order = $this->createOrderForTableContext(
            $session->restaurant,
            $session->restaurantTable,
            $session,
            $validated,
            $invoiceCalculator
        );
        $this->dispatchPendingOrderCreatedAlerts($order);

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
        $this->dispatchPendingOrderCreatedAlerts($order);

        return response()->json([
            'message' => __('messages.orders.created'),
            'order' => $this->formatOrder($order),
        ], 201);
    }

    public function storeChatOrder(Request $request): JsonResponse
    {
        if (! feature_enabled('ai_chatbot')) {
            return response()->json([
                'message' => 'AI chatbot is disabled for this restaurant.',
            ], 403);
        }

        if (! $request->isJson()) {
            return response()->json([
                'message' => 'Invalid content type. Expected application/json.',
            ], 415);
        }

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.name' => 'required|string|max:255',
            'items.*.quantity' => 'required|integer|min:1|max:99',
        ]);

        $sessionId = $request->hasSession() ? $request->session()->getId() : null;
        $sessionScope = is_string($sessionId) && $sessionId !== ''
            ? $sessionId
            : (($request->ip() ?: 'unknown').'|'.substr((string) $request->userAgent(), 0, 64));

        $fingerprint = sha1(json_encode($validated['items'], JSON_UNESCAPED_UNICODE));
        $lockKey = 'chat-order:'.$sessionScope.':'.$fingerprint;

        if (! Cache::add($lockKey, 1, now()->addSeconds(15))) {
            return response()->json([
                'message' => 'Duplicate order detected. Please wait a moment.',
            ], 429);
        }

        $chatOrder = ChatOrder::query()->create([
            'items' => $validated['items'],
            'status' => 'pending',
            'user_session_id' => is_string($sessionId) && $sessionId !== '' ? $sessionId : null,
        ]);

        return response()->json([
            'message' => 'Chat order saved successfully.',
            'order' => [
                'id' => $chatOrder->id,
                'items' => $chatOrder->items,
                'status' => $chatOrder->status,
                'user_session_id' => $chatOrder->user_session_id,
                'created_at' => $chatOrder->created_at?->toIso8601String(),
                'updated_at' => $chatOrder->updated_at?->toIso8601String(),
            ],
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
            $assignedTableIds = $this->staffCapabilityService->assignedTableIds($user, $restaurant);
            $ordersQuery->where(function (Builder $query) use ($assignedTableIds): void {
                if ($assignedTableIds !== []) {
                    $query->whereIn('restaurant_table_id', $assignedTableIds);
                }

                $query->orWhere(function (Builder $eventQuery): void {
                    $eventQuery->whereNull('restaurant_table_id')
                        ->where('table_reference', 'like', 'EVENT-%');
                });
            });
        }

        $orders = $ordersQuery->get();

        return response()->json([
            'orders' => $orders->map(fn (Order $order) => $this->formatOrder($order))->values(),
        ]);
    }

    public function kitchenActiveOrders(Request $request): JsonResponse
    {
        $user = $request->user();
        $restaurant = $this->getRestaurantForRequest($request);
        $statusFilter = $request->query('status');

        $allowedKitchenStatuses = [
            Order::KITCHEN_STATUS_NEW,
            Order::KITCHEN_STATUS_IN_PROGRESS,
            Order::KITCHEN_STATUS_READY,
        ];

        $ordersQuery = Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('status', Order::STATUS_STAFF_CONFIRMED)
            ->whereNotNull('kitchen_status')
            ->whereIn('kitchen_status', $allowedKitchenStatuses)
            ->with(['restaurant', 'restaurantTable', 'tableSession', 'items', 'confirmedBy'])
            ->orderByRaw(
                "CASE kitchen_status WHEN 'new' THEN 0 WHEN 'in_progress' THEN 1 WHEN 'ready' THEN 2 ELSE 3 END"
            )
            ->orderBy('confirmed_at')
            ->orderBy('created_at');

        if ($user->isStaff()) {
            $assignedTableIds = $this->staffCapabilityService->assignedTableIds($user, $restaurant);
            $ordersQuery->where(function (Builder $query) use ($assignedTableIds): void {
                if ($assignedTableIds !== []) {
                    $query->whereIn('restaurant_table_id', $assignedTableIds);
                }

                $query->orWhere(function (Builder $eventQuery): void {
                    $eventQuery->whereNull('restaurant_table_id')
                        ->where('table_reference', 'like', 'EVENT-%');
                });
            });
        }

        if (is_string($statusFilter) && $statusFilter !== '' && in_array($statusFilter, $allowedKitchenStatuses, true)) {
            $ordersQuery->where('kitchen_status', $statusFilter);
        }

        $orders = $ordersQuery->get();

        return response()->json([
            'orders' => $orders->map(fn (Order $order) => $this->formatKitchenOrder($order))->values(),
        ]);
    }

    public function kitchenOrderDetails(Request $request, Order $order): JsonResponse
    {
        $user = $request->user();
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertOrderBelongsToRestaurant($order, $restaurant);
        $this->staffCapabilityService->assertCanAccessOrder($user, $restaurant, $order);

        return response()->json([
            'order' => $this->formatKitchenOrder($order),
        ]);
    }

    public function startKitchenPreparation(Request $request, Order $order): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertOrderBelongsToRestaurant($order, $restaurant);

        if (
            $order->status !== Order::STATUS_STAFF_CONFIRMED
            || $order->kitchen_status !== Order::KITCHEN_STATUS_NEW
        ) {
            return response()->json([
                'message' => __('messages.orders.kitchen_start_only_new'),
            ], 422);
        }

        try {
            $order = DB::transaction(function () use ($order, $request) {
                /** @var Order $lockedOrder */
                $lockedOrder = Order::query()
                    ->whereKey($order->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    $lockedOrder->status !== Order::STATUS_STAFF_CONFIRMED
                    || $lockedOrder->kitchen_status !== Order::KITCHEN_STATUS_NEW
                ) {
                    throw ValidationException::withMessages([
                        'order' => __('messages.orders.kitchen_start_only_new'),
                    ]);
                }

                $lockedOrder->update([
                    'kitchen_status' => Order::KITCHEN_STATUS_IN_PROGRESS,
                    'kitchen_started_at' => $lockedOrder->kitchen_started_at ?? now(),
                    'kitchen_updated_by' => $request->user()->id,
                ]);

                return $lockedOrder->fresh(['restaurant', 'restaurantTable', 'tableSession', 'items', 'confirmedBy']);
            });
        } catch (ValidationException $exception) {
            $firstError = collect($exception->errors())
                ->flatten()
                ->first();

            return response()->json([
                'message' => is_string($firstError) && $firstError !== ''
                    ? $firstError
                    : __('messages.orders.kitchen_start_only_new'),
                'errors' => $exception->errors(),
            ], 422);
        }

        $this->broadcastKitchenOrderUpdated($order);

        return response()->json([
            'message' => __('messages.orders.kitchen_started'),
            'order' => $this->formatKitchenOrder($order),
        ]);
    }

    public function markKitchenReady(Request $request, Order $order): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertOrderBelongsToRestaurant($order, $restaurant);

        if (
            $order->status !== Order::STATUS_STAFF_CONFIRMED
            || $order->kitchen_status !== Order::KITCHEN_STATUS_IN_PROGRESS
        ) {
            return response()->json([
                'message' => __('messages.orders.kitchen_ready_only_in_progress'),
            ], 422);
        }

        try {
            $order = DB::transaction(function () use ($order, $request) {
                /** @var Order $lockedOrder */
                $lockedOrder = Order::query()
                    ->whereKey($order->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    $lockedOrder->status !== Order::STATUS_STAFF_CONFIRMED
                    || $lockedOrder->kitchen_status !== Order::KITCHEN_STATUS_IN_PROGRESS
                ) {
                    throw ValidationException::withMessages([
                        'order' => __('messages.orders.kitchen_ready_only_in_progress'),
                    ]);
                }

                $lockedOrder->update([
                    'kitchen_status' => Order::KITCHEN_STATUS_READY,
                    'kitchen_ready_at' => now(),
                    'kitchen_updated_by' => $request->user()->id,
                ]);

                return $lockedOrder->fresh(['restaurant', 'restaurantTable', 'tableSession', 'items', 'confirmedBy']);
            });
        } catch (ValidationException $exception) {
            $firstError = collect($exception->errors())
                ->flatten()
                ->first();

            return response()->json([
                'message' => is_string($firstError) && $firstError !== ''
                    ? $firstError
                    : __('messages.orders.kitchen_ready_only_in_progress'),
                'errors' => $exception->errors(),
            ], 422);
        }

        $this->broadcastKitchenOrderUpdated($order, true);

        return response()->json([
            'message' => __('messages.orders.kitchen_ready'),
            'order' => $this->formatKitchenOrder($order),
        ]);
    }

    public function publishedDishes(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        $dishes = Dish::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('status', 'published')
            ->with('dishIngredients.ingredient')
            ->orderBy('category')
            ->orderBy('name')
            ->get(['id', 'name', 'price', 'category'])
            ->map(function (Dish $dish): array {
                $isOrderable = $dish->isOrderable();

                return [
                    'id' => $dish->id,
                    'name' => $dish->name,
                    'price' => (float) $dish->price,
                    'category' => $dish->category,
                    'is_orderable' => $isOrderable,
                    'is_out_of_stock' => ! $isOrderable,
                    'alternative_dishes' => $isOrderable
                        ? []
                        : $this->dishAlternativeSuggestionService
                            ->suggestForDish($dish, 4)
                            ->map(fn (Dish $alternativeDish) => [
                                'id' => $alternativeDish->id,
                                'name' => $alternativeDish->name,
                                'price' => (float) $alternativeDish->price,
                                'category' => $alternativeDish->category,
                            ])
                            ->values()
                            ->all(),
                ];
            })
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
        $this->staffCapabilityService->assertCanAccessOrder($user, $restaurant, $order);

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
        $this->staffCapabilityService->assertCanAccessOrder($user, $restaurant, $order);

        if ($order->status !== Order::STATUS_PENDING_STAFF_CONFIRMATION) {
            return response()->json([
                'message' => __('messages.orders.confirm_only_pending'),
            ], 422);
        }

        try {
            $order = DB::transaction(function () use ($order, $request, $restaurant) {
                /** @var Order $lockedOrder */
                $lockedOrder = Order::query()
                    ->whereKey($order->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedOrder->status !== Order::STATUS_PENDING_STAFF_CONFIRMATION) {
                    throw ValidationException::withMessages([
                        'order' => __('messages.orders.confirm_only_pending'),
                    ]);
                }

                $lockedOrder->update([
                    'status' => Order::STATUS_STAFF_CONFIRMED,
                    'confirmed_by' => $request->user()->id,
                    'confirmed_at' => now(),
                    'kitchen_status' => Order::KITCHEN_STATUS_NEW,
                    'kitchen_started_at' => null,
                    'kitchen_ready_at' => null,
                    'kitchen_completed_at' => null,
                    'kitchen_updated_by' => null,
                ]);

                if (feature_enabled('ingredient_stock_deduction', $restaurant)) {
                    $this->orderInventoryDeductionService->deductForConfirmedOrder(
                        $lockedOrder,
                        $request->user()->id
                    );
                }

                return $lockedOrder->fresh(['restaurant', 'restaurantTable', 'items', 'confirmedBy']);
            });
        } catch (ValidationException $exception) {
            $firstError = collect($exception->errors())
                ->flatten()
                ->first();

            return response()->json([
                'message' => is_string($firstError) && $firstError !== ''
                    ? $firstError
                    : __('messages.orders.confirm_only_pending'),
                'errors' => $exception->errors(),
            ], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => __('messages.orders.confirm_failed_inventory_guard'),
            ], 422);
        }

        $this->broadcastKitchenOrderCreated($order);
        $this->broadcastAccountingOrderCreated($order);

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
        $this->staffCapabilityService->assertCanAccessOrder($user, $restaurant, $order);

        if (
            $order->status !== Order::STATUS_PENDING_STAFF_CONFIRMATION
            && $order->status !== Order::STATUS_STAFF_CONFIRMED
        ) {
            return response()->json([
                'message' => __('messages.orders.cancel_only_pending_or_confirmed'),
            ], 422);
        }

        $wasConfirmedAtRequestStart = $order->status === Order::STATUS_STAFF_CONFIRMED;

        try {
            $order = DB::transaction(function () use ($order, $request, $restaurant) {
                /** @var Order $lockedOrder */
                $lockedOrder = Order::query()
                    ->whereKey($order->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    $lockedOrder->status !== Order::STATUS_PENDING_STAFF_CONFIRMATION
                    && $lockedOrder->status !== Order::STATUS_STAFF_CONFIRMED
                ) {
                    throw ValidationException::withMessages([
                        'order' => __('messages.orders.cancel_only_pending_or_confirmed'),
                    ]);
                }

                $wasConfirmed = $lockedOrder->status === Order::STATUS_STAFF_CONFIRMED;

                $lockedOrder->update([
                    'status' => Order::STATUS_STAFF_CANCELLED,
                    'cancelled_by' => $request->user()->id,
                    'cancelled_at' => now(),
                ]);

                if ($wasConfirmed && feature_enabled('ingredient_stock_deduction', $restaurant)) {
                    $this->orderInventoryDeductionService->restoreForCancelledOrder(
                        $lockedOrder,
                        $request->user()->id
                    );
                }

                return $lockedOrder->fresh(['restaurant', 'restaurantTable', 'items', 'cancelledBy']);
            });
        } catch (ValidationException $exception) {
            $firstError = collect($exception->errors())
                ->flatten()
                ->first();

            return response()->json([
                'message' => is_string($firstError) && $firstError !== ''
                    ? $firstError
                    : __('messages.orders.cancel_only_pending_or_confirmed'),
                'errors' => $exception->errors(),
            ], 422);
        }

        if ($wasConfirmedAtRequestStart) {
            $this->broadcastAccountingOrderRemoved($order);
        }

        return response()->json([
            'message' => __('messages.orders.cancelled'),
            'order' => $this->formatOrder($order),
        ]);
    }

    public function markServed(Request $request, Order $order): JsonResponse
    {
        $user = $request->user();
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertOrderBelongsToRestaurant($order, $restaurant);
        $this->staffCapabilityService->assertCanAccessOrder($user, $restaurant, $order);

        if (
            $order->status !== Order::STATUS_STAFF_CONFIRMED
            || ! in_array($order->kitchen_status, [Order::KITCHEN_STATUS_IN_PROGRESS, Order::KITCHEN_STATUS_READY], true)
        ) {
            return response()->json([
                'message' => 'Only confirmed in-progress/ready orders can be marked as served.',
            ], 422);
        }

        $order->update([
            'kitchen_status' => Order::KITCHEN_STATUS_SERVED,
            'kitchen_completed_at' => now(),
            'kitchen_updated_by' => $user->id,
        ]);

        return response()->json([
            'message' => 'Order marked as served.',
            'order' => $this->formatOrder($order->fresh()),
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

        $this->broadcastAccountingOrderRemoved($order);

        return response()->json([
            'message' => __('messages.orders.accounted'),
            'order' => $this->formatOrder($order),
        ]);
    }

    public function quickCheckout(Request $request, OrderInvoiceCalculator $invoiceCalculator): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        $validated = $request->validate([
            'table_reference' => 'nullable|string|max:40',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.dish_id' => 'required|integer|distinct',
            'items.*.quantity' => 'required|integer|min:1|max:99',
            'vat_rate' => 'nullable|numeric|min:0|max:100',
            'discount_type' => 'nullable|in:fixed,percentage',
            'discount_value' => 'nullable|numeric|min:0',
            'payment_method' => 'required|in:cash,card,wallet',
            'amount_received' => 'nullable|numeric|min:0',
        ]);

        $discountType = $validated['discount_type'] ?? null;
        $discountValue = (float) ($validated['discount_value'] ?? 0);

        if ($discountType === null && $discountValue > 0) {
            throw ValidationException::withMessages([
                'discount_type' => __('messages.orders.discount_type_required'),
            ]);
        }

        [$restaurantTable, $tableReference] = $this->resolvePosTableReference(
            $restaurant,
            $validated['table_reference'] ?? null
        );

        $preparedItems = $this->prepareOrderItems($restaurant, $validated['items']);
        $invoice = $invoiceCalculator->calculate(
            $preparedItems,
            (float) ($validated['vat_rate'] ?? 0),
            $discountType,
            $discountValue
        );

        $paymentMethod = (string) $validated['payment_method'];
        $totalAmount = (float) $invoice['total'];
        $amountReceived = (float) ($validated['amount_received'] ?? 0);

        if ($paymentMethod === 'cash' && ($amountReceived + 0.0005) < $totalAmount) {
            return response()->json([
                'message' => __('messages.orders.pos_cash_insufficient'),
            ], 422);
        }

        $userId = (int) $request->user()->id;

        try {
            $order = DB::transaction(function () use (
                $restaurant,
                $restaurantTable,
                $tableReference,
                $validated,
                $preparedItems,
                $invoice,
                $userId
            ) {
                $order = $restaurant->orders()->create([
                    'uuid' => (string) Str::uuid(),
                    'restaurant_table_id' => $restaurantTable?->id,
                    'table_session_id' => null,
                    'status' => Order::STATUS_STAFF_CONFIRMED,
                    'guest_name' => $tableReference,
                    'table_reference' => $tableReference,
                    'notes' => $this->normalizeOptionalString($validated['notes'] ?? null),
                    'confirmed_by' => $userId,
                    'confirmed_at' => now(),
                    ...$invoice,
                ]);

                $order->items()->createMany(array_map(
                    fn (array $item): array => [
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

                if (feature_enabled('ingredient_stock_deduction', $restaurant)) {
                    $this->orderInventoryDeductionService->deductForConfirmedOrder($order, $userId);
                }

                $order->update([
                    'status' => Order::STATUS_ACCOUNTED,
                    'invoice_number' => $this->formatInvoiceNumber($order),
                    'accounted_by' => $userId,
                    'accounted_at' => now(),
                ]);

                return $order->fresh(['restaurant', 'restaurantTable', 'items', 'confirmedBy', 'accountedBy']);
            });
        } catch (ValidationException $exception) {
            $firstError = collect($exception->errors())
                ->flatten()
                ->first();

            return response()->json([
                'message' => is_string($firstError) && $firstError !== ''
                    ? $firstError
                    : __('messages.orders.confirm_failed_inventory_guard'),
                'errors' => $exception->errors(),
            ], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => __('messages.orders.confirm_failed_inventory_guard'),
            ], 422);
        }

        $effectiveAmountReceived = $paymentMethod === 'cash' ? $amountReceived : $totalAmount;
        $changeDue = $paymentMethod === 'cash'
            ? max($effectiveAmountReceived - $totalAmount, 0)
            : 0.0;

        return response()->json([
            'message' => __('messages.orders.pos_checkout_completed'),
            'order' => $this->formatOrder($order),
            'payment' => [
                'method' => $paymentMethod,
                'amount_received' => number_format($effectiveAmountReceived, 2, '.', ''),
                'change_due' => number_format($changeDue, 2, '.', ''),
                'total' => number_format($totalAmount, 2, '.', ''),
            ],
        ], 201);
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

        $requestedDishIds = collect($requestedItems)
            ->pluck('dish_id')
            ->map(fn ($dishId) => (int) $dishId)
            ->values()
            ->all();

        $publishedDishes = $requestedDishIds === []
            ? collect()
            : Dish::query()
                ->where('restaurant_id', $restaurant->id)
                ->where('status', 'published')
                ->whereIn('id', $requestedDishIds)
                ->with('dishIngredients.ingredient')
                ->get()
                ->keyBy('id');

        return array_map(function (array $item) use ($existingItemsByDishId, $publishedDishes): array {
            $dishId = (int) $item['dish_id'];
            $quantity = (int) $item['quantity'];
            $existingItem = $existingItemsByDishId->get((string) $dishId);

            if ($existingItem) {
                $publishedDish = $publishedDishes->get($dishId);
                if ($publishedDish) {
                    $this->ensureDishIsOrderable($publishedDish);
                }

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

            $this->ensureDishIsOrderable($dish);

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
            ->with('dishIngredients.ingredient')
            ->get()
            ->keyBy('id');

        if (count($dishIds) !== $dishes->count()) {
            throw ValidationException::withMessages([
                'items' => 'Orders can only include published dishes from the selected restaurant.',
            ]);
        }

        $unavailableDishNames = $dishes
            ->filter(fn (Dish $dish) => ! $dish->isOrderable())
            ->pluck('name')
            ->values();

        if ($unavailableDishNames->isNotEmpty()) {
            throw ValidationException::withMessages([
                'items' => __('messages.orders.dishes_out_of_stock', [
                    'dishes' => $unavailableDishNames->implode(', '),
                ]),
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

    private function getAccessibleStaffTableIds(User $user, Restaurant $restaurant): array
    {
        return $this->staffCapabilityService->assignedTableIds($user, $restaurant);
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
            'kitchen_status' => $order->kitchen_status,
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
            'kitchen_started_at' => $order->kitchen_started_at?->toIso8601String(),
            'kitchen_ready_at' => $order->kitchen_ready_at?->toIso8601String(),
            'kitchen_completed_at' => $order->kitchen_completed_at?->toIso8601String(),
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

    private function formatKitchenOrder(Order $order): array
    {
        $orderPayload = $this->formatOrder($order);

        return [
            ...$orderPayload,
            'guest_identifier' => $order->table_session_id
                ? 'session-'.$order->table_session_id
                : ($order->guest_name ?: null),
            'time_ordered' => $order->confirmed_at?->toIso8601String()
                ?? $order->created_at?->toIso8601String(),
            'waiter_name' => $order->confirmedBy?->name,
            'special_requests' => $order->notes,
            'items' => collect($orderPayload['items'])
                ->map(fn (array $item) => [
                    ...$item,
                    'modifiers' => [],
                    'dish_notes' => null,
                ])
                ->values()
                ->all(),
        ];
    }

    private function broadcastKitchenOrderCreated(Order $order): void
    {
        $payload = $this->formatKitchenOrder($order);

        try {
            event(new KitchenOrderCreated($order, $payload));
        } catch (Throwable $exception) {
            Log::warning('Failed to broadcast a kitchen-order.created event.', [
                'order_id' => $order->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function broadcastAccountingOrderCreated(Order $order): void
    {
        $payload = $this->formatOrder($order);

        try {
            event(new AccountingOrderCreated($order, $payload));
        } catch (Throwable $exception) {
            Log::warning('Failed to broadcast an accounting-order.created event.', [
                'order_id' => $order->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function broadcastAccountingOrderRemoved(Order $order): void
    {
        $payload = $this->formatOrder($order);

        try {
            event(new AccountingOrderRemoved($order, $payload));
        } catch (Throwable $exception) {
            Log::warning('Failed to broadcast an accounting-order.removed event.', [
                'order_id' => $order->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function broadcastKitchenOrderUpdated(Order $order, bool $includeReadyEvent = false): void
    {
        $payload = $this->formatKitchenOrder($order);

        try {
            event(new KitchenOrderUpdated($order, $payload));
        } catch (Throwable $exception) {
            Log::warning('Failed to broadcast a kitchen-order.updated event.', [
                'order_id' => $order->id,
                'message' => $exception->getMessage(),
            ]);
        }

        if (! $includeReadyEvent) {
            return;
        }

        try {
            event(new KitchenOrderReady($order, $payload));
        } catch (Throwable $exception) {
            Log::warning('Failed to broadcast a kitchen-order.ready event.', [
                'order_id' => $order->id,
                'message' => $exception->getMessage(),
            ]);
        }
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

    /**
     * @return array{0: RestaurantTable|null, 1: string}
     */
    private function resolvePosTableReference(Restaurant $restaurant, mixed $requestedReference): array
    {
        $normalizedReference = is_string($requestedReference)
            ? trim($requestedReference)
            : '';

        if ($normalizedReference === '') {
            return [null, 'POS-WALK-IN'];
        }

        $restaurantTable = RestaurantTable::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('name', $normalizedReference)
            ->first();

        return [$restaurantTable, $normalizedReference];
    }

    private function ensureDishIsOrderable(Dish $dish): void
    {
        if ($dish->isOrderable()) {
            return;
        }

        throw ValidationException::withMessages([
            'items' => __('messages.orders.dishes_out_of_stock', [
                'dishes' => $dish->name,
            ]),
        ]);
    }

    private function dispatchPendingOrderCreatedAlerts(Order $order): void
    {
        try {
            app(MobilePushNotificationService::class)->notifyPendingOrderCreated($order);
        } catch (Throwable $exception) {
            Log::warning('Failed to send mobile push notifications for a pending order.', [
                'order_id' => $order->id,
                'message' => $exception->getMessage(),
            ]);
        }
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
