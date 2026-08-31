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

class MigrateDishAssetsToProtectedStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_dry_run_preserves_legacy_public_asset(): void
    {
        [$asset, $path] = $this->createLegacyPublicAsset();

        $this->artisan('dish-assets:migrate-to-protected', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame('public', $asset->fresh()->storage_disk);
        Storage::disk('public')->assertExists($path);
        Storage::disk(DishAsset::PROTECTED_DISK)->assertMissing($path);
    }

    public function test_command_moves_legacy_public_asset_and_updates_its_record(): void
    {
        [$asset, $path] = $this->createLegacyPublicAsset();

        $this->artisan('dish-assets:migrate-to-protected')->assertSuccessful();

        $this->assertSame(DishAsset::PROTECTED_DISK, $asset->fresh()->storage_disk);
        Storage::disk('public')->assertMissing($path);
        Storage::disk(DishAsset::PROTECTED_DISK)->assertExists($path);
        $this->assertSame('QA_RUN_PROTECTED_ASSET', Storage::disk(DishAsset::PROTECTED_DISK)->get($path));
    }

    /**
     * @return array{DishAsset, string}
     */
    private function createLegacyPublicAsset(): array
    {
        Storage::fake('public');
        Storage::fake(DishAsset::PROTECTED_DISK);

        $owner = User::factory()->admin()->create();
        $restaurant = Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'name' => 'QA_RUN_ASSET_MIGRATION',
            'slug' => 'qa-run-asset-migration-'.Str::lower(Str::random(8)),
        ]);
        $dish = Dish::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => 'QA_RUN_DISH',
            'price' => 12,
            'category' => 'Main',
            'status' => 'draft',
        ]);
        $path = "dishes/{$dish->id}/preview.jpg";
        Storage::disk('public')->put($path, 'QA_RUN_PROTECTED_ASSET');

        $asset = DishAsset::query()->create([
            'uuid' => (string) Str::uuid(),
            'dish_id' => $dish->id,
            'asset_type' => DishAsset::TYPE_PREVIEW_IMAGE,
            'storage_disk' => 'public',
            'file_path' => $path,
            'file_url' => '',
            'file_size' => 22,
            'mime_type' => 'image/jpeg',
        ]);

        return [$asset, $path];
    }
}
