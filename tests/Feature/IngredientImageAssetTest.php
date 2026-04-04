<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\DishAsset;
use App\Models\Ingredient;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IngredientImageAssetTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_admin_can_upload_multiple_ingredient_images_without_replacing_previous_layers(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $restaurant = $this->createRestaurant($user);
        $dish = $this->createDish($restaurant, 'Animated Shawarma');

        Sanctum::actingAs($user);

        $firstResponse = $this->post('/api/dishes/'.$dish->id.'/assets', [
            'type' => 'ingredient_image',
            'label' => 'Grilled Chicken',
            'quantity' => '120g',
            'order_index' => 0,
            'file' => UploadedFile::fake()->image('chicken.png', 600, 320),
        ]);

        $secondResponse = $this->post('/api/dishes/'.$dish->id.'/assets', [
            'type' => 'ingredient_image',
            'label' => 'Garlic Sauce',
            'quantity' => '2 tbsp',
            'order_index' => 1,
            'file' => UploadedFile::fake()->image('garlic.png', 600, 320),
        ]);

        $firstResponse->assertCreated()
            ->assertJsonPath('asset_type', 'ingredient_image')
            ->assertJsonPath('metadata.label', 'Grilled Chicken')
            ->assertJsonPath('metadata.quantity', '120g')
            ->assertJsonPath('metadata.order_index', 0);

        $secondResponse->assertCreated()
            ->assertJsonPath('asset_type', 'ingredient_image')
            ->assertJsonPath('metadata.label', 'Garlic Sauce')
            ->assertJsonPath('metadata.quantity', '2 tbsp')
            ->assertJsonPath('metadata.order_index', 1);

        $ingredientAssets = DishAsset::query()
            ->where('dish_id', $dish->id)
            ->where('asset_type', 'ingredient_image')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $ingredientAssets);
        $this->assertSame('Grilled Chicken', $ingredientAssets[0]->metadata['label'] ?? null);
        $this->assertSame('Garlic Sauce', $ingredientAssets[1]->metadata['label'] ?? null);

        foreach ($ingredientAssets as $asset) {
            $this->assertStringStartsWith("/api/assets/{$asset->id}/file", $asset->file_url);
            Storage::disk('public')->assertExists($asset->file_path);
        }
    }

    public function test_authenticated_admin_can_update_existing_ingredient_image_labels(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $restaurant = $this->createRestaurant($user);
        $dish = $this->createDish($restaurant, 'Animated Bowl');

        Storage::disk('public')->put("dishes/{$dish->id}/ingredients/lettuce.png", 'ingredient');

        $asset = DishAsset::query()->create([
            'uuid' => (string) Str::uuid(),
            'dish_id' => $dish->id,
            'asset_type' => 'ingredient_image',
            'storage_disk' => 'public',
            'file_path' => "dishes/{$dish->id}/ingredients/lettuce.png",
            'file_url' => '/api/assets/placeholder/file',
            'file_size' => 10,
            'mime_type' => 'image/png',
            'metadata' => [
                'file_name' => 'lettuce.png',
                'label' => 'Lettuce',
                'quantity' => '1 leaf',
                'order_index' => 2,
            ],
        ]);

        Sanctum::actingAs($user);

        $response = $this->patch('/api/assets/'.$asset->id, [
            'label' => 'Crisp Lettuce',
            'quantity' => '2 leaves',
            'order_index' => 1,
        ]);

        $response->assertOk()
            ->assertJsonPath('metadata.label', 'Crisp Lettuce')
            ->assertJsonPath('metadata.quantity', '2 leaves')
            ->assertJsonPath('metadata.order_index', 1);

        $this->assertDatabaseHas('dish_assets', [
            'id' => $asset->id,
            'asset_type' => 'ingredient_image',
        ]);

        $asset->refresh();
        $this->assertSame('Crisp Lettuce', $asset->metadata['label'] ?? null);
        $this->assertSame('2 leaves', $asset->metadata['quantity'] ?? null);
        $this->assertSame(1, $asset->metadata['order_index'] ?? null);
    }

    public function test_authenticated_admin_can_create_dish_ingredient_from_saved_library_image(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $restaurant = $this->createRestaurant($user);
        $dish = $this->createDish($restaurant, 'Ingredient Library Bowl');

        $ingredient = Ingredient::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => 'fresh mint leaves',
            'storage_disk' => 'public',
            'file_path' => "ingredients/{$restaurant->id}/fresh-mint-leaves.png",
            'source_file_name' => 'fresh-mint-leaves.png',
            'file_size' => 2048,
            'mime_type' => 'image/png',
        ]);

        Storage::disk('public')->put($ingredient->file_path, 'mint-library-image');

        Sanctum::actingAs($user);

        $response = $this->post('/api/dishes/'.$dish->id.'/assets', [
            'type' => 'ingredient_image',
            'ingredient_library_id' => $ingredient->id,
            'quantity' => '80 g',
            'order_index' => 4,
        ]);

        $response->assertCreated()
            ->assertJsonPath('asset_type', 'ingredient_image')
            ->assertJsonPath('metadata.label', 'fresh mint leaves')
            ->assertJsonPath('metadata.quantity', '80 g')
            ->assertJsonPath('metadata.order_index', 4)
            ->assertJsonPath('metadata.ingredient_library_id', $ingredient->id);

        $asset = DishAsset::query()
            ->where('dish_id', $dish->id)
            ->where('asset_type', 'ingredient_image')
            ->latest('id')
            ->firstOrFail();

        $this->assertNotSame($ingredient->file_path, $asset->file_path);
        Storage::disk('public')->assertExists($asset->file_path);
        $this->assertSame('fresh mint leaves', $asset->metadata['label'] ?? null);
        $this->assertSame($ingredient->id, $asset->metadata['ingredient_library_id'] ?? null);
    }

    private function createRestaurant(User $user): Restaurant
    {
        return Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'name' => 'Ingredient Asset Restaurant',
            'slug' => 'ingredient-asset-restaurant-'.Str::lower(Str::random(6)),
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
