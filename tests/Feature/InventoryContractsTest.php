<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\DishIngredient;
use App\Models\Ingredient;
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
