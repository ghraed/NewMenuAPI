<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IngredientLibraryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_admin_can_bulk_upload_ingredient_library_images(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $restaurant = $this->createRestaurant($user);

        Sanctum::actingAs($user);

        $response = $this->post('/api/ingredients/bulk-upload', [
            'images' => [
                UploadedFile::fake()->image('extra-virgin-olive-oil.png', 600, 320),
                UploadedFile::fake()->image('fresh-mint-leaves.jpg', 600, 320),
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('uploaded_count', 2);

        $this->assertDatabaseHas('ingredients', [
            'restaurant_id' => $restaurant->id,
            'name' => 'extra virgin olive oil',
        ]);

        $this->assertDatabaseHas('ingredients', [
            'restaurant_id' => $restaurant->id,
            'name' => 'fresh mint leaves',
        ]);

        $this->assertCount(2, Ingredient::query()->where('restaurant_id', $restaurant->id)->get());
    }

    public function test_authenticated_admin_can_delete_all_ingredient_library_images(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $restaurant = $this->createRestaurant($user);

        $first = $restaurant->ingredients()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'fresh mint leaves',
            'storage_disk' => 'public',
            'file_path' => "ingredients/{$restaurant->id}/mint.png",
            'source_file_name' => 'fresh-mint-leaves.png',
            'file_size' => 1200,
            'mime_type' => 'image/png',
        ]);

        $second = $restaurant->ingredients()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'lemon juice',
            'storage_disk' => 'public',
            'file_path' => "ingredients/{$restaurant->id}/lemon.png",
            'source_file_name' => 'lemon-juice.png',
            'file_size' => 1300,
            'mime_type' => 'image/png',
        ]);

        Storage::disk('public')->put($first->file_path, 'mint');
        Storage::disk('public')->put($second->file_path, 'lemon');

        Sanctum::actingAs($user);

        $response = $this->delete('/api/ingredients');

        $response->assertOk()
            ->assertJsonPath('deleted_count', 2);

        $this->assertDatabaseCount('ingredients', 0);
        Storage::disk('public')->assertMissing($first->file_path);
        Storage::disk('public')->assertMissing($second->file_path);
    }

    private function createRestaurant(User $user): Restaurant
    {
        return Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'name' => 'Ingredient Library Restaurant',
            'slug' => 'ingredient-library-restaurant-'.Str::lower(Str::random(6)),
        ]);
    }
}
