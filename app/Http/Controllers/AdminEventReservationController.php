<?php

namespace App\Http\Controllers;

use App\Models\EventReservation;
use App\Models\Restaurant;
use App\Services\EventReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminEventReservationController extends Controller
{
    public function __construct(
        private readonly EventReservationService $eventReservationService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', Rule::in(EventReservation::supportedStatuses())],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->eventReservationService->list($restaurant, $validated);

        return response()->json([
            'events' => collect($paginator->items())
                ->map(fn (EventReservation $event) => $this->formatEvent($event))
                ->values(),
            'pagination' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, EventReservation $event): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertEventBelongsToRestaurant($event, $restaurant);
        $event->load(['roomPlan', 'menuItems.dish', 'orderLinks.order']);

        return response()->json([
            'event' => $this->formatEvent($event),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $validated = $this->validateEventPayload($request, true);
        $this->assertInvoiceBelongsToRestaurant($restaurant, $validated['invoice_id'] ?? null);
        $event = $this->eventReservationService->create($restaurant, $validated);

        return response()->json([
            'message' => 'Event reservation created successfully.',
            'event' => $this->formatEvent($event),
        ], 201);
    }

    public function update(Request $request, EventReservation $event): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertEventBelongsToRestaurant($event, $restaurant);
        $validated = $this->validateEventPayload($request, false);
        $this->assertInvoiceBelongsToRestaurant($restaurant, $validated['invoice_id'] ?? null);
        $updated = $this->eventReservationService->update($restaurant, $event, $validated);

        return response()->json([
            'message' => 'Event reservation updated successfully.',
            'event' => $this->formatEvent($updated),
        ]);
    }

    public function confirm(Request $request, EventReservation $event): JsonResponse
    {
        return $this->updateStatus($request, $event, EventReservation::STATUS_CONFIRMED, 'Event reservation confirmed.');
    }

    public function cancel(Request $request, EventReservation $event): JsonResponse
    {
        return $this->updateStatus($request, $event, EventReservation::STATUS_CANCELLED, 'Event reservation cancelled.');
    }

    public function complete(Request $request, EventReservation $event): JsonResponse
    {
        return $this->updateStatus($request, $event, EventReservation::STATUS_COMPLETED, 'Event reservation completed.');
    }

    public function replaceMenuItems(Request $request, EventReservation $event): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertEventBelongsToRestaurant($event, $restaurant);

        $validated = $request->validate([
            'items' => ['required', 'array'],
            'items.*.dish_id' => ['required', 'integer'],
            'items.*.planned_quantity' => ['required', 'integer', 'min:1', 'max:5000'],
            'items.*.prep_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $updated = $this->eventReservationService->replaceMenuItems($restaurant, $event, $validated['items']);

        return response()->json([
            'message' => 'Event menu items updated.',
            'event' => $this->formatEvent($updated),
        ]);
    }

    public function forecast(Request $request, EventReservation $event): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertEventBelongsToRestaurant($event, $restaurant);

        return response()->json([
            'forecast' => $this->eventReservationService->forecastForEvent($restaurant, $event),
        ]);
    }

    public function generateOrderDraft(Request $request, EventReservation $event): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertEventBelongsToRestaurant($event, $restaurant);
        $result = $this->eventReservationService->generateOrderDraft($restaurant, $event);

        return response()->json([
            'message' => $result['created']
                ? 'Event order draft generated.'
                : 'An active event order draft already exists.',
            'created' => $result['created'],
            'order' => [
                'id' => $result['order']->id,
                'order_number' => $result['order']->order_number,
                'status' => $result['order']->status,
                'table_reference' => $result['order']->table_reference,
                'total' => $result['order']->total,
                'created_at' => $result['order']->created_at?->toIso8601String(),
            ],
        ]);
    }

    private function updateStatus(
        Request $request,
        EventReservation $event,
        string $status,
        string $message
    ): JsonResponse {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertEventBelongsToRestaurant($event, $restaurant);
        $updated = $this->eventReservationService->updateStatus($restaurant, $event, $status);

        return response()->json([
            'message' => $message,
            'event' => $this->formatEvent($updated),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateEventPayload(Request $request, bool $isCreate): array
    {
        $prefix = $isCreate ? 'required' : 'sometimes|required';

        return $request->validate([
            'title' => [$prefix, 'string', 'max:160'],
            'customer_name' => [$prefix, 'string', 'max:120'],
            'customer_phone' => [$prefix, 'string', 'max:40'],
            'customer_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'room_plan_id' => ['sometimes', 'nullable', 'integer'],
            'invoice_id' => ['sometimes', 'nullable', 'integer'],
            'event_date' => [$prefix, 'date_format:Y-m-d'],
            'start_time' => [$prefix, 'date_format:H:i'],
            'end_time' => [$prefix, 'date_format:H:i'],
            'status' => ['sometimes', Rule::in(EventReservation::supportedStatuses())],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
    }

    private function assertEventBelongsToRestaurant(EventReservation $event, Restaurant $restaurant): void
    {
        if ($event->restaurant_id !== $restaurant->id) {
            abort(404);
        }
    }

    private function assertInvoiceBelongsToRestaurant(Restaurant $restaurant, mixed $invoiceId): void
    {
        if (! is_numeric($invoiceId)) {
            return;
        }

        $exists = $restaurant->invoices()
            ->whereKey((int) $invoiceId)
            ->exists();

        if (! $exists) {
            abort(422, 'Selected invoice does not belong to this restaurant.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formatEvent(EventReservation $event): array
    {
        $event->loadMissing(['roomPlan', 'menuItems.dish', 'orderLinks.order']);

        return [
            'id' => $event->id,
            'restaurant_id' => $event->restaurant_id,
            'room_plan_id' => $event->room_plan_id,
            'invoice_id' => $event->invoice_id,
            'title' => $event->title,
            'customer_name' => $event->customer_name,
            'customer_phone' => $event->customer_phone,
            'customer_email' => $event->customer_email,
            'status' => $event->status,
            'notes' => $event->notes,
            'start_at' => $event->start_at?->toIso8601String(),
            'end_at' => $event->end_at?->toIso8601String(),
            'event_date' => $event->start_at?->timezone(config('app.timezone'))->format('Y-m-d'),
            'start_time' => $event->start_at?->timezone(config('app.timezone'))->format('H:i'),
            'end_time' => $event->end_at?->timezone(config('app.timezone'))->format('H:i'),
            'lead_time_warning' => $this->eventReservationService->leadTimeWarning($event),
            'room_plan' => $event->roomPlan ? [
                'id' => $event->roomPlan->id,
                'name' => $event->roomPlan->name,
            ] : null,
            'menu_items' => $event->menuItems
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'dish_id' => $item->dish_id,
                    'dish_name' => $item->dish_name_snapshot ?: $item->dish?->name,
                    'category' => $item->category_snapshot ?: $item->dish?->category,
                    'planned_quantity' => $item->planned_quantity,
                    'prep_notes' => $item->prep_notes,
                ])
                ->values()
                ->all(),
            'linked_orders' => $event->orderLinks
                ->map(fn ($link) => $link->order ? [
                    'order_id' => $link->order->id,
                    'order_number' => $link->order->order_number,
                    'status' => $link->order->status,
                    'table_reference' => $link->order->table_reference,
                    'created_at' => $link->created_at?->toIso8601String(),
                ] : null)
                ->filter()
                ->values()
                ->all(),
            'created_at' => $event->created_at?->toIso8601String(),
            'updated_at' => $event->updated_at?->toIso8601String(),
        ];
    }

    private function getRestaurantForRequest(Request $request): Restaurant
    {
        $user = $request->user();

        if (! $user || ! method_exists($user, 'currentRestaurant')) {
            abort(403, 'No restaurant linked to user.');
        }

        $restaurant = $user->currentRestaurant();

        if (! $restaurant) {
            abort(403, 'No restaurant linked to user.');
        }

        return $restaurant;
    }
}

