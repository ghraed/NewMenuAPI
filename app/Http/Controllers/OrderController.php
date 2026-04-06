<?php

namespace App\Http\Controllers;

use App\Models\Dish;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Services\OrderInvoiceCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function store(
        Request $request,
        string $restaurant_slug,
        OrderInvoiceCalculator $invoiceCalculator
    ): JsonResponse {
        $validated = $request->validate([
            'guest_name' => 'required|string|max:255',
            'guest_phone' => 'nullable|string|max:40',
            'guest_email' => 'nullable|email|max:255',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.dish_id' => 'required|integer|distinct',
            'items.*.quantity' => 'required|integer|min:1|max:99',
        ]);

        $restaurant = Restaurant::query()
            ->where('slug', $restaurant_slug)
            ->firstOrFail();

        $preparedItems = $this->prepareOrderItems($restaurant, $validated['items']);
        $invoice = $invoiceCalculator->calculate($preparedItems);

        $order = DB::transaction(function () use ($restaurant, $validated, $preparedItems, $invoice) {
            $order = $restaurant->orders()->create([
                'uuid' => (string) Str::uuid(),
                'status' => Order::STATUS_PENDING_CONFIRMATION,
                'guest_name' => trim($validated['guest_name']),
                'guest_phone' => $this->normalizeOptionalString($validated['guest_phone'] ?? null),
                'guest_email' => $this->normalizeOptionalString($validated['guest_email'] ?? null),
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

            return $order->fresh(['restaurant', 'items']);
        });

        return response()->json([
            'message' => 'Order created successfully and is awaiting confirmation.',
            'order' => $this->formatOrder($order),
        ], 201);
    }

    public function pendingConfirmation(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        $orders = Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('status', Order::STATUS_PENDING_CONFIRMATION)
            ->with(['restaurant', 'items'])
            ->latest()
            ->get();

        return response()->json([
            'orders' => $orders->map(fn (Order $order) => $this->formatOrder($order)),
        ]);
    }

    public function confirm(
        Request $request,
        Order $order,
        OrderInvoiceCalculator $invoiceCalculator
    ): JsonResponse {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertOrderBelongsToRestaurant($order, $restaurant);

        if ($order->status === Order::STATUS_CONFIRMED) {
            return response()->json([
                'message' => 'Order has already been confirmed.',
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
                'discount_type' => 'A discount type is required when a discount value is provided.',
            ]);
        }

        $order->loadMissing('items', 'restaurant', 'confirmedBy');

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
                'status' => Order::STATUS_CONFIRMED,
                'invoice_number' => $this->formatInvoiceNumber($order),
                'confirmed_by' => $request->user()->id,
                'confirmed_at' => now(),
                ...$invoice,
            ]);

            return $order->fresh(['restaurant', 'items', 'confirmedBy']);
        });

        return response()->json([
            'message' => 'Order confirmed successfully.',
            'order' => $this->formatOrder($order),
        ]);
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

    private function formatOrder(Order $order): array
    {
        $order->loadMissing('restaurant', 'items', 'confirmedBy');

        return [
            'id' => $order->id,
            'uuid' => $order->uuid,
            'order_number' => $order->order_number,
            'invoice_number' => $order->invoice_number,
            'status' => $order->status,
            'guest_name' => $order->guest_name,
            'guest_phone' => $order->guest_phone,
            'guest_email' => $order->guest_email,
            'notes' => $order->notes,
            'created_at' => $order->created_at?->toIso8601String(),
            'confirmed_at' => $order->confirmed_at?->toIso8601String(),
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
            'confirmed_by' => $order->confirmedBy ? [
                'id' => $order->confirmedBy->id,
                'name' => $order->confirmedBy->name,
                'email' => $order->confirmedBy->email,
                'role' => $order->confirmedBy->role,
            ] : null,
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
}
