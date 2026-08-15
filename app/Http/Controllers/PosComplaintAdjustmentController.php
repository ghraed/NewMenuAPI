<?php

namespace App\Http\Controllers;

use App\Models\Dish;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PosComplaintAdjustment;
use App\Models\Restaurant;
use App\Services\OrderInventoryDeductionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PosComplaintAdjustmentController extends Controller
{
    public function searchSales(Request $request): JsonResponse
    {
        $restaurant = $this->restaurant($request);
        $query = trim((string) $request->validate(['query' => ['required', 'string', 'min:2', 'max:80']])['query']);
        $orders = Order::query()->where('restaurant_id', $restaurant->id)->where('status', Order::STATUS_ACCOUNTED)
            ->where(function ($builder) use ($query): void {
                $builder->where('invoice_number', 'like', "%{$query}%")->orWhere('order_number', 'like', "%{$query}%");
            })->with('items')->latest('accounted_at')->limit(20)->get();

        return response()->json(['sales' => $orders->map(fn (Order $order) => $this->sale($order))->values()]);
    }

    public function index(Request $request, Order $order): JsonResponse
    {
        $restaurant = $this->restaurant($request);
        $this->assertOrder($order, $restaurant);
        return response()->json(['adjustments' => PosComplaintAdjustment::query()
            ->where('restaurant_id', $restaurant->id)->where('original_order_id', $order->id)->with('gifts')->latest()->get()
            ->map(fn (PosComplaintAdjustment $adjustment) => $this->adjustment($adjustment))->values()]);
    }

    public function store(Request $request, Order $order): JsonResponse
    {
        $restaurant = $this->restaurant($request);
        $this->assertOrder($order, $restaurant);
        if ($order->status !== Order::STATUS_ACCOUNTED) abort(422, 'Only settled POS sales can be adjusted.');

        $validated = $request->validate([
            'complaint_reason' => ['required', 'string', 'max:80'], 'complaint_category' => ['nullable', 'string', 'max:60'],
            'complaint_note' => ['nullable', 'string', 'max:2000'], 'accounting_bucket' => ['nullable', 'string', 'max:80'],
            'refund_amount' => ['nullable', 'numeric', 'min:0'], 'affected_item_ids' => ['required', 'array', 'min:1'],
            'affected_item_ids.*' => ['integer'], 'gifts' => ['nullable', 'array'], 'gifts.*.dish_id' => ['required', 'integer'],
            'gifts.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ]);
        $refundAmount = round((float) ($validated['refund_amount'] ?? 0), 2);
        if ($refundAmount > (float) $order->total + 0.0001) throw ValidationException::withMessages(['refund_amount' => 'Refund cannot exceed the original sale total.']);

        $items = $order->items()->whereIn('id', $validated['affected_item_ids'])->get();
        if ($items->count() !== count(array_unique($validated['affected_item_ids']))) throw ValidationException::withMessages(['affected_item_ids' => 'Every affected item must belong to the selected sale.']);
        $giftInputs = $validated['gifts'] ?? [];
        $dishes = Dish::query()->where('restaurant_id', $restaurant->id)->whereIn('id', collect($giftInputs)->pluck('dish_id'))->get()->keyBy('id');
        if ($dishes->count() !== count(array_unique(collect($giftInputs)->pluck('dish_id')->all()))) throw ValidationException::withMessages(['gifts' => 'A selected gift dish is unavailable.']);

        $adjustment = DB::transaction(function () use ($request, $restaurant, $order, $validated, $refundAmount, $items, $giftInputs, $dishes): PosComplaintAdjustment {
            $invoice = Invoice::query()->where('restaurant_id', $restaurant->id)->where('invoice_number', $order->invoice_number)->first();
            $isApprover = in_array($request->user()->role, ['admin', 'accountant'], true);
            $adjustment = PosComplaintAdjustment::query()->create([
                'restaurant_id' => $restaurant->id, 'original_order_id' => $order->id, 'original_invoice_id' => $invoice?->id,
                'original_invoice_number' => $order->invoice_number, 'status' => $isApprover ? 'draft' : 'pending_approval',
                'complaint_reason' => $validated['complaint_reason'], 'complaint_category' => $validated['complaint_category'] ?? null,
                'complaint_note' => $validated['complaint_note'] ?? null, 'accounting_bucket' => $validated['accounting_bucket'] ?? 'customer_complaint_loss',
                'refund_amount' => $refundAmount, 'refund_payment_method' => $order->payment_method ?: 'cash',
                'affected_items' => $items->map(fn ($item) => ['order_item_id' => $item->id, 'dish_name' => $item->dish_name, 'quantity' => $item->quantity, 'line_total' => $item->line_subtotal])->values()->all(),
                'created_by' => $request->user()->id,
            ]);
            foreach ($giftInputs as $gift) {
                $dish = $dishes->get($gift['dish_id']); $quantity = (int) $gift['quantity']; $unitValue = (float) $dish->price;
                $adjustment->gifts()->create(['dish_id' => $dish->id, 'dish_name_snapshot' => $dish->name, 'quantity' => $quantity, 'unit_value' => $unitValue, 'line_value' => $unitValue * $quantity]);
            }
            return $adjustment->fresh('gifts');
        });
        return response()->json(['adjustment' => $this->adjustment($adjustment)], 201);
    }

    public function post(Request $request, PosComplaintAdjustment $adjustment, OrderInventoryDeductionService $inventory): JsonResponse
    {
        $restaurant = $this->restaurant($request);
        if (! in_array($request->user()->role, ['admin', 'accountant'], true)) abort(403);
        if ($adjustment->restaurant_id !== $restaurant->id) abort(404);
        $posted = DB::transaction(function () use ($adjustment, $request, $inventory): PosComplaintAdjustment {
            $adjustment = PosComplaintAdjustment::query()->whereKey($adjustment->id)->lockForUpdate()->with('gifts')->firstOrFail();
            if ($adjustment->status === 'posted') return $adjustment;
            if ($adjustment->status === 'void') abort(422, 'A voided adjustment cannot be posted.');
            $giftOrderId = null;
            if ($adjustment->gifts->isNotEmpty()) {
                $order = Order::query()->create([
                    'uuid' => (string) Str::uuid(), 'restaurant_id' => $adjustment->restaurant_id, 'status' => Order::STATUS_STAFF_CONFIRMED,
                    'guest_name' => 'Complaint gift', 'table_reference' => 'SERVICE-RECOVERY', 'notes' => 'Gift for complaint adjustment #'.$adjustment->id,
                    'currency' => $adjustment->originalOrder->currency, 'exchange_rate' => $adjustment->originalOrder->exchange_rate,
                    'subtotal' => 0, 'discount_value' => 0, 'discount_amount' => 0, 'taxable_subtotal' => 0, 'vat_amount' => 0, 'service_charge_amount' => 0, 'total' => 0,
                    'confirmed_by' => $request->user()->id, 'confirmed_at' => now(),
                ]);
                $order->items()->createMany($adjustment->gifts->map(fn ($gift) => ['dish_id' => $gift->dish_id, 'dish_name' => $gift->dish_name_snapshot, 'unit_price' => 0, 'quantity' => $gift->quantity, 'line_subtotal' => 0, 'status' => 'compensated', 'compensation_type' => 'complimentary', 'compensation_reason' => $adjustment->complaint_reason, 'complaint_category' => $adjustment->complaint_category, 'compensation_note' => $adjustment->complaint_note, 'accounting_bucket' => $adjustment->accounting_bucket, 'is_complimentary' => true])->all());
                $inventory->deductForConfirmedOrder($order, $request->user()->id);
                $order->update(['status' => Order::STATUS_ACCOUNTED, 'accounted_by' => $request->user()->id, 'accounted_at' => now()]);
                $giftOrderId = $order->id;
            }
            $adjustment->update(['status' => 'posted', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'posted_at' => now(), 'gift_order_id' => $giftOrderId]);
            return $adjustment->fresh('gifts');
        });
        return response()->json(['adjustment' => $this->adjustment($posted)]);
    }

    public function void(Request $request, PosComplaintAdjustment $adjustment): JsonResponse
    {
        $restaurant = $this->restaurant($request);
        if (! in_array($request->user()->role, ['admin', 'accountant'], true)) abort(403);
        if ($adjustment->restaurant_id !== $restaurant->id) abort(404);
        if ($adjustment->status === 'posted') abort(422, 'Posted adjustments are immutable; create a correcting adjustment instead.');
        $adjustment->update(['status' => 'void', 'voided_at' => now()]);
        return response()->json(['adjustment' => $this->adjustment($adjustment->fresh('gifts'))]);
    }

    private function restaurant(Request $request): Restaurant { $user = $request->user(); $user->loadMissing('restaurant', 'staffRestaurants'); return $user->currentRestaurant() ?? abort(403, 'No restaurant is linked to this account'); }
    private function assertOrder(Order $order, Restaurant $restaurant): void { if ($order->restaurant_id !== $restaurant->id) abort(404); }
    private function sale(Order $order): array { return ['id' => $order->id, 'order_number' => $order->order_number, 'invoice_number' => $order->invoice_number, 'total' => $order->total, 'currency' => $order->currency, 'payment_method' => $order->payment_method, 'accounted_at' => $order->accounted_at?->toIso8601String(), 'items' => $order->items->map(fn ($item) => ['id' => $item->id, 'dish_name' => $item->dish_name, 'quantity' => $item->quantity, 'line_total' => $item->line_subtotal])->values()]; }
    private function adjustment(PosComplaintAdjustment $a): array { return ['id' => $a->id, 'status' => $a->status, 'original_order_id' => $a->original_order_id, 'original_invoice_number' => $a->original_invoice_number, 'complaint_reason' => $a->complaint_reason, 'complaint_category' => $a->complaint_category, 'complaint_note' => $a->complaint_note, 'accounting_bucket' => $a->accounting_bucket, 'refund_amount' => $a->refund_amount, 'refund_payment_method' => $a->refund_payment_method, 'affected_items' => $a->affected_items, 'gifts' => $a->gifts->map(fn ($gift) => ['dish_name' => $gift->dish_name_snapshot, 'quantity' => $gift->quantity, 'line_value' => $gift->line_value])->values(), 'created_at' => $a->created_at?->toIso8601String(), 'posted_at' => $a->posted_at?->toIso8601String()]; }
}
