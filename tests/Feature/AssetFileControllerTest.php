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

        $this->assertStringStartsWith("/api/assets/{$asset->id}/file?", $asset->file_url);

        $response = $this->get($asset->file_url);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'model/gltf-binary');
        $this->assertSame('glb-data', $response->streamedContent());
    }

    public function test_unsigned_public_asset_urls_are_not_directly_readable_by_id(): void
    {
        Storage::fake('b2');

        $asset = $this->createRemoteAssetForRestaurant('public-idor');

        Storage::disk('b2')->put('dishes/1/model.glb', 'glb-data');

        $this->get("/api/assets/{$asset->id}/file")
            ->assertNotFound();
    }

    public function test_authenticated_same_tenant_user_can_read_unsigned_asset_url(): void
    {
        Storage::fake('b2');

        $owner = User::factory()->create();
        $restaurant = $this->createRestaurant($owner, 'same-tenant');
        $asset = $this->createRemoteAssetForRestaurant('same-tenant', $restaurant);

        Storage::disk('b2')->put('dishes/1/model.glb', 'glb-data');

        Sanctum::actingAs($owner);

        $response = $this->get("/api/assets/{$asset->id}/file");

        $response->assertOk();
        $this->assertSame('glb-data', $response->streamedContent());
    }

    public function test_authenticated_other_tenant_user_cannot_read_unsigned_asset_url(): void
    {
        Storage::fake('b2');

        $assetOwner = User::factory()->create();
        $assetRestaurant = $this->createRestaurant($assetOwner, 'asset-owner');
        $asset = $this->createRemoteAssetForRestaurant('cross-tenant', $assetRestaurant);

        $otherOwner = User::factory()->create();
        $this->createRestaurant($otherOwner, 'other-tenant');

        Storage::disk('b2')->put('dishes/1/model.glb', 'glb-data');

        Sanctum::actingAs($otherOwner);

        $this->get("/api/assets/{$asset->id}/file")
            ->assertNotFound();
    }

    private function createRemoteAssetForRestaurant(string $prefix, ?Restaurant $restaurant = null): DishAsset
    {
        $restaurant ??= $this->createRestaurant(User::factory()->create(), $prefix);

        $dish = Dish::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => 'Streamed Dish',
            'description' => null,
            'price' => 12.50,
            'category' => 'Main',
            'status' => 'published',
        ]);

        return DishAsset::query()->create([
            'uuid' => (string) Str::uuid(),
            'dish_id' => $dish->id,
            'asset_type' => 'glb',
            'storage_disk' => 'b2',
            'file_path' => 'dishes/1/model.glb',
            'glb_path' => 'dishes/1/model.glb',
            'file_url' => '',
            'file_size' => 8,
            'mime_type' => 'model/gltf-binary',
            'metadata' => ['file_name' => 'model.glb'],
        ]);
    }

    private function createRestaurant(User $user, string $prefix): Restaurant
    {
        return Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'name' => 'Asset Stream Restaurant '.Str::upper(Str::random(4)),
            'slug' => "{$prefix}-".Str::lower(Str::random(6)),
        ]);
    }
}
