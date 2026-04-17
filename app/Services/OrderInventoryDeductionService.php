<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemIngredientUsage;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderInventoryDeductionService
{
    public function deductForConfirmedOrder(Order $order, ?int $performedByUserId = null): void
    {
        $runner = function () use ($order, $performedByUserId): void {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOrder->status !== Order::STATUS_STAFF_CONFIRMED) {
                throw ValidationException::withMessages([
                    'order' => 'Inventory deduction is only allowed for confirmed orders.',
                ]);
            }

            $hasUsageSnapshots = OrderItemIngredientUsage::query()
                ->where('order_id', $lockedOrder->id)
                ->exists();
            $hasConsumptionMovements = StockMovement::query()
                ->where('order_id', $lockedOrder->id)
                ->where('movement_type', StockMovement::TYPE_ORDER_CONSUMPTION)
                ->exists();

            if ($hasUsageSnapshots || $hasConsumptionMovements) {
                if ($hasUsageSnapshots && $hasConsumptionMovements) {
                    return;
                }

                throw ValidationException::withMessages([
                    'inventory' => 'Inventory deduction appears partially applied for this order. Resolve the inconsistency before retrying.',
                ]);
            }

            $lockedOrder->loadMissing([
                'items.dish.dishIngredients.ingredient',
            ]);

            $ingredientConsumptionTotals = [];
            $usageSnapshotRows = [];

            foreach ($lockedOrder->items as $orderItem) {
                if (! $orderItem instanceof OrderItem) {
                    continue;
                }

                // Keep legacy orders confirmable even when they have snapshot-only items.
                if ($orderItem->dish_id === null || ! $orderItem->dish) {
                    continue;
                }

                $orderItemQuantity = (int) $orderItem->quantity;

                if ($orderItemQuantity <= 0) {
                    continue;
                }

                foreach ($orderItem->dish->dishIngredients as $dishIngredient) {
                    $ingredient = $dishIngredient->ingredient;

                    if (! $ingredient) {
                        continue;
                    }

                    $recipeQuantity = round((float) $dishIngredient->quantity, 3);
                    if ($recipeQuantity <= 0) {
                        continue;
                    }

                    if ($dishIngredient->unit !== $ingredient->stock_unit) {
                        throw ValidationException::withMessages([
                            'inventory' => "Recipe unit mismatch for ingredient '{$ingredient->name}'.",
                        ]);
                    }

                    $consumedQuantity = round($recipeQuantity * $orderItemQuantity, 3);
                    if ($consumedQuantity <= 0) {
                        continue;
                    }

                    $ingredientId = (int) $ingredient->id;
                    $ingredientConsumptionTotals[$ingredientId] = round(
                        ($ingredientConsumptionTotals[$ingredientId] ?? 0) + $consumedQuantity,
                        3
                    );

                    $usageSnapshotRows[] = [
                        'restaurant_id' => $lockedOrder->restaurant_id,
                        'order_id' => $lockedOrder->id,
                        'order_item_id' => $orderItem->id,
                        'dish_id' => $orderItem->dish_id,
                        'dish_ingredient_id' => $dishIngredient->id,
                        'ingredient_id' => $ingredientId,
                        'ingredient_name_snapshot' => $ingredient->name,
                        'unit' => $ingredient->stock_unit,
                        'recipe_quantity_per_dish' => $recipeQuantity,
                        'order_item_quantity' => $orderItemQuantity,
                        'consumed_quantity' => $consumedQuantity,
                    ];
                }
            }

            if ($ingredientConsumptionTotals === []) {
                return;
            }

            $ingredientIds = array_map('intval', array_keys($ingredientConsumptionTotals));

            $lockedIngredients = Ingredient::query()
                ->where('restaurant_id', $lockedOrder->restaurant_id)
                ->whereIn('id', $ingredientIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($lockedIngredients->count() !== count($ingredientIds)) {
                throw ValidationException::withMessages([
                    'inventory' => 'Some recipe ingredients are not available in this restaurant inventory.',
                ]);
            }

            $insufficientLines = [];

            foreach ($ingredientConsumptionTotals as $ingredientId => $requiredQuantity) {
                /** @var Ingredient $ingredient */
                $ingredient = $lockedIngredients->get($ingredientId);
                $availableQuantity = round((float) $ingredient->current_stock_quantity, 3);

                if ($availableQuantity + 0.0005 < $requiredQuantity) {
                    $insufficientLines[] = sprintf(
                        '%s: required %s %s, available %s %s',
                        $ingredient->name,
                        number_format($requiredQuantity, 3, '.', ''),
                        $ingredient->stock_unit,
                        number_format($availableQuantity, 3, '.', ''),
                        $ingredient->stock_unit
                    );
                }
            }

            if ($insufficientLines !== []) {
                throw ValidationException::withMessages([
                    'inventory' => 'Insufficient stock to confirm this order. '.implode(' | ', $insufficientLines),
                ]);
            }

            $now = now();
            $usageRowsWithTimestamps = array_map(
                fn (array $row): array => [
                    ...$row,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $usageSnapshotRows
            );

            OrderItemIngredientUsage::query()->upsert(
                $usageRowsWithTimestamps,
                ['order_item_id', 'ingredient_id'],
                [
                    'dish_id',
                    'dish_ingredient_id',
                    'ingredient_name_snapshot',
                    'unit',
                    'recipe_quantity_per_dish',
                    'order_item_quantity',
                    'consumed_quantity',
                    'updated_at',
                ]
            );

            $movementRows = [];

            foreach ($ingredientConsumptionTotals as $ingredientId => $requiredQuantity) {
                /** @var Ingredient $ingredient */
                $ingredient = $lockedIngredients->get($ingredientId);
                $quantityBefore = round((float) $ingredient->current_stock_quantity, 3);
                $quantityAfter = round($quantityBefore - $requiredQuantity, 3);

                if ($quantityAfter < -0.0005) {
                    throw ValidationException::withMessages([
                        'inventory' => "Insufficient stock for ingredient '{$ingredient->name}'.",
                    ]);
                }

                if ($quantityAfter < 0) {
                    $quantityAfter = 0.0;
                }

                $ingredient->update([
                    'current_stock_quantity' => $quantityAfter,
                ]);

                $movementRows[] = [
                    'restaurant_id' => $lockedOrder->restaurant_id,
                    'ingredient_id' => $ingredient->id,
                    'order_id' => $lockedOrder->id,
                    'order_item_id' => null,
                    'performed_by' => $performedByUserId,
                    'movement_type' => StockMovement::TYPE_ORDER_CONSUMPTION,
                    'unit' => $ingredient->stock_unit,
                    'quantity_delta' => round(0 - $requiredQuantity, 3),
                    'quantity_before' => $quantityBefore,
                    'quantity_after' => $quantityAfter,
                    'ingredient_name_snapshot' => $ingredient->name,
                    'reference' => 'order:'.$lockedOrder->id,
                    'notes' => 'Stock deducted when order was confirmed.',
                    'occurred_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($movementRows !== []) {
                StockMovement::query()->insert($movementRows);
            }
        };

        if (DB::transactionLevel() > 0) {
            $runner();

            return;
        }

        DB::transaction($runner);
    }
}
