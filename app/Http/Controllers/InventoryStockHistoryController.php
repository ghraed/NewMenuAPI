<?php

namespace App\Http\Controllers;

use App\Models\Ingredient;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryStockHistoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        $validated = $request->validate([
            'ingredient_id' => ['nullable', 'integer'],
            'movement_type' => [
                'nullable',
                'string',
                'in:'.implode(',', [
                    StockMovement::TYPE_OPENING_BALANCE,
                    StockMovement::TYPE_RESTOCK,
                    StockMovement::TYPE_MANUAL_ADJUSTMENT,
                    StockMovement::TYPE_ORDER_CONSUMPTION,
                    StockMovement::TYPE_ORDER_RESTORATION,
                ]),
            ],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'linked_expense' => ['nullable', 'in:all,linked,unlinked'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = StockMovement::query()
            ->where('restaurant_id', $restaurant->id)
            ->with([
                'ingredient:id,name',
                'dish:id,name',
                'linkedExpense:id,status,expense_date',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (! empty($validated['ingredient_id'])) {
            $query->where('ingredient_id', (int) $validated['ingredient_id']);
        }

        if (! empty($validated['movement_type'])) {
            $query->where('movement_type', $validated['movement_type']);
        }

        if (! empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }

        $linkedExpenseMode = $validated['linked_expense'] ?? 'all';
        if ($linkedExpenseMode === 'linked') {
            $query->whereNotNull('linked_expense_id');
        } elseif ($linkedExpenseMode === 'unlinked') {
            $query->whereNull('linked_expense_id');
        }

        $perPage = (int) ($validated['per_page'] ?? 20);
        $movements = $query->paginate($perPage)->withQueryString();
        $movementCollection = $movements->getCollection();
        $dishNameByOrderIngredient = $this->resolveDishNamesByOrderIngredient(
            $movementCollection,
            $restaurant->id
        );
        $orderContextByOrderId = $this->resolveOrderContextByOrderId(
            $movementCollection,
            $restaurant->id
        );

        $ingredients = Ingredient::query()
            ->where('restaurant_id', $restaurant->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'movements' => $movements->getCollection()
                ->map(fn (StockMovement $movement) => $this->formatMovement(
                    $movement,
                    $dishNameByOrderIngredient[$this->orderIngredientKey($movement->order_id, $movement->ingredient_id)] ?? $movement->dish?->name,
                    $orderContextByOrderId[$movement->order_id ?? 0] ?? null
                ))
                ->values(),
            'pagination' => [
                'current_page' => $movements->currentPage(),
                'last_page' => $movements->lastPage(),
                'per_page' => $movements->perPage(),
                'total' => $movements->total(),
                'from' => $movements->firstItem(),
                'to' => $movements->lastItem(),
            ],
            'filters' => [
                'movement_types' => [
                    StockMovement::TYPE_OPENING_BALANCE,
                    StockMovement::TYPE_RESTOCK,
                    StockMovement::TYPE_MANUAL_ADJUSTMENT,
                    StockMovement::TYPE_ORDER_CONSUMPTION,
                    StockMovement::TYPE_ORDER_RESTORATION,
                ],
                'ingredients' => $ingredients,
            ],
        ]);
    }

    private function getRestaurantForRequest(Request $request): Restaurant
    {
        $user = $request->user();
        $user?->loadMissing('restaurant', 'staffRestaurants');
        $restaurant = $user?->currentRestaurant();

        if (! $restaurant) {
            abort(403, 'No restaurant is linked to this account.');
        }

        return $restaurant;
    }

    /**
     * @param  array{order_number:?string,invoice_number:?string,invoice_id:?int}|null  $orderContext
     */
    private function formatMovement(StockMovement $movement, ?string $dishName = null, ?array $orderContext = null): array
    {
        [$referenceType, $referenceId] = $this->resolveReference($movement);

        return [
            'id' => $movement->id,
            'ingredient_name' => $movement->ingredient?->name ?: $movement->ingredient_name_snapshot,
            'dish_name' => $dishName,
            'inventory_source' => $movement->inventory_source,
            'order_number' => $orderContext['order_number'] ?? null,
            'invoice_number' => $orderContext['invoice_number'] ?? null,
            'invoice_id' => $orderContext['invoice_id'] ?? null,
            'movement_type' => $movement->movement_type,
            'unit' => $movement->unit,
            'quantity' => $this->formatQuantity((float) $movement->quantity_delta),
            'quantity_before' => $movement->quantity_before === null ? null : $this->formatQuantity((float) $movement->quantity_before),
            'quantity_after' => $movement->quantity_after === null ? null : $this->formatQuantity((float) $movement->quantity_after),
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'notes' => $movement->notes,
            'linked_expense_id' => $movement->linked_expense_id,
            'linked_expense_status' => $movement->linkedExpense?->status,
            'linked_expense_date' => $movement->linkedExpense?->expense_date?->toDateString(),
            'created_at' => $movement->created_at?->toIso8601String(),
        ];
    }

    private function resolveReference(StockMovement $movement): array
    {
        if ($movement->order_item_id) {
            return ['order_item', (string) $movement->order_item_id];
        }

        if ($movement->order_id) {
            return ['order', (string) $movement->order_id];
        }

        if ($movement->linked_expense_id) {
            return ['expense', (string) $movement->linked_expense_id];
        }

        if ($movement->reference) {
            return ['reference', $movement->reference];
        }

        return ['none', null];
    }

    private function formatQuantity(float $value): string
    {
        return number_format($value, 3, '.', '');
    }

    /**
     * @return array<string, string>
     */
    private function resolveDishNamesByOrderIngredient(Collection $movements, int $restaurantId): array
    {
        $eligiblePairs = $movements
            ->filter(function (StockMovement $movement): bool {
                if (! in_array($movement->movement_type, [
                    StockMovement::TYPE_ORDER_CONSUMPTION,
                    StockMovement::TYPE_ORDER_RESTORATION,
                ], true)) {
                    return false;
                }

                return $movement->order_id !== null && $movement->ingredient_id !== null;
            })
            ->map(fn (StockMovement $movement): array => [
                'order_id' => (int) $movement->order_id,
                'ingredient_id' => (int) $movement->ingredient_id,
            ])
            ->unique(fn (array $pair): string => $this->orderIngredientKey($pair['order_id'], $pair['ingredient_id']))
            ->values();

        if ($eligiblePairs->isEmpty()) {
            return [];
        }

        $usageRows = DB::table('order_item_ingredient_usages as usage')
            ->join('order_items as item', 'item.id', '=', 'usage.order_item_id')
            ->leftJoin('dishes as dish', 'dish.id', '=', 'usage.dish_id')
            ->where('usage.restaurant_id', $restaurantId)
            ->where(function ($query) use ($eligiblePairs): void {
                foreach ($eligiblePairs as $pair) {
                    $query->orWhere(function ($pairQuery) use ($pair): void {
                        $pairQuery
                            ->where('usage.order_id', $pair['order_id'])
                            ->where('usage.ingredient_id', $pair['ingredient_id']);
                    });
                }
            })
            ->orderBy('usage.order_id')
            ->orderBy('usage.ingredient_id')
            ->orderBy('usage.order_item_id')
            ->select([
                'usage.order_id',
                'usage.ingredient_id',
                'item.dish_name as order_item_dish_name',
                'dish.name as dish_name',
            ])
            ->get();

        $groupedNames = [];
        foreach ($usageRows as $row) {
            $key = $this->orderIngredientKey((int) $row->order_id, (int) $row->ingredient_id);
            $candidateName = trim((string) ($row->order_item_dish_name ?: $row->dish_name ?: ''));
            if ($candidateName === '') {
                continue;
            }

            if (! isset($groupedNames[$key])) {
                $groupedNames[$key] = [];
            }

            if (! in_array($candidateName, $groupedNames[$key], true)) {
                $groupedNames[$key][] = $candidateName;
            }
        }

        $result = [];
        foreach ($groupedNames as $key => $names) {
            if ($names === []) {
                continue;
            }

            $result[$key] = implode(', ', $names);
        }

        return $result;
    }

    private function orderIngredientKey(?int $orderId, ?int $ingredientId): string
    {
        if ($orderId === null || $ingredientId === null) {
            return '';
        }

        return $orderId.':'.$ingredientId;
    }

    /**
     * @return array<int, array{order_number:?string,invoice_number:?string,invoice_id:?int}>
     */
    private function resolveOrderContextByOrderId(Collection $movements, int $restaurantId): array
    {
        $orderIds = $movements
            ->pluck('order_id')
            ->filter(fn ($orderId): bool => is_numeric($orderId) && (int) $orderId > 0)
            ->map(fn ($orderId): int => (int) $orderId)
            ->unique()
            ->values();

        if ($orderIds->isEmpty()) {
            return [];
        }

        $orders = Order::query()
            ->where('restaurant_id', $restaurantId)
            ->whereIn('id', $orderIds->all())
            ->get(['id', 'order_number', 'invoice_number']);

        $invoiceNumbers = $orders
            ->pluck('invoice_number')
            ->filter(fn ($invoiceNumber): bool => is_string($invoiceNumber) && trim($invoiceNumber) !== '')
            ->map(fn (string $invoiceNumber): string => trim($invoiceNumber))
            ->unique()
            ->values();

        $invoiceIdByNumber = [];
        if ($invoiceNumbers->isNotEmpty()) {
            $invoiceIdByNumber = Invoice::query()
                ->where('restaurant_id', $restaurantId)
                ->whereIn('invoice_number', $invoiceNumbers->all())
                ->pluck('id', 'invoice_number')
                ->mapWithKeys(fn ($id, $number): array => [trim((string) $number) => (int) $id])
                ->all();
        }

        $result = [];
        foreach ($orders as $order) {
            $invoiceNumber = is_string($order->invoice_number) ? trim($order->invoice_number) : '';
            $result[(int) $order->id] = [
                'order_number' => is_string($order->order_number) && trim($order->order_number) !== '' ? trim($order->order_number) : null,
                'invoice_number' => $invoiceNumber !== '' ? $invoiceNumber : null,
                'invoice_id' => $invoiceNumber !== '' ? ($invoiceIdByNumber[$invoiceNumber] ?? null) : null,
            ];
        }

        return $result;
    }
}
