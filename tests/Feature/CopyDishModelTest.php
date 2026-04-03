<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\DishAsset;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CopyDishModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_admin_can_copy_model_assets_from_another_dish(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $restaurant = $this->createRestaurant($user);
        $targetDish = $this->createDish($restaurant, 'Target Dish');
        $sourceDish = $this->createDish($restaurant, 'Source Dish');

        Storage::disk('public')->put("dishes/{$sourceDish->id}/model.glb", 'glb-data');
        Storage::disk('public')->put("dishes/{$sourceDish->id}/model.usdz", 'usdz-data');

        DishAsset::query()->create([
            'uuid' => (string) Str::uuid(),
            'dish_id' => $sourceDish->id,
            'asset_type' => 'glb',
            'storage_disk' => 'public',
            'file_path' => "dishes/{$sourceDish->id}/model.glb",
            'glb_path' => "dishes/{$sourceDish->id}/model.glb",
            'file_url' => '/api/assets/source-glb/file',
            'file_size' => 8,
            'mime_type' => 'model/gltf-binary',
            'metadata' => ['file_name' => 'model.glb'],
        ]);

        DishAsset::query()->create([
            'uuid' => (string) Str::uuid(),
            'dish_id' => $sourceDish->id,
            'asset_type' => 'usdz',
            'storage_disk' => 'public',
            'file_path' => "dishes/{$sourceDish->id}/model.usdz",
            'usdz_path' => "dishes/{$sourceDish->id}/model.usdz",
            'file_url' => '/api/assets/source-usdz/file',
            'file_size' => 9,
            'mime_type' => 'model/vnd.usdz+zip',
            'metadata' => ['file_name' => 'model.usdz'],
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/dishes/{$targetDish->id}/copy-model", [
            'source_dish_id' => $sourceDish->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('id', $targetDish->id)
            ->assertJsonCount(2, 'assets');

        $targetAssets = DishAsset::query()
            ->where('dish_id', $targetDish->id)
            ->get()
            ->keyBy('asset_type');

        $this->assertTrue($targetAssets->has('glb'));
        $this->assertTrue($targetAssets->has('usdz'));
        $this->assertFalse($targetAssets->has('preview_image'));

        $this->assertSame('copied_from_existing_model', $targetAssets['glb']->metadata['source']);
        $this->assertSame($sourceDish->id, $targetAssets['glb']->metadata['source_dish_id']);
        $this->assertSame(
            "/api/assets/{$targetAssets['glb']->id}/file",
            $targetAssets['glb']->fresh()->file_url
        );
        $this->assertNotSame(
            "dishes/{$sourceDish->id}/model.glb",
            $targetAssets['glb']->file_path
        );

        Storage::disk('public')->assertExists($targetAssets['glb']->file_path);
        Storage::disk('public')->assertExists($targetAssets['usdz']->file_path);

        $response->assertJsonFragment([
            'file_url' => "/api/assets/{$targetAssets['glb']->id}/file",
        ]);
    }

    public function test_copy_model_requires_a_ready_source_dish(): void
    {
        $user = User::factory()->create();
        $restaurant = $this->createRestaurant($user);
        $targetDish = $this->createDish($restaurant, 'Target Dish');
        $sourceDish = $this->createDish($restaurant, 'Source Dish');

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/dishes/{$targetDish->id}/copy-model", [
            'source_dish_id' => $sourceDish->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'The selected source dish does not have a reusable 3D model yet.');
    }

    private function createRestaurant(User $user): Restaurant
    {
        return Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'name' => 'Copy Model Restaurant',
            'slug' => 'copy-model-restaurant-'.Str::lower(Str::random(6)),
        ]);
    }

    private function createDish(Restaurant $restaurant, string $name): Dish
    {
        return Dish::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => $name,
            'description' => null,
            'price' => 15.00,
            'category' => 'Main',
            'status' => 'draft',
        ]);
    }
}
