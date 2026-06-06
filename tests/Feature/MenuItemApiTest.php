<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\Ingredient;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MenuItemApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_prepared_dish_menu_item(): void
    {
        [$admin, $restaurant] = $this->adminContext();
        $ingredient = $this->createIngredient($restaurant, 'Flour');
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/menu-items', [
            'name' => 'Burger',
            'price' => 12,
            'category' => 'Main',
            'item_type' => Dish::ITEM_TYPE_PREPARED_DISH,
            'recipe_ingredients' => [
                ['ingredient_id' => $ingredient->id, 'quantity_required' => 1.5],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('item_type', Dish::ITEM_TYPE_PREPARED_DISH);
    }

    public function test_packaged_drink_cannot_accept_recipe_ingredients(): void
    {
        [$admin, $restaurant] = $this->adminContext();
        $ingredient = $this->createIngredient($restaurant, 'Pepsi Can');
        $recipeIngredient = $this->createIngredient($restaurant, 'Syrup');
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/menu-items', [
            'name' => 'Pepsi',
            'price' => 2,
            'category' => 'Drinks',
            'item_type' => Dish::ITEM_TYPE_PACKAGED_DRINK,
            'direct_stock_ingredient_id' => $ingredient->id,
            'recipe_ingredients' => [
                ['ingredient_id' => $recipeIngredient->id, 'quantity_required' => 1],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('recipe_ingredients');
    }

    public function test_admin_can_activate_predefined_drink_and_update_price_and_availability(): void
    {
        [$admin, $restaurant] = $this->adminContext();
        $ingredient = $this->createIngredient($restaurant, '7UP Can');
        Sanctum::actingAs($admin);

        $activate = $this->postJson('/api/admin/menu-item-templates/activate', [
            'template_key' => '7up',
            'price' => 1.75,
            'status' => 'published',
            'direct_stock_ingredient_id' => $ingredient->id,
        ]);

        $activate->assertCreated()
            ->assertJsonPath('dish.item_type', Dish::ITEM_TYPE_PACKAGED_DRINK);

        $dishId = (int) $activate->json('dish.id');

        $this->patchJson("/api/menu-items/{$dishId}", [
            'price' => 2.10,
            'status' => 'draft',
        ])->assertOk()
            ->assertJsonPath('price', '2.10')
            ->assertJsonPath('status', 'draft');
    }

    private function adminContext(): array
    {
        $admin = User::factory()->admin()->create();
        $restaurant = Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'name' => 'Menu Item Test '.Str::upper(Str::random(4)),
            'slug' => 'menu-item-test-'.Str::lower(Str::random(6)),
            'description' => 'menu item test',
            'address' => 'Beirut',
        ]);

        return [$admin, $restaurant];
    }

    private function createIngredient(Restaurant $restaurant, string $name): Ingredient
    {
        return Ingredient::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => $name,
            'stock_unit' => Ingredient::UNIT_PIECE,
            'current_stock_quantity' => '30.000',
            'low_stock_threshold' => '0.000',
            'is_active' => true,
        ]);
    }
}
