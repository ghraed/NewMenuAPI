<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\DishIngredient;
use App\Models\Feature;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\RestaurantFeature;
use App\Models\RestaurantTable;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_order_confirmation_rolls_back_when_a_competing_transaction_holds_the_ingredient_lock(): void
    {
        $restaurant = $this->createRestaurant();
        $this->enableFeature($restaurant, 'realtime_staff_orders');
        $this->enableFeature($restaurant, 'ingredient_stock_deduction');
        $staff = $this->createStaffUser($restaurant, ['T01']);
        $beef = $this->createIngredient($restaurant, 'Beef', 10, Ingredient::UNIT_GRAM);

        $dish = $this->createDish($restaurant, 'Beef Bowl', 16.00);
        $this->attachRecipe($dish, $beef, 4.000);
        $order = $this->createPendingOrderWithDish($restaurant, 'T01', $dish, 1);

        $lockConnectionName = $this->beginSecondaryIngredientLock($beef->id);

        try {
            DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
            Sanctum::actingAs($staff);

            $response = $this->postJson("/api/orders/{$order->id}/confirm");

            $response->assertStatus(422);

            $this->assertDatabaseHas('orders', [
                'id' => $order->id,
                'status' => Order::STATUS_PENDING_STAFF_CONFIRMATION,
                'confirmed_by' => null,
            ]);
            $this->assertDatabaseHas('ingredients', [
                'id' => $beef->id,
                'current_stock_quantity' => '10.000',
            ]);
            $this->assertSame(
                0,
                StockMovement::query()
                    ->where('order_id', $order->id)
                    ->where('movement_type', StockMovement::TYPE_ORDER_CONSUMPTION)
                    ->count()
            );
            $this->assertSame(
                0,
                DB::table('order_item_ingredient_usages')
                    ->where('order_id', $order->id)
                    ->count()
            );
        } finally {
            DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
            $this->releaseSecondaryConnection($lockConnectionName);
        }
    }

    private function beginSecondaryIngredientLock(int $ingredientId): string
    {
        $connectionName = 'mysql_lock_'.Str::lower(Str::random(8));

        config([
            "database.connections.{$connectionName}" => config('database.connections.mysql'),
        ]);

        DB::purge($connectionName);
        $connection = DB::connection($connectionName);
        $connection->statement('SET SESSION innodb_lock_wait_timeout = 5');
        $connection->beginTransaction();
        $connection->table('ingredients')
            ->where('id', $ingredientId)
            ->lockForUpdate()
            ->first();

        return $connectionName;
    }

    private function releaseSecondaryConnection(string $connectionName): void
    {
        $connection = DB::connection($connectionName);

        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $connection->disconnect();
        DB::purge($connectionName);
    }

    private function createRestaurant(?User $owner = null): Restaurant
    {
        $admin = $owner ?? User::factory()->admin()->create();

        $restaurant = Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'name' => 'Inventory Lock '.Str::upper(Str::random(4)),
            'slug' => 'inventory-lock-'.Str::lower(Str::random(8)),
            'description' => 'Inventory concurrency test restaurant',
            'address' => 'Beirut',
        ]);

        foreach (range(1, 10) as $number) {
            RestaurantTable::query()->updateOrCreate(
                [
                    'restaurant_id' => $restaurant->id,
                    'name' => sprintf('T%02d', $number),
                ],
                [
                    'is_active' => true,
                ]
            );
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

    private function createIngredient(Restaurant $restaurant, string $name, float $quantity, string $unit): Ingredient
    {
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

    private function createDish(Restaurant $restaurant, string $name, float $price): Dish
    {
        return Dish::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => $name,
            'description' => $name.' description',
            'price' => $price,
            'category' => 'Main',
            'status' => 'published',
            'item_type' => Dish::ITEM_TYPE_PREPARED_DISH,
        ]);
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

    private function createPendingOrderWithDish(Restaurant $restaurant, string $tableReference, Dish $dish, int $quantity): Order
    {
        $tableId = $restaurant->tables()
            ->where('name', $tableReference)
            ->value('id');

        $unitPrice = (float) $dish->price;
        $subtotal = $unitPrice * $quantity;

        $order = Order::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'restaurant_table_id' => $tableId,
            'order_number' => 'ORD-LOCK-'.Str::upper(Str::random(6)),
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
