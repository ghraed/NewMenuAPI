<?php

namespace App\Services;

use App\Models\Dish;
use App\Models\EventMenuItem;
use App\Models\EventOrderLink;
use App\Models\EventReservation;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\Restaurant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventReservationService
{
    public function __construct(
        private readonly ReservationAvailabilityService $reservationAvailabilityService,
        private readonly EventPlanningAlertService $eventPlanningAlertService,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function list(Restaurant $restaurant, array $filters): LengthAwarePaginator
    {
        $query = EventReservation::query()
            ->where('restaurant_id', $restaurant->id)
            ->with(['roomPlan', 'menuItems.dish', 'orderLinks.order'])
            ->orderBy('start_at');

        if (! empty($filters['date_from'])) {
            $query->where('start_at', '>=', $filters['date_from'].' 00:00:00');
        }

        if (! empty($filters['date_to'])) {
            $query->where('start_at', '<=', $filters['date_to'].' 23:59:59');
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        return $query->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(Restaurant $restaurant, array $payload): EventReservation
    {
        return DB::transaction(function () use ($restaurant, $payload): EventReservation {
            [$startAt, $endAt] = $this->buildRange((string) $payload['event_date'], (string) $payload['start_time'], (string) $payload['end_time']);
            $status = strtolower(trim((string) ($payload['status'] ?? EventReservation::STATUS_DRAFT)));

            $this->assertNoBlockingConflicts($restaurant, $startAt->toDateTimeString(), $endAt->toDateTimeString(), null);

            $eventReservation = EventReservation::query()->create([
                'restaurant_id' => $restaurant->id,
                'room_plan_id' => $payload['room_plan_id'] ?? null,
                'invoice_id' => $payload['invoice_id'] ?? null,
                'title' => trim((string) $payload['title']),
                'customer_name' => trim((string) $payload['customer_name']),
                'customer_phone' => trim((string) $payload['customer_phone']),
                'customer_email' => $this->normalizeOptionalString($payload['customer_email'] ?? null),
                'start_at' => $startAt,
                'end_at' => $endAt,
                'status' => in_array($status, EventReservation::supportedStatuses(), true) ? $status : EventReservation::STATUS_DRAFT,
                'notes' => $this->normalizeOptionalString($payload['notes'] ?? null),
            ]);

            $eventReservation->load(['roomPlan', 'menuItems.dish', 'orderLinks.order']);

            $this->eventPlanningAlertService->dispatchImmediateUpdate($eventReservation, 'created');

            return $eventReservation;
        });
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(Restaurant $restaurant, EventReservation $eventReservation, array $payload): EventReservation
    {
        $this->assertEventBelongsToRestaurant($eventReservation, $restaurant);

        return DB::transaction(function () use ($restaurant, $eventReservation, $payload): EventReservation {
            /** @var EventReservation $locked */
            $locked = EventReservation::query()
                ->where('restaurant_id', $restaurant->id)
                ->whereKey($eventReservation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $eventDate = (string) ($payload['event_date'] ?? $locked->start_at?->timezone(config('app.timezone'))->format('Y-m-d'));
            $startTime = (string) ($payload['start_time'] ?? $locked->start_at?->timezone(config('app.timezone'))->format('H:i'));
            $endTime = (string) ($payload['end_time'] ?? $locked->end_at?->timezone(config('app.timezone'))->format('H:i'));
            [$startAt, $endAt] = $this->buildRange($eventDate, $startTime, $endTime);
            $nextStatus = strtolower(trim((string) ($payload['status'] ?? $locked->status)));

            if (! in_array($nextStatus, EventReservation::supportedStatuses(), true)) {
                throw ValidationException::withMessages([
                    'status' => 'Unsupported event status.',
                ]);
            }

            if (in_array($nextStatus, [EventReservation::STATUS_DRAFT, EventReservation::STATUS_CONFIRMED], true)) {
                $this->assertNoBlockingConflicts(
                    $restaurant,
                    $startAt->toDateTimeString(),
                    $endAt->toDateTimeString(),
                    $locked->id
                );
            }

            $locked->update([
                'room_plan_id' => array_key_exists('room_plan_id', $payload) ? ($payload['room_plan_id'] ?? null) : $locked->room_plan_id,
                'invoice_id' => array_key_exists('invoice_id', $payload) ? ($payload['invoice_id'] ?? null) : $locked->invoice_id,
                'title' => array_key_exists('title', $payload) ? trim((string) $payload['title']) : $locked->title,
                'customer_name' => array_key_exists('customer_name', $payload) ? trim((string) $payload['customer_name']) : $locked->customer_name,
                'customer_phone' => array_key_exists('customer_phone', $payload) ? trim((string) $payload['customer_phone']) : $locked->customer_phone,
                'customer_email' => array_key_exists('customer_email', $payload)
                    ? $this->normalizeOptionalString($payload['customer_email'])
                    : $locked->customer_email,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'status' => $nextStatus,
                'notes' => array_key_exists('notes', $payload)
                    ? $this->normalizeOptionalString($payload['notes'])
                    : $locked->notes,
            ]);

            $updated = $locked->fresh(['roomPlan', 'menuItems.dish', 'orderLinks.order']);
            $this->eventPlanningAlertService->dispatchImmediateUpdate($updated, 'updated');

            return $updated;
        });
    }

    public function updateStatus(Restaurant $restaurant, EventReservation $eventReservation, string $status): EventReservation
    {
        return $this->update($restaurant, $eventReservation, ['status' => $status]);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function replaceMenuItems(Restaurant $restaurant, EventReservation $eventReservation, array $items): EventReservation
    {
        $this->assertEventBelongsToRestaurant($eventReservation, $restaurant);

        return DB::transaction(function () use ($restaurant, $eventReservation, $items): EventReservation {
            /** @var EventReservation $locked */
            $locked = EventReservation::query()
                ->where('restaurant_id', $restaurant->id)
                ->whereKey($eventReservation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $dishIds = collect($items)
                ->pluck('dish_id')
                ->map(fn ($value) => (int) $value)
                ->filter(fn (int $dishId) => $dishId > 0)
                ->unique()
                ->values()
                ->all();

            $dishesById = Dish::query()
                ->where('restaurant_id', $restaurant->id)
                ->whereIn('id', $dishIds)
                ->get(['id', 'name', 'category'])
                ->keyBy('id');

            if ($dishesById->count() !== count($dishIds)) {
                throw ValidationException::withMessages([
                    'items' => 'One or more selected dishes do not belong to this restaurant.',
                ]);
            }

            EventMenuItem::query()
                ->where('event_reservation_id', $locked->id)
                ->delete();

            $rows = [];
            foreach ($items as $item) {
                $dishId = (int) $item['dish_id'];
                $plannedQuantity = (int) $item['planned_quantity'];
                if ($plannedQuantity < 1) {
                    continue;
                }

                /** @var Dish $dish */
                $dish = $dishesById->get($dishId);
                $rows[] = [
                    'event_reservation_id' => $locked->id,
                    'dish_id' => $dishId,
                    'planned_quantity' => $plannedQuantity,
                    'prep_notes' => $this->normalizeOptionalString($item['prep_notes'] ?? null),
                    'dish_name_snapshot' => $dish->name,
                    'category_snapshot' => $dish->category,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($rows !== []) {
                EventMenuItem::query()->insert($rows);
            }

            $updated = $locked->fresh(['roomPlan', 'menuItems.dish', 'orderLinks.order']);
            $this->eventPlanningAlertService->dispatchImmediateUpdate($updated, 'menu_items_updated');

            return $updated;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function forecastForEvent(Restaurant $restaurant, EventReservation $eventReservation): array
    {
        $this->assertEventBelongsToRestaurant($eventReservation, $restaurant);
        $eventReservation->loadMissing(['menuItems.dish.dishIngredients.ingredient']);

        $dishTotals = [];
        $ingredientTotals = [];

        foreach ($eventReservation->menuItems as $menuItem) {
            $dish = $menuItem->dish;
            if (! $dish) {
                continue;
            }

            $plannedQuantity = (int) $menuItem->planned_quantity;
            $dishTotals[] = [
                'dish_id' => $dish->id,
                'dish_name' => $menuItem->dish_name_snapshot ?: $dish->name,
                'category' => $menuItem->category_snapshot ?: $dish->category,
                'planned_quantity' => $plannedQuantity,
            ];

            foreach ($dish->dishIngredients as $dishIngredient) {
                $ingredient = $dishIngredient->ingredient;
                if (! $ingredient) {
                    continue;
                }

                $ingredientId = (int) $ingredient->id;
                $requiredQuantity = round((float) $dishIngredient->quantity * $plannedQuantity, 3);
                if ($requiredQuantity <= 0) {
                    continue;
                }

                if (! isset($ingredientTotals[$ingredientId])) {
                    $available = round((float) $ingredient->current_stock_quantity, 3);
                    $ingredientTotals[$ingredientId] = [
                        'ingredient_id' => $ingredientId,
                        'ingredient_name' => $ingredient->name,
                        'unit' => $ingredient->stock_unit,
                        'required_quantity' => 0.0,
                        'available_quantity' => $available,
                    ];
                }

                $ingredientTotals[$ingredientId]['required_quantity'] = round(
                    (float) $ingredientTotals[$ingredientId]['required_quantity'] + $requiredQuantity,
                    3
                );
            }
        }

        $ingredientRows = array_values(array_map(function (array $row): array {
            $shortage = max(0.0, round((float) $row['required_quantity'] - (float) $row['available_quantity'], 3));

            return [
                ...$row,
                'required_quantity' => number_format((float) $row['required_quantity'], 3, '.', ''),
                'available_quantity' => number_format((float) $row['available_quantity'], 3, '.', ''),
                'shortage_quantity' => number_format($shortage, 3, '.', ''),
                'is_shortage' => $shortage > 0,
            ];
        }, $ingredientTotals));

        $shortageCount = count(array_filter($ingredientRows, fn (array $row) => $row['is_shortage'] === true));

        return [
            'event_id' => $eventReservation->id,
            'dish_totals' => $dishTotals,
            'ingredient_totals' => $ingredientRows,
            'summary' => [
                'dish_count' => count($dishTotals),
                'ingredient_count' => count($ingredientRows),
                'shortage_count' => $shortageCount,
            ],
        ];
    }

    /**
     * @return array{order: Order, created: bool}
     */
    public function generateOrderDraft(Restaurant $restaurant, EventReservation $eventReservation): array
    {
        $this->assertEventBelongsToRestaurant($eventReservation, $restaurant);

        return DB::transaction(function () use ($restaurant, $eventReservation): array {
            /** @var EventReservation $locked */
            $locked = EventReservation::query()
                ->where('restaurant_id', $restaurant->id)
                ->whereKey($eventReservation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== EventReservation::STATUS_CONFIRMED) {
                throw ValidationException::withMessages([
                    'event' => 'Only confirmed events can generate an order draft.',
                ]);
            }

            $locked->loadMissing(['menuItems.dish', 'orderLinks.order']);

            if ($locked->menuItems->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'Event menu is empty. Add planned dishes before generating an order draft.',
                ]);
            }

            $existingLink = $locked->orderLinks()
                ->whereHas('order', fn (Builder $query) => $query->whereIn('status', [
                    Order::STATUS_PENDING_STAFF_CONFIRMATION,
                    Order::STATUS_STAFF_CONFIRMED,
                ]))
                ->with('order')
                ->latest('id')
                ->first();

            if ($existingLink && $existingLink->order) {
                return ['order' => $existingLink->order, 'created' => false];
            }

            $preparedItems = [];
            $subtotal = 0.0;

            foreach ($locked->menuItems as $menuItem) {
                $dish = $menuItem->dish;
                if (! $dish) {
                    continue;
                }

                $quantity = max(1, (int) $menuItem->planned_quantity);
                $unitPrice = round((float) $dish->price, 2);
                $lineSubtotal = round($unitPrice * $quantity, 2);
                $subtotal += $lineSubtotal;

                $preparedItems[] = [
                    'dish_id' => $dish->id,
                    'dish_name' => $dish->name,
                    'unit_price' => number_format($unitPrice, 2, '.', ''),
                    'quantity' => $quantity,
                    'line_subtotal' => number_format($lineSubtotal, 2, '.', ''),
                ];
            }

            if ($preparedItems === []) {
                throw ValidationException::withMessages([
                    'items' => 'Event menu items are invalid or unavailable for draft generation.',
                ]);
            }

            $eventLabel = 'EVENT-'.$locked->id.'-'.strtoupper(substr(md5((string) $locked->title), 0, 6));
            $subtotalFormatted = number_format($subtotal, 2, '.', '');

            $order = Order::query()->create([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'restaurant_id' => $restaurant->id,
                'restaurant_table_id' => null,
                'table_session_id' => null,
                'order_number' => null,
                'invoice_number' => null,
                'status' => Order::STATUS_PENDING_STAFF_CONFIRMATION,
                'kitchen_status' => null,
                'guest_name' => $eventLabel,
                'guest_phone' => $locked->customer_phone,
                'guest_email' => $locked->customer_email,
                'table_reference' => $eventLabel,
                'notes' => trim('Event draft: '.$locked->title),
                'vat_rate' => 0,
                'subtotal' => $subtotalFormatted,
                'discount_type' => null,
                'discount_value' => number_format(0, 2, '.', ''),
                'discount_amount' => number_format(0, 2, '.', ''),
                'taxable_subtotal' => $subtotalFormatted,
                'vat_amount' => number_format(0, 2, '.', ''),
                'total' => $subtotalFormatted,
            ]);

            $order->items()->createMany($preparedItems);
            $order->update([
                'order_number' => $this->formatOrderNumber($order),
            ]);

            EventOrderLink::query()->create([
                'event_reservation_id' => $locked->id,
                'order_id' => $order->id,
                'created_at' => now(),
            ]);

            $freshOrder = $order->fresh(['restaurant', 'restaurantTable', 'tableSession', 'items', 'confirmedBy', 'cancelledBy', 'accountedBy']);
            $this->eventPlanningAlertService->dispatchImmediateUpdate($locked->fresh(), 'order_draft_generated');

            return ['order' => $freshOrder, 'created' => true];
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function conflictsForRange(
        Restaurant $restaurant,
        string $eventDate,
        string $startTime,
        string $endTime,
        ?int $ignoreEventId = null
    ): array {
        [$startAt, $endAt] = $this->buildRange($eventDate, $startTime, $endTime);

        return [
            'blocking_reservations' => $this->blockingReservationConflicts(
                $restaurant,
                $startAt->toDateTimeString(),
                $endAt->toDateTimeString()
            ),
            'blocking_events' => $this->blockingEventConflicts(
                $restaurant,
                $startAt->toDateTimeString(),
                $endAt->toDateTimeString(),
                $ignoreEventId
            ),
        ];
    }

    public function leadTimeWarning(EventReservation $eventReservation): ?string
    {
        if (! $eventReservation->start_at) {
            return null;
        }

        $secondsUntilStart = now()->diffInSeconds($eventReservation->start_at, false);
        if ($secondsUntilStart < 0) {
            return null;
        }

        return $secondsUntilStart < (24 * 60 * 60)
            ? 'Event starts in less than 24 hours. Stock and kitchen preparation may be at risk.'
            : null;
    }

    private function assertNoBlockingConflicts(
        Restaurant $restaurant,
        string $startAt,
        string $endAt,
        ?int $ignoreEventId
    ): void {
        $reservationConflicts = $this->blockingReservationConflicts($restaurant, $startAt, $endAt);
        $eventConflicts = $this->blockingEventConflicts($restaurant, $startAt, $endAt, $ignoreEventId);

        if ($reservationConflicts === [] && $eventConflicts === []) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'Blocking conflicts detected for the selected event time window.',
            'errors' => [
                'conflicts' => [
                    'blocking_reservations' => $reservationConflicts,
                    'blocking_events' => $eventConflicts,
                ],
            ],
        ], 422));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function blockingReservationConflicts(
        Restaurant $restaurant,
        string $startAt,
        string $endAt
    ): array {
        return Reservation::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('status', Reservation::blockingStatuses())
            ->where('start_at', '<', $endAt)
            ->where('end_at', '>', $startAt)
            ->with(['roomPlan', 'roomPlanItem'])
            ->get()
            ->map(fn (Reservation $reservation): array => [
                'id' => $reservation->id,
                'customer_name' => $reservation->customer_name,
                'reservation_date' => $reservation->reservation_date?->format('Y-m-d'),
                'start_time' => $reservation->start_time,
                'end_time' => $reservation->end_time,
                'room_plan_name' => $reservation->roomPlan?->name,
                'table_label' => $reservation->roomPlanItem?->label,
                'status' => $reservation->status,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function blockingEventConflicts(
        Restaurant $restaurant,
        string $startAt,
        string $endAt,
        ?int $ignoreEventId
    ): array {
        $query = EventReservation::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('status', EventReservation::blockingStatuses())
            ->where('start_at', '<', $endAt)
            ->where('end_at', '>', $startAt);

        if ($ignoreEventId) {
            $query->where('id', '!=', $ignoreEventId);
        }

        return $query
            ->get()
            ->map(fn (EventReservation $event): array => [
                'id' => $event->id,
                'title' => $event->title,
                'start_at' => $event->start_at?->toIso8601String(),
                'end_at' => $event->end_at?->toIso8601String(),
                'status' => $event->status,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{0: \Carbon\CarbonImmutable, 1: \Carbon\CarbonImmutable}
     */
    private function buildRange(string $eventDate, string $startTime, string $endTime): array
    {
        $range = $this->reservationAvailabilityService->buildDateTimeRange($eventDate, $startTime, $endTime);

        return [$range['start_at'], $range['end_at']];
    }

    private function assertEventBelongsToRestaurant(EventReservation $eventReservation, Restaurant $restaurant): void
    {
        if ($eventReservation->restaurant_id !== $restaurant->id) {
            abort(404);
        }
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);
        return $normalized === '' ? null : $normalized;
    }

    private function formatOrderNumber(Order $order): string
    {
        return 'ORD-'.now()->format('Ymd').'-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT);
    }
}
