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

class DishManagementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_update_and_delete_multilingual_dishes_with_relationships(): void
    {
        [$admin, $restaurant] = $this->adminContext(['Main Courses', 'Desserts']);
        $ingredient = $this->createIngredient($restaurant, 'Shawarma Meat');
        $suggestedDish = Dish::factory()->for($restaurant)->published()->create([
            'name' => 'Fresh Lemonade',
            'category' => 'Desserts',
        ]);
        $relatedDish = Dish::factory()->for($restaurant)->published()->create([
            'name' => 'Grilled Halloumi',
            'category' => 'Main Courses',
        ]);

        Sanctum::actingAs($admin);

        $createResponse = $this->postJson('/api/menu-items', [
            'name' => 'Mixed Grill 🔥',
            'name_ar' => 'مشاوي مشكلة',
            'description' => 'Large sharing platter',
            'description_ar' => 'طبق كبير للمشاركة',
            'price' => 0,
            'currency' => 'USD',
            'category' => 'Main Courses',
            'category_ar' => 'الأطباق الرئيسية',
            'status' => 'published',
            'is_anchor' => true,
            'is_profitable' => false,
            'suggested_dish_ids' => [$suggestedDish->id],
            'related_dish_ids' => [$relatedDish->id],
            'recipe_ingredients' => [
                [
                    'ingredient_id' => $ingredient->id,
                    'quantity_required' => 2.5,
                    'order_index' => 0,
                ],
            ],
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('name', 'Mixed Grill 🔥')
            ->assertJsonPath('name_ar', 'مشاوي مشكلة')
            ->assertJsonPath('description_ar', 'طبق كبير للمشاركة')
            ->assertJsonPath('price', '0.00')
            ->assertJsonPath('is_anchor', true)
            ->assertJsonPath('is_profitable', false)
            ->assertJsonPath('suggested_dishes.0.id', $suggestedDish->id)
            ->assertJsonPath('related_dishes.0.id', $relatedDish->id)
            ->assertJsonPath('dish_ingredients.0.ingredient_id', $ingredient->id);

        $dishId = (int) $createResponse->json('id');

        $updateResponse = $this->patchJson("/api/menu-items/{$dishId}", [
            'price' => 99999999.99,
            'description' => 'Updated platter for large groups 😋',
            'description_ar' => 'طبق محدث للمجموعات الكبيرة 😋',
            'status' => 'draft',
            'is_profitable' => true,
        ]);

        $updateResponse->assertOk()
            ->assertJsonPath('price', '99999999.99')
            ->assertJsonPath('description_ar', 'طبق محدث للمجموعات الكبيرة 😋')
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('is_profitable', true);

        $deleteResponse = $this->deleteJson("/api/menu-items/{$dishId}");
        $deleteResponse->assertOk();

        $this->assertSoftDeleted('dishes', ['id' => $dishId]);

        $this->postJson("/api/dishes/{$dishId}/restore")
            ->assertOk()
            ->assertJsonPath('dish.id', $dishId);

        $this->assertDatabaseHas('dishes', [
            'id' => $dishId,
            'deleted_at' => null,
        ]);
    }

    public function test_dish_management_rejects_negative_prices_disabled_categories_and_cross_tenant_relations(): void
    {
        [$admin, $restaurant] = $this->adminContext(['Main Courses']);
        $localIngredient = $this->createIngredient($restaurant, 'Rice');

        $otherRestaurant = Restaurant::factory()->create([
            'profile' => ['menu_categories' => ['Main Courses']],
        ]);
        $foreignIngredient = $this->createIngredient($otherRestaurant, 'Foreign Spice');
        $foreignDish = Dish::factory()->for($otherRestaurant)->published()->create([
            'category' => 'Main Courses',
        ]);

        Sanctum::actingAs($admin);

        $negativePrice = $this->postJson('/api/menu-items', [
            'name' => 'Invalid Dish',
            'price' => -1,
            'category' => 'Main Courses',
        ]);

        $negativePrice->assertStatus(422)
            ->assertJsonValidationErrors(['price']);

        $invalidCategory = $this->postJson('/api/menu-items', [
            'name' => 'Wrong Category',
            'price' => 12,
            'category' => 'Desserts',
        ]);

        $invalidCategory->assertStatus(422)
            ->assertJsonValidationErrors(['category']);

        $foreignIngredientPayload = $this->postJson('/api/menu-items', [
            'name' => 'Cross Tenant Ingredient',
            'price' => 12,
            'category' => 'Main Courses',
            'recipe_ingredients' => [
                ['ingredient_id' => $localIngredient->id, 'quantity_required' => 1],
                ['ingredient_id' => $foreignIngredient->id, 'quantity_required' => 1],
            ],
        ]);

        $foreignIngredientPayload->assertStatus(422)
            ->assertJsonValidationErrors(['recipe_ingredients']);

        $foreignRelationPayload = $this->postJson('/api/menu-items', [
            'name' => 'Cross Tenant Relation',
            'price' => 8.5,
            'category' => 'Main Courses',
            'suggested_dish_ids' => [$foreignDish->id],
        ]);

        $foreignRelationPayload->assertStatus(422)
            ->assertJsonValidationErrors(['suggested_dish_ids']);
    }

    /**
     * @return array{0: User, 1: Restaurant}
     */
    private function adminContext(array $menuCategories): array
    {
        $admin = User::factory()->admin()->create();
        $restaurant = Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'name' => 'Dish Test '.Str::upper(Str::random(4)),
            'slug' => 'dish-test-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'description' => 'dish test',
            'address' => 'Beirut',
            'currency' => 'USD',
            'other_currency' => 'LBP',
            'dollar_rate' => '1.00',
            'profile' => ['menu_categories' => $menuCategories],
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
