<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\DishIngredient;
use App\Models\Ingredient;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemIngredientUsage;
use App\Models\Restaurant;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryContractsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_checkout_rejects_dish_when_recipe_has_inactive_ingredient(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $restaurant = $this->createRestaurant($admin, 'inventory-contract-orderable');

        $dish = Dish::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => 'Inactive Ingredient Bowl',
            'description' => 'Test dish',
            'price' => 15.00,
            'status' => 'published',
            'category' => 'Main',
        ]);

        $ingredient = Ingredient::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => 'Paused Tomato',
            'stock_unit' => Ingredient::UNIT_GRAM,
            'current_stock_quantity' => 1000,
            'low_stock_threshold' => 100,
            'target_quantity' => 500,
            'is_active' => false,
            'storage_disk' => 'public',
            'file_path' => null,
            'source_file_name' => null,
            'file_size' => null,
            'mime_type' => null,
        ]);

        DishIngredient::query()->create([
            'dish_id' => $dish->id,
            'ingredient_id' => $ingredient->id,
            'quantity' => 50,
            'unit' => Ingredient::UNIT_GRAM,
            'order_index' => 0,
            'show_in_animation' => true,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/pos/checkout', [
            'table_reference' => 'T01',
            'payment_method' => 'cash',
            'amount_received' => 20,
            'items' => [
                [
                    'dish_id' => $dish->id,
                    'quantity' => 1,
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'The following dishes are unavailable: Inactive Ingredient Bowl');
    }

    public function test_stock_history_api_includes_movement_unit_field(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $restaurant = $this->createRestaurant($admin, 'inventory-contract-stock-history');

        $ingredient = Ingredient::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => 'Olive Oil',
            'stock_unit' => Ingredient::UNIT_MILLILITER,
            'current_stock_quantity' => 900,
            'low_stock_threshold' => 100,
            'target_quantity' => 1000,
            'is_active' => true,
            'storage_disk' => 'public',
            'file_path' => null,
            'source_file_name' => null,
            'file_size' => null,
            'mime_type' => null,
        ]);

        StockMovement::query()->create([
            'restaurant_id' => $restaurant->id,
            'ingredient_id' => $ingredient->id,
            'performed_by' => $admin->id,
            'movement_type' => StockMovement::TYPE_RESTOCK,
            'unit' => Ingredient::UNIT_MILLILITER,
            'quantity_delta' => 150,
            'quantity_before' => 750,
            'quantity_after' => 900,
            'ingredient_name_snapshot' => $ingredient->name,
            'reference' => 'test-restock',
            'notes' => 'Contract test movement',
            'occurred_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/inventory/stock-history');

        $response->assertOk();
        $response->assertJsonPath('movements.0.movement_type', StockMovement::TYPE_RESTOCK);
        $response->assertJsonPath('movements.0.unit', Ingredient::UNIT_MILLILITER);
        $response->assertJsonPath('movements.0.dish_name', null);
    }

    public function test_stock_history_api_includes_aggregated_dish_names_for_order_consumption(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $restaurant = $this->createRestaurant($admin, 'inventory-contract-dish-summary');

        $ingredient = Ingredient::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => 'Tomato',
            'stock_unit' => Ingredient::UNIT_GRAM,
            'current_stock_quantity' => 1000,
            'low_stock_threshold' => 100,
            'target_quantity' => 1000,
            'is_active' => true,
            'storage_disk' => 'public',
            'file_path' => null,
            'source_file_name' => null,
            'file_size' => null,
            'mime_type' => null,
        ]);

        $dishOne = Dish::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => 'Tomato Soup',
            'description' => 'Soup',
            'price' => 10.00,
            'status' => 'published',
            'category' => 'Main',
        ]);
        $dishTwo = Dish::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => 'Greek Salad',
            'description' => 'Salad',
            'price' => 11.00,
            'status' => 'published',
            'category' => 'Main',
        ]);

        $order = Order::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'restaurant_table_id' => $restaurant->tables()->value('id'),
            'order_number' => 'ORD-'.Str::upper(Str::random(8)),
            'status' => Order::STATUS_STAFF_CONFIRMED,
            'guest_name' => 'T01',
            'table_reference' => 'T01',
            'subtotal' => '21.00',
            'taxable_subtotal' => '21.00',
            'total' => '21.00',
        ]);

        $itemOne = OrderItem::query()->create([
            'order_id' => $order->id,
            'dish_id' => $dishOne->id,
            'dish_name' => $dishOne->name,
            'unit_price' => '10.00',
            'quantity' => 1,
            'line_subtotal' => '10.00',
        ]);
        $itemTwo = OrderItem::query()->create([
            'order_id' => $order->id,
            'dish_id' => $dishTwo->id,
            'dish_name' => $dishTwo->name,
            'unit_price' => '11.00',
            'quantity' => 1,
            'line_subtotal' => '11.00',
        ]);

        OrderItemIngredientUsage::query()->create([
            'restaurant_id' => $restaurant->id,
            'order_id' => $order->id,
            'order_item_id' => $itemOne->id,
            'dish_id' => $dishOne->id,
            'dish_ingredient_id' => null,
            'ingredient_id' => $ingredient->id,
            'ingredient_name_snapshot' => $ingredient->name,
            'unit' => Ingredient::UNIT_GRAM,
            'recipe_quantity_per_dish' => '100.000',
            'order_item_quantity' => 1,
            'consumed_quantity' => '100.000',
        ]);
        OrderItemIngredientUsage::query()->create([
            'restaurant_id' => $restaurant->id,
            'order_id' => $order->id,
            'order_item_id' => $itemTwo->id,
            'dish_id' => $dishTwo->id,
            'dish_ingredient_id' => null,
            'ingredient_id' => $ingredient->id,
            'ingredient_name_snapshot' => $ingredient->name,
            'unit' => Ingredient::UNIT_GRAM,
            'recipe_quantity_per_dish' => '80.000',
            'order_item_quantity' => 1,
            'consumed_quantity' => '80.000',
        ]);

        StockMovement::query()->create([
            'restaurant_id' => $restaurant->id,
            'ingredient_id' => $ingredient->id,
            'order_id' => $order->id,
            'order_item_id' => null,
            'performed_by' => $admin->id,
            'movement_type' => StockMovement::TYPE_ORDER_CONSUMPTION,
            'unit' => Ingredient::UNIT_GRAM,
            'quantity_delta' => -180,
            'quantity_before' => 1000,
            'quantity_after' => 820,
            'ingredient_name_snapshot' => $ingredient->name,
            'reference' => 'order:'.$order->id,
            'notes' => 'Aggregated movement',
            'occurred_at' => now(),
        ]);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/inventory/stock-history?movement_type='.StockMovement::TYPE_ORDER_CONSUMPTION);

        $response->assertOk();
        $response->assertJsonPath('movements.0.dish_name', 'Tomato Soup, Greek Salad');
    }

    public function test_stock_history_api_includes_single_dish_name_for_order_consumption(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $restaurant = $this->createRestaurant($admin, 'inventory-contract-single-dish');

        $ingredient = Ingredient::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => 'Cheese',
            'stock_unit' => Ingredient::UNIT_GRAM,
            'current_stock_quantity' => 500,
            'low_stock_threshold' => 50,
            'target_quantity' => 500,
            'is_active' => true,
            'storage_disk' => 'public',
            'file_path' => null,
            'source_file_name' => null,
            'file_size' => null,
            'mime_type' => null,
        ]);

        $dish = Dish::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => 'Cheese Pasta',
            'description' => 'Pasta',
            'price' => 12.00,
            'status' => 'published',
            'category' => 'Main',
        ]);

        $invoice = Invoice::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'invoice_number' => 'INV-TEST-001',
            'invoice_date' => now()->toDateString(),
            'status' => Invoice::STATUS_ISSUED,
            'subtotal' => '12.00',
            'total' => '12.00',
            'notes' => null,
            'paid_at' => null,
        ]);

        $order = Order::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'restaurant_table_id' => $restaurant->tables()->value('id'),
            'order_number' => 'ORD-'.Str::upper(Str::random(8)),
            'invoice_number' => $invoice->invoice_number,
            'status' => Order::STATUS_STAFF_CONFIRMED,
            'guest_name' => 'T01',
            'table_reference' => 'T01',
            'subtotal' => '12.00',
            'taxable_subtotal' => '12.00',
            'total' => '12.00',
        ]);

        $orderItem = OrderItem::query()->create([
            'order_id' => $order->id,
            'dish_id' => $dish->id,
            'dish_name' => $dish->name,
            'unit_price' => '12.00',
            'quantity' => 1,
            'line_subtotal' => '12.00',
        ]);

        OrderItemIngredientUsage::query()->create([
            'restaurant_id' => $restaurant->id,
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'dish_id' => $dish->id,
            'dish_ingredient_id' => null,
            'ingredient_id' => $ingredient->id,
            'ingredient_name_snapshot' => $ingredient->name,
            'unit' => Ingredient::UNIT_GRAM,
            'recipe_quantity_per_dish' => '50.000',
            'order_item_quantity' => 1,
            'consumed_quantity' => '50.000',
        ]);

        StockMovement::query()->create([
            'restaurant_id' => $restaurant->id,
            'ingredient_id' => $ingredient->id,
            'order_id' => $order->id,
            'order_item_id' => null,
            'performed_by' => $admin->id,
            'movement_type' => StockMovement::TYPE_ORDER_CONSUMPTION,
            'unit' => Ingredient::UNIT_GRAM,
            'quantity_delta' => -50,
            'quantity_before' => 500,
            'quantity_after' => 450,
            'ingredient_name_snapshot' => $ingredient->name,
            'reference' => 'order:'.$order->id,
            'notes' => 'Single dish movement',
            'occurred_at' => now(),
        ]);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/inventory/stock-history?movement_type='.StockMovement::TYPE_ORDER_CONSUMPTION);

        $response->assertOk();
        $response->assertJsonPath('movements.0.dish_name', 'Cheese Pasta');
        $response->assertJsonPath('movements.0.order_number', $order->order_number);
        $response->assertJsonPath('movements.0.invoice_number', $invoice->invoice_number);
        $response->assertJsonPath('movements.0.invoice_id', $invoice->id);
    }

    private function createRestaurant(User $owner, string $slug): Restaurant
    {
        return Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'name' => Str::headline($slug),
            'slug' => $slug,
            'description' => 'Inventory contract test restaurant',
            'address' => 'Beirut',
        ]);
    }
}
