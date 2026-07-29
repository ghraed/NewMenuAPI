<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\DishIngredient;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Feature;
use App\Models\Ingredient;
use App\Models\Restaurant;
use App\Models\RestaurantFeature;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryIngredientApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_update_restock_adjust_and_review_stock_history_with_linked_purchase_entry(): void
    {
        $admin = User::factory()->admin()->create();
        $restaurant = $this->createRestaurant($admin);
        $this->enableFeature($restaurant, 'inventory');

        $expenseCategory = ExpenseCategory::factory()->create([
            'restaurant_id' => $restaurant->id,
            'code' => 'restock_food',
            'name' => 'Ingredient Purchases',
        ]);
        $vendor = Vendor::factory()->create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Fresh Foods Supplier',
        ]);

        Sanctum::actingAs($admin);

        $createResponse = $this->postJson('/api/inventory/ingredients', [
            'name' => 'Olive Oil',
            'unit' => Ingredient::UNIT_MILLILITER,
            'current_quantity' => 12.345,
            'low_stock_threshold' => 3.000,
            'target_quantity' => 25.000,
            'is_active' => true,
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('ingredient.name', 'Olive Oil')
            ->assertJsonPath('ingredient.unit', Ingredient::UNIT_MILLILITER)
            ->assertJsonPath('ingredient.current_quantity', '12.345');

        $ingredientId = (int) $createResponse->json('ingredient.id');

        $this->patchJson("/api/inventory/ingredients/{$ingredientId}", [
            'name' => 'Extra Virgin Olive Oil',
            'unit' => Ingredient::UNIT_MILLILITER,
            'low_stock_threshold' => 3.000,
            'target_quantity' => 30.000,
            'is_active' => true,
        ])->assertOk()
            ->assertJsonPath('ingredient.name', 'Extra Virgin Olive Oil')
            ->assertJsonPath('ingredient.target_quantity', '30.000');

        $restockResponse = $this->postJson("/api/inventory/ingredients/{$ingredientId}/restock", [
            'quantity' => 4.555,
            'reference' => 'PO-77',
            'notes' => 'Weekly purchase',
            'create_expense' => true,
            'expense_category_id' => $expenseCategory->id,
            'expense_vendor_id' => $vendor->id,
            'expense_amount_cents' => 1500,
            'expense_tax_amount_cents' => 300,
            'expense_currency' => 'USD',
            'expense_status' => Expense::STATUS_PAID,
            'expense_payment_method' => 'cash',
        ]);

        $restockResponse->assertOk()
            ->assertJsonPath('ingredient.current_quantity', '16.900')
            ->assertJsonPath('restock_finance.status', 'linked');

        $expenseId = (int) $restockResponse->json('restock_finance.expense_id');
        $movementId = (int) $restockResponse->json('restock_finance.stock_movement_id');

        $this->assertDatabaseHas('expenses', [
            'id' => $expenseId,
            'restaurant_id' => $restaurant->id,
            'expense_category_id' => $expenseCategory->id,
            'vendor_id' => $vendor->id,
            'amount_cents' => 1500,
            'tax_amount_cents' => 300,
            'currency' => 'USD',
            'status' => Expense::STATUS_PAID,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'id' => $movementId,
            'ingredient_id' => $ingredientId,
            'movement_type' => StockMovement::TYPE_RESTOCK,
            'quantity_delta' => '4.555',
            'quantity_before' => '12.345',
            'quantity_after' => '16.900',
            'linked_expense_id' => $expenseId,
        ]);

        $adjustResponse = $this->postJson("/api/inventory/ingredients/{$ingredientId}/adjust", [
            'quantity_delta' => -14.000,
            'reference' => 'WASTE-1',
            'notes' => 'Kitchen waste',
        ]);

        $adjustResponse->assertOk()
            ->assertJsonPath('ingredient.current_quantity', '2.900');

        $this->getJson('/api/inventory/ingredients')
            ->assertOk()
            ->assertJsonPath('ingredients.0.current_quantity', '2.900')
            ->assertJsonPath('ingredients.0.is_low_stock', true);

        $historyResponse = $this->getJson('/api/inventory/stock-history');
        $historyResponse->assertOk()
            ->assertJsonCount(3, 'movements')
            ->assertJsonPath('movements.0.movement_type', StockMovement::TYPE_MANUAL_ADJUSTMENT)
            ->assertJsonPath('movements.0.quantity', '-14.000')
            ->assertJsonPath('movements.0.quantity_before', '16.900')
            ->assertJsonPath('movements.0.quantity_after', '2.900')
            ->assertJsonPath('movements.1.movement_type', StockMovement::TYPE_RESTOCK)
            ->assertJsonPath('movements.1.linked_expense_id', $expenseId)
            ->assertJsonPath('movements.1.unit', Ingredient::UNIT_MILLILITER)
            ->assertJsonPath('movements.2.movement_type', StockMovement::TYPE_OPENING_BALANCE)
            ->assertJsonPath('movements.2.quantity', '12.345');
    }

    public function test_inventory_api_rejects_invalid_units_negative_values_and_unsafe_unit_changes_for_recipe_ingredients(): void
    {
        $admin = User::factory()->admin()->create();
        $restaurant = $this->createRestaurant($admin);
        $this->enableFeature($restaurant, 'inventory');

        Sanctum::actingAs($admin);

        $this->postJson('/api/inventory/ingredients', [
            'name' => 'Invalid Unit',
            'unit' => 'kg',
            'current_quantity' => 1,
            'low_stock_threshold' => 0,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['unit']);

        $this->postJson('/api/inventory/ingredients', [
            'name' => 'Negative Quantity',
            'unit' => Ingredient::UNIT_GRAM,
            'current_quantity' => -1,
            'low_stock_threshold' => 0,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['current_quantity']);

        $createResponse = $this->postJson('/api/inventory/ingredients', [
            'name' => 'Flour',
            'unit' => Ingredient::UNIT_GRAM,
            'current_quantity' => 10,
            'low_stock_threshold' => 1,
            'target_quantity' => 20,
        ])->assertCreated();

        $ingredientId = (int) $createResponse->json('ingredient.id');

        $dish = Dish::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => 'Flatbread',
            'description' => 'Bread',
            'price' => 8.00,
            'category' => 'Main',
            'status' => 'published',
            'item_type' => Dish::ITEM_TYPE_PREPARED_DISH,
        ]);

        DishIngredient::query()->create([
            'dish_id' => $dish->id,
            'ingredient_id' => $ingredientId,
            'quantity' => '2.500',
            'unit' => Ingredient::UNIT_GRAM,
        ]);

        $this->patchJson("/api/inventory/ingredients/{$ingredientId}", [
            'name' => 'Flour',
            'unit' => Ingredient::UNIT_PIECE,
            'low_stock_threshold' => 1,
            'target_quantity' => 20,
            'is_active' => true,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['unit']);

        $this->postJson("/api/inventory/ingredients/{$ingredientId}/restock", [
            'quantity' => -5,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['quantity']);

        $this->postJson("/api/inventory/ingredients/{$ingredientId}/adjust", [
            'quantity_delta' => -999,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['quantity_delta']);
    }

    public function test_inventory_api_accepts_extremely_large_quantities_and_enforces_tenant_isolation(): void
    {
        $adminOne = User::factory()->admin()->create();
        $restaurantOne = $this->createRestaurant($adminOne);
        $this->enableFeature($restaurantOne, 'inventory');

        Sanctum::actingAs($adminOne);

        $createResponse = $this->postJson('/api/inventory/ingredients', [
            'name' => 'Bulk Rice',
            'unit' => Ingredient::UNIT_GRAM,
            'current_quantity' => 999999999.999,
            'low_stock_threshold' => 10.000,
            'target_quantity' => 999999999.999,
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('ingredient.current_quantity', '999999999.999');

        $ingredientId = (int) $createResponse->json('ingredient.id');

        $adminTwo = User::factory()->admin()->create();
        $restaurantTwo = $this->createRestaurant($adminTwo);
        $this->enableFeature($restaurantTwo, 'inventory');

        Sanctum::actingAs($adminTwo);

        $this->patchJson("/api/inventory/ingredients/{$ingredientId}", [
            'name' => 'Stolen Rice',
            'unit' => Ingredient::UNIT_GRAM,
            'low_stock_threshold' => 10.000,
            'target_quantity' => 999999999.999,
            'is_active' => true,
        ])->assertStatus(404);

        $this->postJson("/api/inventory/ingredients/{$ingredientId}/restock", [
            'quantity' => 1,
        ])->assertStatus(404);

        $this->postJson("/api/inventory/ingredients/{$ingredientId}/adjust", [
            'quantity_delta' => -1,
        ])->assertStatus(404);

        $this->getJson('/api/inventory/stock-history')
            ->assertOk()
            ->assertJsonCount(0, 'movements');
    }

    public function test_inventory_api_supports_quantity_unit_conversion_for_restock_and_adjustment(): void
    {
        $admin = User::factory()->admin()->create();
        $restaurant = $this->createRestaurant($admin);
        $this->enableFeature($restaurant, 'inventory');

        Sanctum::actingAs($admin);

        $createResponse = $this->postJson('/api/inventory/ingredients', [
            'name' => 'Bread Flour',
            'unit' => Ingredient::UNIT_GRAM,
            'current_quantity' => 1000,
            'low_stock_threshold' => 500,
            'target_quantity' => 3000,
        ])->assertCreated();

        $ingredientId = (int) $createResponse->json('ingredient.id');

        $this->postJson("/api/inventory/ingredients/{$ingredientId}/restock", [
            'quantity' => 1.5,
            'unit' => 'kg',
            'reference' => 'KG-RESTOCK',
        ])->assertOk()
            ->assertJsonPath('ingredient.current_quantity', '2500.000');

        $this->postJson("/api/inventory/ingredients/{$ingredientId}/adjust", [
            'quantity_delta' => -0.25,
            'unit' => 'kg',
            'reference' => 'KG-WASTE',
        ])->assertOk()
            ->assertJsonPath('ingredient.current_quantity', '2250.000');

        $historyResponse = $this->getJson('/api/inventory/stock-history');
        $historyResponse->assertOk()
            ->assertJsonPath('movements.0.reference_id', 'KG-WASTE')
            ->assertJsonPath('movements.0.quantity', '-250.000')
            ->assertJsonPath('movements.0.unit', Ingredient::UNIT_GRAM)
            ->assertJsonPath('movements.1.reference_id', 'KG-RESTOCK')
            ->assertJsonPath('movements.1.quantity', '1500.000')
            ->assertJsonPath('movements.1.unit', Ingredient::UNIT_GRAM);
    }

    public function test_inventory_api_can_delete_ingredient_without_losing_stock_history_snapshots(): void
    {
        $admin = User::factory()->admin()->create();
        $restaurant = $this->createRestaurant($admin);
        $this->enableFeature($restaurant, 'inventory');

        Sanctum::actingAs($admin);

        $createResponse = $this->postJson('/api/inventory/ingredients', [
            'name' => 'Delete Me',
            'unit' => Ingredient::UNIT_PIECE,
            'current_quantity' => 12,
            'low_stock_threshold' => 2,
            'target_quantity' => 20,
        ])->assertCreated();

        $ingredientId = (int) $createResponse->json('ingredient.id');

        $this->postJson("/api/inventory/ingredients/{$ingredientId}/adjust", [
            'quantity_delta' => -2,
            'reference' => 'DELETE-WASTE',
        ])->assertOk();

        $this->deleteJson("/api/inventory/ingredients/{$ingredientId}")
            ->assertOk()
            ->assertJsonPath('message', 'Ingredient deleted successfully.');

        $this->assertDatabaseMissing('ingredients', [
            'id' => $ingredientId,
        ]);

        $historyResponse = $this->getJson('/api/inventory/stock-history');
        $historyResponse->assertOk()
            ->assertJsonCount(2, 'movements')
            ->assertJsonPath('movements.0.ingredient_name', 'Delete Me')
            ->assertJsonPath('movements.0.quantity', '-2.000')
            ->assertJsonPath('movements.1.ingredient_name', 'Delete Me')
            ->assertJsonPath('movements.1.quantity', '12.000');
    }

    private function createRestaurant(User $owner): Restaurant
    {
        return Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'name' => 'Inventory Ingredient '.Str::upper(Str::random(4)),
            'slug' => 'inventory-ingredient-'.Str::lower(Str::random(8)),
            'description' => 'Inventory ingredient test restaurant',
            'address' => 'Beirut',
        ]);
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
