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
                    'order' => __('messages.orders.inventory_deduction_confirmed_only'),
                ]);
            }

            $hasUsageSnapshots = OrderItemIngredientUsage::query()
                ->where('order_id', $lockedOrder->id)
                ->exists();
            $hasConsumptionMovements = StockMovement::query()
                ->where('order_id', $lockedOrder->id)
                ->where('movement_type', StockMovement::TYPE_ORDER_CONSUMPTION)
                ->exists();
            $hasRecipeConsumptionMovements = StockMovement::query()
                ->where('order_id', $lockedOrder->id)
                ->where('movement_type', StockMovement::TYPE_ORDER_CONSUMPTION)
                ->where('inventory_source', StockMovement::SOURCE_RECIPE_INGREDIENT_USAGE)
                ->exists();

            if ($hasUsageSnapshots || $hasConsumptionMovements) {
                if ($hasConsumptionMovements && (! $hasRecipeConsumptionMovements || $hasUsageSnapshots)) {
                    return;
                }

                throw ValidationException::withMessages([
                    'inventory' => __('messages.orders.inventory_deduction_partial'),
                ]);
            }

            $lockedOrder->loadMissing([
                'items.dish.dishIngredients.ingredient',
                'items.dish',
            ]);

            $ingredientConsumptionTotals = [];
            $recipeConsumptionTotals = [];
            $usageSnapshotRows = [];
            $directConsumptionRows = [];

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

                if ($orderItem->dish->usesDirectInventory()) {
                    $ingredient = Ingredient::query()->find($orderItem->dish->direct_stock_ingredient_id);
                    if (! $ingredient || ! $ingredient->is_active) {
                        throw ValidationException::withMessages([
                            'inventory' => 'Packaged item stock source is missing or inactive.',
                        ]);
                    }

                    $directPerSale = round((float) ($orderItem->dish->direct_stock_quantity_per_sale ?: 1), 3);
                    $consumedQuantity = round($directPerSale * $orderItemQuantity, 3);
                    if ($consumedQuantity <= 0) {
                        continue;
                    }

                    $ingredientId = (int) $ingredient->id;
                    $ingredientConsumptionTotals[$ingredientId] = round(
                        ($ingredientConsumptionTotals[$ingredientId] ?? 0) + $consumedQuantity,
                        3
                    );

                    $directConsumptionRows[] = [
                        'ingredient_id' => $ingredientId,
                        'order_item_id' => $orderItem->id,
                        'dish_id' => $orderItem->dish_id,
                        'unit' => $ingredient->stock_unit,
                        'consumed_quantity' => $consumedQuantity,
                    ];

                    continue;
                }

                if ($orderItem->dish->isStandalonePackagedItem()) {
                    $availableQuantity = round((float) ($orderItem->dish->packaged_stock_quantity ?? 0), 3);
                    $consumedQuantity = round((float) $orderItemQuantity, 3);

                    if ($consumedQuantity <= 0) {
                        continue;
                    }

                    if ($availableQuantity < $consumedQuantity) {
                        throw ValidationException::withMessages([
                            'inventory' => 'Packaged item does not have enough stock quantity.',
                        ]);
                    }

                    $orderItem->dish->decrement('packaged_stock_quantity', $consumedQuantity);
                    $orderItem->dish->refresh();

                    continue;
                }

                foreach ($orderItem->dish->dishIngredients as $dishIngredient) {
                    $ingredient = $dishIngredient->ingredient;

                    if (! $ingredient) {
                        continue;
                    }

                    if (! $ingredient->is_active) {
                        throw ValidationException::withMessages([
                            'inventory' => __('messages.orders.inventory_inactive_ingredient', [
                                'ingredient' => $ingredient->name,
                            ]),
                        ]);
                    }

                    $recipeQuantity = round((float) $dishIngredient->quantity, 3);
                    if ($recipeQuantity <= 0) {
                        continue;
                    }

                    if ($dishIngredient->unit !== $ingredient->stock_unit) {
                        throw ValidationException::withMessages([
                            'inventory' => __('messages.orders.inventory_unit_mismatch', [
                                'ingredient' => $ingredient->name,
                                'recipe_unit' => $dishIngredient->unit,
                                'stock_unit' => $ingredient->stock_unit,
                            ]),
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
                    $recipeConsumptionTotals[$ingredientId] = round(
                        ($recipeConsumptionTotals[$ingredientId] ?? 0) + $consumedQuantity,
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
                    'inventory' => __('messages.orders.inventory_missing_ingredients'),
                ]);
            }

            $insufficientLines = [];

            foreach ($ingredientConsumptionTotals as $ingredientId => $requiredQuantity) {
                /** @var Ingredient $ingredient */
                $ingredient = $lockedIngredients->get($ingredientId);
                $availableQuantity = round((float) $ingredient->current_stock_quantity, 3);

                if ($availableQuantity + 0.0005 < $requiredQuantity) {
                    $insufficientLines[] = __('messages.orders.inventory_shortage_line', [
                        'ingredient' => $ingredient->name,
                        'required' => $this->formatQuantity($requiredQuantity),
                        'available' => $this->formatQuantity($availableQuantity),
                        'unit' => $ingredient->stock_unit,
                    ]);
                }
            }

            if ($insufficientLines !== []) {
                throw ValidationException::withMessages([
                    'inventory' => __('messages.orders.inventory_shortage', [
                        'details' => implode(' | ', $insufficientLines),
                    ]),
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

            foreach ($recipeConsumptionTotals as $ingredientId => $requiredQuantity) {
                /** @var Ingredient $ingredient */
                $ingredient = $lockedIngredients->get($ingredientId);
                $quantityBefore = round((float) $ingredient->current_stock_quantity, 3);
                $quantityAfter = round($quantityBefore - $requiredQuantity, 3);

                if ($quantityAfter < -0.0005) {
                    throw ValidationException::withMessages([
                        'inventory' => __('messages.orders.inventory_shortage_line', [
                            'ingredient' => $ingredient->name,
                            'required' => $this->formatQuantity($requiredQuantity),
                            'available' => $this->formatQuantity($quantityBefore),
                            'unit' => $ingredient->stock_unit,
                        ]),
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
                    'dish_id' => null,
                    'performed_by' => $performedByUserId,
                    'movement_type' => StockMovement::TYPE_ORDER_CONSUMPTION,
                    'inventory_source' => StockMovement::SOURCE_RECIPE_INGREDIENT_USAGE,
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

            foreach ($directConsumptionRows as $directRow) {
                /** @var Ingredient $ingredient */
                $ingredient = $lockedIngredients->get((int) $directRow['ingredient_id']);
                $requiredQuantity = round((float) $directRow['consumed_quantity'], 3);
                $quantityBefore = round((float) $ingredient->current_stock_quantity, 3);
                $quantityAfter = round($quantityBefore - $requiredQuantity, 3);

                if ($quantityAfter < -0.0005) {
                    throw ValidationException::withMessages([
                        'inventory' => __('messages.orders.inventory_shortage_line', [
                            'ingredient' => $ingredient->name,
                            'required' => $this->formatQuantity($requiredQuantity),
                            'available' => $this->formatQuantity($quantityBefore),
                            'unit' => $ingredient->stock_unit,
                        ]),
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
                    'order_item_id' => (int) $directRow['order_item_id'],
                    'dish_id' => (int) $directRow['dish_id'],
                    'performed_by' => $performedByUserId,
                    'movement_type' => StockMovement::TYPE_ORDER_CONSUMPTION,
                    'inventory_source' => StockMovement::SOURCE_DIRECT_PACKAGED_SALE,
                    'unit' => $ingredient->stock_unit,
                    'quantity_delta' => round(0 - $requiredQuantity, 3),
                    'quantity_before' => $quantityBefore,
                    'quantity_after' => $quantityAfter,
                    'ingredient_name_snapshot' => $ingredient->name,
                    'reference' => 'order:'.$lockedOrder->id,
                    'notes' => 'Direct packaged item sale deduction.',
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

    public function restoreForCancelledOrder(Order $order, ?int $performedByUserId = null): void
    {
        $runner = function () use ($order, $performedByUserId): void {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOrder->status !== Order::STATUS_STAFF_CANCELLED) {
                throw ValidationException::withMessages([
                    'order' => __('messages.orders.inventory_restore_cancelled_only'),
                ]);
            }

            $hasUsageSnapshots = OrderItemIngredientUsage::query()
                ->where('order_id', $lockedOrder->id)
                ->exists();
            $hasConsumptionMovements = StockMovement::query()
                ->where('order_id', $lockedOrder->id)
                ->where('movement_type', StockMovement::TYPE_ORDER_CONSUMPTION)
                ->exists();
            $hasRecipeConsumptionMovements = StockMovement::query()
                ->where('order_id', $lockedOrder->id)
                ->where('movement_type', StockMovement::TYPE_ORDER_CONSUMPTION)
                ->where('inventory_source', StockMovement::SOURCE_RECIPE_INGREDIENT_USAGE)
                ->exists();

            // Only restore when deduction really happened.
            if (! $hasUsageSnapshots && ! $hasConsumptionMovements) {
                return;
            }

            if ($hasConsumptionMovements && $hasRecipeConsumptionMovements && ! $hasUsageSnapshots) {
                throw ValidationException::withMessages([
                    'inventory' => __('messages.orders.inventory_deduction_partial'),
                ]);
            }

            $consumedTotals = OrderItemIngredientUsage::query()
                ->selectRaw('ingredient_id, SUM(consumed_quantity) as consumed_total')
                ->where('order_id', $lockedOrder->id)
                ->whereNotNull('ingredient_id')
                ->groupBy('ingredient_id')
                ->get()
                ->mapWithKeys(fn ($row) => [(int) $row->ingredient_id => round((float) $row->consumed_total, 3)])
                ->all();

            $directConsumedTotals = StockMovement::query()
                ->selectRaw('ingredient_id, SUM(ABS(quantity_delta)) as consumed_total')
                ->where('order_id', $lockedOrder->id)
                ->where('movement_type', StockMovement::TYPE_ORDER_CONSUMPTION)
                ->where('inventory_source', StockMovement::SOURCE_DIRECT_PACKAGED_SALE)
                ->whereNotNull('ingredient_id')
                ->groupBy('ingredient_id')
                ->get()
                ->mapWithKeys(fn ($row) => [(int) $row->ingredient_id => round((float) $row->consumed_total, 3)])
                ->all();

            foreach ($directConsumedTotals as $ingredientId => $directTotal) {
                $consumedTotals[$ingredientId] = round(($consumedTotals[$ingredientId] ?? 0) + $directTotal, 3);
            }

            if ($consumedTotals === []) {
                throw ValidationException::withMessages([
                    'inventory' => __('messages.orders.inventory_restore_missing_snapshots'),
                ]);
            }

            $ingredientIds = array_map('intval', array_keys($consumedTotals));

            $lockedIngredients = Ingredient::query()
                ->where('restaurant_id', $lockedOrder->restaurant_id)
                ->whereIn('id', $ingredientIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($lockedIngredients->count() !== count($ingredientIds)) {
                throw ValidationException::withMessages([
                    'inventory' => __('messages.orders.inventory_restore_missing_ingredients'),
                ]);
            }

            $existingRestoreTotals = StockMovement::query()
                ->selectRaw('ingredient_id, SUM(quantity_delta) as restored_total')
                ->where('order_id', $lockedOrder->id)
                ->where('movement_type', StockMovement::TYPE_CANCELLATION_RESTORE)
                ->whereNotNull('ingredient_id')
                ->groupBy('ingredient_id')
                ->get()
                ->mapWithKeys(fn ($row) => [(int) $row->ingredient_id => round((float) $row->restored_total, 3)])
                ->all();

            if ($existingRestoreTotals !== []) {
                $matchesExpectedRestore = count($existingRestoreTotals) === count($consumedTotals);

                if ($matchesExpectedRestore) {
                    foreach ($consumedTotals as $ingredientId => $consumedTotal) {
                        $restoredTotal = $existingRestoreTotals[$ingredientId] ?? null;

                        if ($restoredTotal === null || abs($restoredTotal - $consumedTotal) > 0.0005) {
                            $matchesExpectedRestore = false;
                            break;
                        }
                    }
                }

                if ($matchesExpectedRestore) {
                    return;
                }

                throw ValidationException::withMessages([
                    'inventory' => __('messages.orders.inventory_restore_partial'),
                ]);
            }

            $now = now();
            $movementRows = [];

            foreach ($consumedTotals as $ingredientId => $consumedTotal) {
                /** @var Ingredient $ingredient */
                $ingredient = $lockedIngredients->get($ingredientId);
                $quantityBefore = round((float) $ingredient->current_stock_quantity, 3);
                $quantityAfter = round($quantityBefore + $consumedTotal, 3);

                $ingredient->update([
                    'current_stock_quantity' => $quantityAfter,
                ]);

                $movementRows[] = [
                    'restaurant_id' => $lockedOrder->restaurant_id,
                    'ingredient_id' => $ingredient->id,
                    'order_id' => $lockedOrder->id,
                    'order_item_id' => null,
                    'performed_by' => $performedByUserId,
                    'movement_type' => StockMovement::TYPE_CANCELLATION_RESTORE,
                    'inventory_source' => StockMovement::SOURCE_RECIPE_INGREDIENT_USAGE,
                    'unit' => $ingredient->stock_unit,
                    'quantity_delta' => $consumedTotal,
                    'quantity_before' => $quantityBefore,
                    'quantity_after' => $quantityAfter,
                    'ingredient_name_snapshot' => $ingredient->name,
                    'reference' => 'order:'.$lockedOrder->id,
                    'notes' => 'Stock restored because a confirmed order was cancelled.',
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

    private function formatQuantity(float $value): string
    {
        return number_format($value, 3, '.', '');
    }
}
