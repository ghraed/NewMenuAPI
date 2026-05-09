<?php

namespace App\Http\Controllers;

use App\Models\Ingredient;
use App\Models\Restaurant;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $ingredients = Ingredient::query()
            ->where('restaurant_id', $restaurant->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'movements' => $movements->getCollection()
                ->map(fn (StockMovement $movement) => $this->formatMovement($movement))
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

    private function formatMovement(StockMovement $movement): array
    {
        [$referenceType, $referenceId] = $this->resolveReference($movement);

        return [
            'id' => $movement->id,
            'ingredient_name' => $movement->ingredient?->name ?: $movement->ingredient_name_snapshot,
            'movement_type' => $movement->movement_type,
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
}
