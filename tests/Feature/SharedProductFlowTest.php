<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\DishAsset;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SharedProductFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_menu_only_returns_published_dishes_with_a_glb_model(): void
    {
        $restaurant = $this->createRestaurant();

        $readyDish = Dish::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => 'Ready Dish',
            'description' => 'Visible to guests',
            'price' => 18.50,
            'category' => 'Main',
            'status' => 'published',
        ]);

        DishAsset::query()->create([
            'uuid' => (string) Str::uuid(),
            'dish_id' => $readyDish->id,
            'asset_type' => 'glb',
            'storage_disk' => 'public',
            'file_path' => 'dishes/ready/model.glb',
            'glb_path' => 'dishes/ready/model.glb',
            'file_url' => '/api/assets/1/file',
            'file_size' => 1024,
            'mime_type' => 'model/gltf-binary',
            'metadata' => ['uploaded_at' => now()->toIso8601String()],
        ]);

        Dish::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => 'Published But Processing',
            'description' => 'Should stay hidden',
            'price' => 16.00,
            'category' => 'Main',
            'status' => 'published',
        ]);

        Dish::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => 'Draft Dish',
            'description' => 'Admin only',
            'price' => 14.00,
            'category' => 'Main',
            'status' => 'draft',
        ]);

        $response = $this->getJson("/api/menu/{$restaurant->slug}/dishes");

        $response->assertOk()
            ->assertJsonCount(1, 'dishes')
            ->assertJsonPath('dishes.0.id', $readyDish->id)
            ->assertJsonMissing(['name' => 'Published But Processing'])
            ->assertJsonMissing(['name' => 'Draft Dish']);
    }

    public function test_authenticated_admin_can_create_a_dish_without_uploading_model_files(): void
    {
        $user = User::factory()->create();
        $restaurant = $this->createRestaurant($user);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/dishes', [
            'name' => 'Mobile Created Dish',
            'description' => 'Created before scanning finishes',
            'price' => 11.75,
            'category' => 'Special',
            'status' => 'draft',
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Mobile Created Dish')
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('model_state', 'none')
            ->assertJsonPath('is_model_ready', false)
            ->assertJsonCount(0, 'assets');

        $this->assertDatabaseHas('dishes', [
            'restaurant_id' => $restaurant->id,
            'name' => 'Mobile Created Dish',
            'status' => 'draft',
        ]);
    }

    private function createRestaurant(?User $user = null): Restaurant
    {
        $owner = $user ?? User::factory()->create();

        return Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'name' => 'Shared Product Restaurant',
            'slug' => 'shared-product-restaurant-'.Str::lower(Str::random(6)),
        ]);
    }
}
