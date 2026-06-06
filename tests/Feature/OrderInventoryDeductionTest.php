<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\DishIngredient;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemIngredientUsage;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Models\Feature;
use App\Models\RestaurantFeature;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\OrderInventoryDeductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderInventoryDeductionTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirming_order_deducts_inventory_and_creates_usage_snapshots_and_stock_movements(): void
    {
        $restaurant = $this->createRestaurant();
        $this->enableFeature($restaurant, 'realtime_staff_orders');
        $this->enableFeature($restaurant, 'ingredient_stock_deduction');
        $staff = $this->createStaffUser($restaurant, ['T01']);
        $flour = $this->createIngredient($restaurant, 'Flour', 20, Ingredient::UNIT_GRAM);
        $sauce = $this->createIngredient($restaurant, 'Sauce', 15, Ingredient::UNIT_GRAM);

        $dish = $this->createDish($restaurant, 'Flatbread', 12.00);
        $this->attachRecipe($dish, $flour, 2.500);
        $this->attachRecipe($dish, $sauce, 1.000);

        $order = $this->createPendingOrderWithDish($restaurant, 'T01', $dish, 3);

        Sanctum::actingAs($staff);

        $response = $this->postJson("/api/orders/{$order->id}/confirm");

        $response->assertOk()
            ->assertJsonPath('order.status', Order::STATUS_STAFF_CONFIRMED);

        $this->assertDatabaseHas('ingredients', [
            'id' => $flour->id,
            'current_stock_quantity' => '12.500',
        ]);
        $this->assertDatabaseHas('ingredients', [
            'id' => $sauce->id,
            'current_stock_quantity' => '12.000',
        ]);

        $orderItem = OrderItem::query()->where('order_id', $order->id)->firstOrFail();

        $this->assertDatabaseHas('order_item_ingredient_usages', [
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'ingredient_id' => $flour->id,
            'recipe_quantity_per_dish' => '2.500',
            'order_item_quantity' => 3,
            'consumed_quantity' => '7.500',
        ]);
        $this->assertDatabaseHas('order_item_ingredient_usages', [
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'ingredient_id' => $sauce->id,
            'recipe_quantity_per_dish' => '1.000',
            'order_item_quantity' => 3,
            'consumed_quantity' => '3.000',
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'order_id' => $order->id,
            'ingredient_id' => $flour->id,
            'movement_type' => StockMovement::TYPE_ORDER_CONSUMPTION,
            'quantity_delta' => '-7.500',
            'quantity_before' => '20.000',
            'quantity_after' => '12.500',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'order_id' => $order->id,
            'ingredient_id' => $sauce->id,
            'movement_type' => StockMovement::TYPE_ORDER_CONSUMPTION,
            'quantity_delta' => '-3.000',
            'quantity_before' => '15.000',
            'quantity_after' => '12.000',
        ]);
    }

    public function test_confirm_fails_safely_when_stock_is_insufficient_and_rolls_back_status_change(): void
    {
        $restaurant = $this->createRestaurant();
        $this->enableFeature($restaurant, 'realtime_staff_orders');
        $this->enableFeature($restaurant, 'ingredient_stock_deduction');
        $staff = $this->createStaffUser($restaurant, ['T01']);
        $beef = $this->createIngredient($restaurant, 'Beef Patty', 5, Ingredient::UNIT_GRAM);

        $dish = $this->createDish($restaurant, 'Mini Burger', 11.00);
        $this->attachRecipe($dish, $beef, 3.000);

        $order = $this->createPendingOrderWithDish($restaurant, 'T01', $dish, 2);

        Sanctum::actingAs($staff);

        $response = $this->postJson("/api/orders/{$order->id}/confirm");

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'insufficient ingredient stock',
            (string) $response->json('message')
        );

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => Order::STATUS_PENDING_STAFF_CONFIRMATION,
            'confirmed_by' => null,
        ]);
        $this->assertDatabaseHas('ingredients', [
            'id' => $beef->id,
            'current_stock_quantity' => '5.000',
        ]);

        $this->assertSame(
            0,
            OrderItemIngredientUsage::query()->where('order_id', $order->id)->count()
        );
        $this->assertSame(
            0,
            StockMovement::query()
                ->where('order_id', $order->id)
                ->where('movement_type', StockMovement::TYPE_ORDER_CONSUMPTION)
                ->count()
        );
    }

    public function test_inventory_deduction_service_is_idempotent_for_same_confirmed_order(): void
    {
        $restaurant = $this->createRestaurant();
        $this->enableFeature($restaurant, 'realtime_staff_orders');
        $this->enableFeature($restaurant, 'ingredient_stock_deduction');
        $staff = $this->createStaffUser($restaurant, ['T01']);
        $cheese = $this->createIngredient($restaurant, 'Cheese', 10, Ingredient::UNIT_GRAM);

        $dish = $this->createDish($restaurant, 'Cheese Roll', 9.00);
        $this->attachRecipe($dish, $cheese, 2.000);

        $order = $this->createPendingOrderWithDish($restaurant, 'T01', $dish, 2);

        Sanctum::actingAs($staff);
        $this->postJson("/api/orders/{$order->id}/confirm")->assertOk();

        $usageCountBefore = OrderItemIngredientUsage::query()
            ->where('order_id', $order->id)
            ->count();
        $movementCountBefore = StockMovement::query()
            ->where('order_id', $order->id)
            ->where('movement_type', StockMovement::TYPE_ORDER_CONSUMPTION)
            ->count();
        $quantityBefore = (string) Ingredient::query()->findOrFail($cheese->id)->current_stock_quantity;

        app(OrderInventoryDeductionService::class)->deductForConfirmedOrder(
            $order->fresh(),
            $staff->id
        );

        $usageCountAfter = OrderItemIngredientUsage::query()
            ->where('order_id', $order->id)
            ->count();
        $movementCountAfter = StockMovement::query()
            ->where('order_id', $order->id)
            ->where('movement_type', StockMovement::TYPE_ORDER_CONSUMPTION)
            ->count();
        $quantityAfter = (string) Ingredient::query()->findOrFail($cheese->id)->current_stock_quantity;

        $this->assertSame($usageCountBefore, $usageCountAfter);
        $this->assertSame($movementCountBefore, $movementCountAfter);
        $this->assertSame($quantityBefore, $quantityAfter);
    }

    public function test_cancelling_a_confirmed_order_restores_inventory_from_usage_snapshots(): void
    {
        $restaurant = $this->createRestaurant();
        $this->enableFeature($restaurant, 'realtime_staff_orders');
        $this->enableFeature($restaurant, 'ingredient_stock_deduction');
        $staff = $this->createStaffUser($restaurant, ['T01']);
        $tomato = $this->createIngredient($restaurant, 'Tomato', 30, Ingredient::UNIT_GRAM);

        $dish = $this->createDish($restaurant, 'Tomato Soup', 14.00);
        $this->attachRecipe($dish, $tomato, 4.000);

        $order = $this->createPendingOrderWithDish($restaurant, 'T01', $dish, 2);

        Sanctum::actingAs($staff);
        $this->postJson("/api/orders/{$order->id}/confirm")->assertOk();

        $this->assertDatabaseHas('ingredients', [
            'id' => $tomato->id,
            'current_stock_quantity' => '22.000',
        ]);

        $cancelResponse = $this->postJson("/api/orders/{$order->id}/cancel");

        $cancelResponse->assertOk()
            ->assertJsonPath('order.status', Order::STATUS_STAFF_CANCELLED);

        $this->assertDatabaseHas('ingredients', [
            'id' => $tomato->id,
            'current_stock_quantity' => '30.000',
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'order_id' => $order->id,
            'ingredient_id' => $tomato->id,
            'movement_type' => StockMovement::TYPE_CANCELLATION_RESTORE,
            'quantity_delta' => '8.000',
            'quantity_before' => '22.000',
            'quantity_after' => '30.000',
        ]);
    }

    public function test_cancel_confirmed_order_without_prior_deduction_does_not_restore_stock(): void
    {
        $restaurant = $this->createRestaurant();
        $this->enableFeature($restaurant, 'realtime_staff_orders');
        $this->enableFeature($restaurant, 'ingredient_stock_deduction');
        $staff = $this->createStaffUser($restaurant, ['T01']);
        $onion = $this->createIngredient($restaurant, 'Onion', 9, Ingredient::UNIT_GRAM);

        $dish = $this->createDish($restaurant, 'Onion Rings', 10.00);
        $this->attachRecipe($dish, $onion, 2.000);

        $order = $this->createPendingOrderWithDish($restaurant, 'T01', $dish, 2);
        $order->update([
            'status' => Order::STATUS_STAFF_CONFIRMED,
            'confirmed_by' => $staff->id,
            'confirmed_at' => now(),
        ]);

        Sanctum::actingAs($staff);
        $response = $this->postJson("/api/orders/{$order->id}/cancel");

        $response->assertOk()
            ->assertJsonPath('order.status', Order::STATUS_STAFF_CANCELLED);

        $this->assertDatabaseHas('ingredients', [
            'id' => $onion->id,
            'current_stock_quantity' => '9.000',
        ]);

        $this->assertSame(
            0,
            StockMovement::query()
                ->where('order_id', $order->id)
                ->where('movement_type', StockMovement::TYPE_CANCELLATION_RESTORE)
                ->count()
        );
    }

    public function test_inventory_restore_service_is_idempotent_for_same_cancelled_order(): void
    {
        $restaurant = $this->createRestaurant();
        $this->enableFeature($restaurant, 'realtime_staff_orders');
        $this->enableFeature($restaurant, 'ingredient_stock_deduction');
        $staff = $this->createStaffUser($restaurant, ['T01']);
        $mint = $this->createIngredient($restaurant, 'Mint', 11, Ingredient::UNIT_GRAM);

        $dish = $this->createDish($restaurant, 'Mint Tea', 6.00);
        $this->attachRecipe($dish, $mint, 1.500);

        $order = $this->createPendingOrderWithDish($restaurant, 'T01', $dish, 2);

        Sanctum::actingAs($staff);
        $this->postJson("/api/orders/{$order->id}/confirm")->assertOk();
        $this->postJson("/api/orders/{$order->id}/cancel")->assertOk();

        $movementCountBefore = StockMovement::query()
            ->where('order_id', $order->id)
            ->where('movement_type', StockMovement::TYPE_CANCELLATION_RESTORE)
            ->count();
        $quantityBefore = (string) Ingredient::query()->findOrFail($mint->id)->current_stock_quantity;

        app(OrderInventoryDeductionService::class)->restoreForCancelledOrder(
            $order->fresh(),
            $staff->id
        );

        $movementCountAfter = StockMovement::query()
            ->where('order_id', $order->id)
            ->where('movement_type', StockMovement::TYPE_CANCELLATION_RESTORE)
            ->count();
        $quantityAfter = (string) Ingredient::query()->findOrFail($mint->id)->current_stock_quantity;

        $this->assertSame($movementCountBefore, $movementCountAfter);
        $this->assertSame($quantityBefore, $quantityAfter);
    }

    public function test_cancelling_same_order_twice_does_not_restore_stock_twice(): void
    {
        $restaurant = $this->createRestaurant();
        $this->enableFeature($restaurant, 'realtime_staff_orders');
        $this->enableFeature($restaurant, 'ingredient_stock_deduction');
        $staff = $this->createStaffUser($restaurant, ['T01']);
        $oliveOil = $this->createIngredient($restaurant, 'Olive Oil', 10, Ingredient::UNIT_MILLILITER);

        $dish = $this->createDish($restaurant, 'Garlic Bread', 8.50);
        $this->attachRecipe($dish, $oliveOil, 2.000);

        $order = $this->createPendingOrderWithDish($restaurant, 'T01', $dish, 2);

        Sanctum::actingAs($staff);
        $this->postJson("/api/orders/{$order->id}/confirm")->assertOk();
        $this->postJson("/api/orders/{$order->id}/cancel")->assertOk();

        $restorationCountAfterFirstCancel = StockMovement::query()
            ->where('order_id', $order->id)
            ->where('movement_type', StockMovement::TYPE_CANCELLATION_RESTORE)
            ->count();
        $quantityAfterFirstCancel = (string) Ingredient::query()->findOrFail($oliveOil->id)->current_stock_quantity;

        $secondCancelResponse = $this->postJson("/api/orders/{$order->id}/cancel");

        $secondCancelResponse->assertStatus(422);

        $restorationCountAfterSecondCancel = StockMovement::query()
            ->where('order_id', $order->id)
            ->where('movement_type', StockMovement::TYPE_CANCELLATION_RESTORE)
            ->count();
        $quantityAfterSecondCancel = (string) Ingredient::query()->findOrFail($oliveOil->id)->current_stock_quantity;

        $this->assertSame($restorationCountAfterFirstCancel, $restorationCountAfterSecondCancel);
        $this->assertSame($quantityAfterFirstCancel, $quantityAfterSecondCancel);
    }

    public function test_usage_snapshot_uses_recipe_values_at_confirmation_time(): void
    {
        $restaurant = $this->createRestaurant();
        $this->enableFeature($restaurant, 'realtime_staff_orders');
        $this->enableFeature($restaurant, 'ingredient_stock_deduction');
        $staff = $this->createStaffUser($restaurant, ['T01']);
        $rice = $this->createIngredient($restaurant, 'Rice', 20, Ingredient::UNIT_GRAM);

        $dish = $this->createDish($restaurant, 'Rice Bowl', 13.00);
        $dishIngredient = $this->attachRecipe($dish, $rice, 1.000);
        $order = $this->createPendingOrderWithDish($restaurant, 'T01', $dish, 2);

        // Recipe changed before confirmation; snapshot should use this latest value.
        $dishIngredient->update([
            'quantity' => '2.500',
        ]);

        Sanctum::actingAs($staff);
        $this->postJson("/api/orders/{$order->id}/confirm")->assertOk();

        $usage = OrderItemIngredientUsage::query()
            ->where('order_id', $order->id)
            ->where('ingredient_id', $rice->id)
            ->firstOrFail();

        $this->assertSame('2.500', (string) $usage->recipe_quantity_per_dish);
        $this->assertSame('5.000', (string) $usage->consumed_quantity);
        $this->assertDatabaseHas('ingredients', [
            'id' => $rice->id,
            'current_stock_quantity' => '15.000',
        ]);
    }

    public function test_recipe_changes_after_confirmation_do_not_alter_existing_usage_history(): void
    {
        $restaurant = $this->createRestaurant();
        $this->enableFeature($restaurant, 'realtime_staff_orders');
        $this->enableFeature($restaurant, 'ingredient_stock_deduction');
        $staff = $this->createStaffUser($restaurant, ['T01']);
        $milk = $this->createIngredient($restaurant, 'Milk', 30, Ingredient::UNIT_MILLILITER);

        $dish = $this->createDish($restaurant, 'Milkshake', 7.00);
        $dishIngredient = $this->attachRecipe($dish, $milk, 1.500);
        $order = $this->createPendingOrderWithDish($restaurant, 'T01', $dish, 2);

        Sanctum::actingAs($staff);
        $this->postJson("/api/orders/{$order->id}/confirm")->assertOk();

        $usageBeforeRecipeChange = OrderItemIngredientUsage::query()
            ->where('order_id', $order->id)
            ->where('ingredient_id', $milk->id)
            ->firstOrFail();

        $this->assertSame('1.500', (string) $usageBeforeRecipeChange->recipe_quantity_per_dish);
        $this->assertSame('3.000', (string) $usageBeforeRecipeChange->consumed_quantity);

        $dishIngredient->update([
            'quantity' => '5.000',
        ]);

        $usageAfterRecipeChange = OrderItemIngredientUsage::query()
            ->where('order_id', $order->id)
            ->where('ingredient_id', $milk->id)
            ->firstOrFail();

        $this->assertSame('1.500', (string) $usageAfterRecipeChange->recipe_quantity_per_dish);
        $this->assertSame('3.000', (string) $usageAfterRecipeChange->consumed_quantity);
    }

    public function test_packaged_item_uses_direct_stock_deduction_and_restores_on_cancel(): void
    {
        $restaurant = $this->createRestaurant();
        $this->enableFeature($restaurant, 'realtime_staff_orders');
        $this->enableFeature($restaurant, 'ingredient_stock_deduction');
        $staff = $this->createStaffUser($restaurant, ['T01']);
        $pepsiStock = $this->createIngredient($restaurant, 'Pepsi Can', 10, Ingredient::UNIT_PIECE);

        $pepsi = $this->createDish($restaurant, 'Pepsi', 2.50, [
            'item_type' => Dish::ITEM_TYPE_PACKAGED_DRINK,
            'direct_stock_ingredient_id' => $pepsiStock->id,
            'direct_stock_quantity_per_sale' => '1.000',
        ]);

        $order = $this->createPendingOrderWithDish($restaurant, 'T01', $pepsi, 3);

        Sanctum::actingAs($staff);
        $this->postJson("/api/orders/{$order->id}/confirm")->assertOk();

        $this->assertDatabaseHas('ingredients', [
            'id' => $pepsiStock->id,
            'current_stock_quantity' => '7.000',
        ]);
        $this->assertDatabaseMissing('order_item_ingredient_usages', [
            'order_id' => $order->id,
            'ingredient_id' => $pepsiStock->id,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'order_id' => $order->id,
            'dish_id' => $pepsi->id,
            'ingredient_id' => $pepsiStock->id,
            'movement_type' => StockMovement::TYPE_ORDER_CONSUMPTION,
            'inventory_source' => StockMovement::SOURCE_DIRECT_PACKAGED_SALE,
            'quantity_delta' => '-3.000',
        ]);

        $this->postJson("/api/orders/{$order->id}/cancel")->assertOk();
        $this->assertDatabaseHas('ingredients', [
            'id' => $pepsiStock->id,
            'current_stock_quantity' => '10.000',
        ]);
    }

    private function createRestaurant(?User $owner = null): Restaurant
    {
        $admin = $owner ?? User::factory()->admin()->create();

        $restaurant = Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'name' => 'Inventory Deduction '.Str::upper(Str::random(4)),
            'slug' => 'inventory-deduction-'.Str::lower(Str::random(8)),
            'description' => 'Inventory deduction test restaurant',
            'address' => 'Beirut',
        ]);

        foreach (range(1, 10) as $number) {
            RestaurantTable::query()->create([
                'restaurant_id' => $restaurant->id,
                'name' => sprintf('T%02d', $number),
                'is_active' => true,
            ]);
        }

        return $restaurant;
    }

    private function createStaffUser(Restaurant $restaurant, array $tableNames = []): User
    {
        $staff = User::factory()->staff()->create();
        $restaurant->staffUsers()->attach($staff->id);

        if ($tableNames !== []) {
            $tableIds = $restaurant->tables()
                ->whereIn('name', $tableNames)
                ->pluck('id')
                ->all();

            $staff->assignedTables()->sync($tableIds);
        }

        return $staff;
    }

    private function createIngredient(
        Restaurant $restaurant,
        string $name,
        float $quantity,
        string $unit
    ): Ingredient {
        return Ingredient::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => $name,
            'name_ar' => null,
            'storage_disk' => 'public',
            'file_path' => null,
            'source_file_name' => null,
            'file_size' => null,
            'mime_type' => null,
            'stock_unit' => $unit,
            'current_stock_quantity' => number_format($quantity, 3, '.', ''),
            'low_stock_threshold' => '0.000',
            'is_active' => true,
        ]);
    }

    private function createDish(Restaurant $restaurant, string $name, float $price, array $overrides = []): Dish
    {
        return Dish::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => $name,
            'description' => $name.' description',
            'price' => $price,
            'category' => 'Main',
            'status' => 'published',
            'item_type' => Dish::ITEM_TYPE_PREPARED_DISH,
        ], $overrides));
    }

    private function attachRecipe(Dish $dish, Ingredient $ingredient, float $quantity): DishIngredient
    {
        return DishIngredient::query()->create([
            'dish_id' => $dish->id,
            'ingredient_id' => $ingredient->id,
            'quantity' => number_format($quantity, 3, '.', ''),
            'unit' => $ingredient->stock_unit,
        ]);
    }

    private function createPendingOrderWithDish(
        Restaurant $restaurant,
        string $tableReference,
        Dish $dish,
        int $quantity
    ): Order {
        $tableId = $restaurant->tables()
            ->where('name', $tableReference)
            ->value('id');

        $unitPrice = (float) $dish->price;
        $subtotal = $unitPrice * $quantity;

        $order = Order::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'restaurant_table_id' => $tableId,
            'order_number' => 'ORD-TEST-'.Str::upper(Str::random(6)),
            'status' => Order::STATUS_PENDING_STAFF_CONFIRMATION,
            'guest_name' => $tableReference,
            'table_reference' => $tableReference,
            'subtotal' => number_format($subtotal, 2, '.', ''),
            'taxable_subtotal' => number_format($subtotal, 2, '.', ''),
            'total' => number_format($subtotal, 2, '.', ''),
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'dish_id' => $dish->id,
            'dish_name' => $dish->name,
            'unit_price' => number_format($unitPrice, 2, '.', ''),
            'quantity' => $quantity,
            'line_subtotal' => number_format($subtotal, 2, '.', ''),
        ]);

        return $order;
    }

    private function enableFeature(Restaurant $restaurant, string $featureKey): void
    {
        $feature = Feature::query()->updateOrCreate(
            ['key' => $featureKey],
            [
                'name' => Str::title(str_replace('_', ' ', $featureKey)),
                'description' => 'Enabled in tests',
                'category' => 'Testing',
                'is_active_by_default' => false,
            ]
        );

        RestaurantFeature::query()->updateOrCreate(
            [
                'restaurant_id' => $restaurant->id,
                'feature_id' => $feature->id,
            ],
            [
                'enabled' => true,
            ]
        );
    }
}
