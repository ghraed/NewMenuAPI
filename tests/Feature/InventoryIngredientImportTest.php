<?php

namespace Tests\Feature;

use App\Models\GlobalIngredient;
use App\Models\Ingredient;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryIngredientImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_import_multiple_global_ingredients(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $restaurant = $this->createRestaurant($admin, 'inventory-import-alpha');

        $mint = $this->createGlobalIngredient('fresh mint leaves');
        $oliveOil = $this->createGlobalIngredient('extra virgin olive oil');

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/inventory/ingredients/import-global', [
            'global_ingredient_ids' => [$mint->id, $oliveOil->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('created_count', 2)
            ->assertJsonPath('linked_count', 0)
            ->assertJsonPath('skipped_count', 0);

        $this->assertDatabaseHas('ingredients', [
            'restaurant_id' => $restaurant->id,
            'global_ingredient_id' => $mint->id,
            'name' => $mint->name,
            'stock_unit' => Ingredient::UNIT_PIECE,
            'current_stock_quantity' => '0.000',
            'low_stock_threshold' => '0.000',
            'is_active' => 1,
        ]);

        $this->assertDatabaseHas('ingredients', [
            'restaurant_id' => $restaurant->id,
            'global_ingredient_id' => $oliveOil->id,
            'name' => $oliveOil->name,
            'stock_unit' => Ingredient::UNIT_PIECE,
            'current_stock_quantity' => '0.000',
            'low_stock_threshold' => '0.000',
            'is_active' => 1,
        ]);
    }

    public function test_import_skips_already_linked_global_ingredients(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $restaurant = $this->createRestaurant($admin, 'inventory-import-skip');
        $salt = $this->createGlobalIngredient('sea salt');

        Ingredient::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'global_ingredient_id' => $salt->id,
            'name' => $salt->name,
            'name_ar' => $salt->name_ar,
            'stock_unit' => Ingredient::UNIT_PIECE,
            'current_stock_quantity' => 0,
            'low_stock_threshold' => 0,
            'is_active' => true,
            'storage_disk' => 'public',
            'file_path' => null,
            'source_file_name' => null,
            'file_size' => null,
            'mime_type' => null,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/inventory/ingredients/import-global', [
            'global_ingredient_ids' => [$salt->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('created_count', 0)
            ->assertJsonPath('linked_count', 0)
            ->assertJsonPath('skipped_count', 1)
            ->assertJsonPath('skipped_global_ingredient_ids.0', $salt->id);
    }

    public function test_import_links_existing_name_match_when_global_link_is_missing(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $restaurant = $this->createRestaurant($admin, 'inventory-import-link');
        $mint = $this->createGlobalIngredient('fresh mint leaves');

        $existing = Ingredient::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'global_ingredient_id' => null,
            'name' => 'Fresh Mint Leaves',
            'name_ar' => null,
            'stock_unit' => Ingredient::UNIT_GRAM,
            'current_stock_quantity' => 3.500,
            'low_stock_threshold' => 1.000,
            'is_active' => true,
            'storage_disk' => 'public',
            'file_path' => null,
            'source_file_name' => null,
            'file_size' => null,
            'mime_type' => null,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/inventory/ingredients/import-global', [
            'global_ingredient_ids' => [$mint->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('created_count', 0)
            ->assertJsonPath('linked_count', 1)
            ->assertJsonPath('linked_ids.0', $existing->id)
            ->assertJsonPath('skipped_count', 0);

        $this->assertDatabaseHas('ingredients', [
            'id' => $existing->id,
            'global_ingredient_id' => $mint->id,
            'name' => 'Fresh Mint Leaves',
            'stock_unit' => Ingredient::UNIT_GRAM,
            'current_stock_quantity' => '3.500',
        ]);

        $this->assertSame(1, Ingredient::query()->where('restaurant_id', $restaurant->id)->count());
    }

    public function test_staff_cannot_import_global_ingredients(): void
    {
        $staff = User::factory()->create(['role' => User::ROLE_STAFF]);
        Sanctum::actingAs($staff);

        $response = $this->postJson('/api/inventory/ingredients/import-global', [
            'global_ingredient_ids' => [1],
        ]);

        $response->assertForbidden();
    }

    public function test_import_only_affects_current_restaurant(): void
    {
        $adminOne = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $restaurantOne = $this->createRestaurant($adminOne, 'inventory-import-r1');
        $global = $this->createGlobalIngredient('black pepper');

        $adminTwo = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $restaurantTwo = $this->createRestaurant($adminTwo, 'inventory-import-r2');

        Ingredient::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurantTwo->id,
            'global_ingredient_id' => null,
            'name' => 'black pepper',
            'name_ar' => null,
            'stock_unit' => Ingredient::UNIT_PIECE,
            'current_stock_quantity' => 7.000,
            'low_stock_threshold' => 1.000,
            'is_active' => true,
            'storage_disk' => 'public',
            'file_path' => null,
            'source_file_name' => null,
            'file_size' => null,
            'mime_type' => null,
        ]);

        Sanctum::actingAs($adminOne);

        $response = $this->postJson('/api/inventory/ingredients/import-global', [
            'global_ingredient_ids' => [$global->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('created_count', 1)
            ->assertJsonPath('linked_count', 0);

        $this->assertDatabaseHas('ingredients', [
            'restaurant_id' => $restaurantOne->id,
            'global_ingredient_id' => $global->id,
            'name' => $global->name,
        ]);

        $this->assertDatabaseHas('ingredients', [
            'restaurant_id' => $restaurantTwo->id,
            'global_ingredient_id' => null,
            'name' => 'black pepper',
            'current_stock_quantity' => '7.000',
        ]);
    }

    private function createGlobalIngredient(string $name): GlobalIngredient
    {
        return GlobalIngredient::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'name_ar' => null,
            'normalized_name' => $this->normalizeIngredientName($name),
        ]);
    }

    private function createRestaurant(User $owner, string $slug): Restaurant
    {
        return Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'name' => Str::headline($slug),
            'slug' => $slug,
            'description' => 'Inventory import test restaurant',
            'address' => 'Beirut',
        ]);
    }

    private function normalizeIngredientName(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = str_replace('&', 'and', $normalized);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    }
}
