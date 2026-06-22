<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\DishAsset;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PreviewImageAssetUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_admin_can_upload_and_replace_a_preview_image(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $restaurant = $this->createRestaurant($user);
        $dish = $this->createDish($restaurant, 'Preview Dish');

        Storage::disk('public')->put("dishes/{$dish->id}/existing-preview.jpg", 'old-preview');

        $existingAsset = DishAsset::query()->create([
            'uuid' => (string) Str::uuid(),
            'dish_id' => $dish->id,
            'asset_type' => 'preview_image',
            'storage_disk' => 'public',
            'file_path' => "dishes/{$dish->id}/existing-preview.jpg",
            'file_url' => '/api/assets/existing-preview/file',
            'file_size' => 11,
            'mime_type' => 'image/jpeg',
            'metadata' => ['file_name' => 'existing-preview.jpg'],
        ]);

        Sanctum::actingAs($user);

        $response = $this->post('/api/dishes/'.$dish->id.'/assets', [
            'type' => 'preview_image',
            'file' => UploadedFile::fake()->image('fresh-preview.jpg', 900, 900),
        ]);

        $response->assertCreated()
            ->assertJsonPath('asset_type', 'preview_image');

        $this->assertDatabaseMissing('dish_assets', [
            'id' => $existingAsset->id,
        ]);

        Storage::disk('public')->assertMissing("dishes/{$dish->id}/existing-preview.jpg");

        $newAsset = DishAsset::query()
            ->where('dish_id', $dish->id)
            ->where('asset_type', 'preview_image')
            ->first();

        $this->assertNotNull($newAsset);
        $this->assertSame("/api/assets/{$newAsset->id}/file", $newAsset->file_url);
        $this->assertStringStartsWith("dishes/{$dish->id}/", $newAsset->file_path ?? '');
        Storage::disk('public')->assertExists($newAsset->file_path);
    }

    public function test_authenticated_restaurant_member_admin_can_upload_a_preview_image(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create();
        $memberAdmin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
        ]);

        $restaurant = $this->createRestaurant($owner);
        $restaurant->staffUsers()->attach($memberAdmin->id);

        $dish = $this->createDish($restaurant, 'Member Admin Preview Dish');

        Sanctum::actingAs($memberAdmin);

        $response = $this->post('/api/dishes/'.$dish->id.'/assets', [
            'type' => 'preview_image',
            'file' => UploadedFile::fake()->image('member-admin-preview.jpg', 900, 900),
        ]);

        $response->assertCreated()
            ->assertJsonPath('asset_type', 'preview_image');

        $newAsset = DishAsset::query()
            ->where('dish_id', $dish->id)
            ->where('asset_type', 'preview_image')
            ->first();

        $this->assertNotNull($newAsset);
        Storage::disk('public')->assertExists($newAsset->file_path);
    }

    private function createRestaurant(User $user): Restaurant
    {
        return Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'name' => 'Preview Asset Restaurant',
            'slug' => 'preview-asset-restaurant-'.Str::lower(Str::random(6)),
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
