<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\DishAsset;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssetFileControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_remote_assets_are_streamed_from_the_api_origin(): void
    {
        Storage::fake('b2');

        $user = User::factory()->create();
        $restaurant = Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'name' => 'Asset Stream Restaurant',
            'slug' => 'asset-stream-restaurant-'.Str::lower(Str::random(6)),
        ]);

        $dish = Dish::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => 'Streamed Dish',
            'description' => null,
            'price' => 12.50,
            'category' => 'Main',
            'status' => 'published',
        ]);

        Storage::disk('b2')->put('dishes/1/model.glb', 'glb-data');

        $asset = DishAsset::query()->create([
            'uuid' => (string) Str::uuid(),
            'dish_id' => $dish->id,
            'asset_type' => 'glb',
            'storage_disk' => 'b2',
            'file_path' => 'dishes/1/model.glb',
            'glb_path' => 'dishes/1/model.glb',
            'file_url' => route('api.assets.show', ['asset' => 1]),
            'file_size' => 8,
            'mime_type' => 'model/gltf-binary',
            'metadata' => ['file_name' => 'model.glb'],
        ]);

        $response = $this->get("/api/assets/{$asset->id}/file");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'model/gltf-binary');
        $this->assertSame('glb-data', $response->streamedContent());
    }
}
