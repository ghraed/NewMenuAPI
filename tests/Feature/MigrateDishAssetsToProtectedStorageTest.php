<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\DishAsset;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
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

    public function test_command_fails_and_keeps_record_retryable_when_public_delete_fails(): void
    {
        [$asset, $path] = $this->createLegacyPublicAsset();
        $public = Storage::disk('public');
        $deleteFailingPublic = Mockery::mock($public)->makePartial();
        $deleteFailingPublic->shouldReceive('delete')->once()->with($path)->andReturn(false);
        Storage::getFacadeRoot()->set('public', $deleteFailingPublic);

        $this->artisan('dish-assets:migrate-to-protected')->assertFailed();

        $this->assertSame('public', $asset->fresh()->storage_disk);
        $this->assertTrue($public->exists($path));
        Storage::disk(DishAsset::PROTECTED_DISK)->assertExists($path);
        $this->assertSame('QA_RUN_PROTECTED_ASSET', Storage::disk(DishAsset::PROTECTED_DISK)->get($path));
    }

    public function test_command_replaces_a_mismatched_existing_destination_before_switching_the_record(): void
    {
        [$asset, $path] = $this->createLegacyPublicAsset();
        Storage::disk(DishAsset::PROTECTED_DISK)->put($path, 'QA_RUN_TRUNCATED');

        $this->artisan('dish-assets:migrate-to-protected')->assertSuccessful();

        $this->assertSame(DishAsset::PROTECTED_DISK, $asset->fresh()->storage_disk);
        Storage::disk('public')->assertMissing($path);
        $this->assertSame('QA_RUN_PROTECTED_ASSET', Storage::disk(DishAsset::PROTECTED_DISK)->get($path));
    }

    public function test_command_does_not_trust_an_unverifiable_destination_when_the_public_source_is_missing(): void
    {
        [$asset, $path] = $this->createLegacyPublicAsset();
        Storage::disk(DishAsset::PROTECTED_DISK)->put($path, 'QA_RUN_UNVERIFIABLE');
        Storage::disk('public')->delete($path);

        $this->artisan('dish-assets:migrate-to-protected')->assertFailed();

        $this->assertSame('public', $asset->fresh()->storage_disk);
        $this->assertSame('QA_RUN_UNVERIFIABLE', Storage::disk(DishAsset::PROTECTED_DISK)->get($path));
    }

    public function test_command_safely_retries_after_a_delete_failure_using_the_verified_destination(): void
    {
        [$asset, $path] = $this->createLegacyPublicAsset();
        $public = Storage::disk('public');
        $deleteFailingPublic = Mockery::mock($public)->makePartial();
        $deleteFailingPublic->shouldReceive('delete')->once()->with($path)->andReturn(false);
        Storage::getFacadeRoot()->set('public', $deleteFailingPublic);

        $this->artisan('dish-assets:migrate-to-protected')->assertFailed();
        Storage::getFacadeRoot()->set('public', $public);
        $this->artisan('dish-assets:migrate-to-protected')->assertSuccessful();

        $this->assertSame(DishAsset::PROTECTED_DISK, $asset->fresh()->storage_disk);
        $this->assertFalse($public->exists($path));
        $this->assertSame('QA_RUN_PROTECTED_ASSET', Storage::disk(DishAsset::PROTECTED_DISK)->get($path));
    }

    public function test_shared_file_is_retained_until_every_asset_reference_is_protected(): void
    {
        [$first, $path] = $this->createLegacyPublicAsset();
        $second = $first->replicate();
        $second->uuid = (string) Str::uuid();
        $second->file_path = '/'.$path;
        $second->saveOrFail();

        $this->artisan('dish-assets:migrate-to-protected')->assertSuccessful();

        $this->assertSame(DishAsset::PROTECTED_DISK, $first->fresh()->storage_disk);
        $this->assertSame(DishAsset::PROTECTED_DISK, $second->fresh()->storage_disk);
        $this->assertSame(2, DishAsset::query()->count());
        Storage::disk('public')->assertMissing($path);
        $this->assertSame('QA_RUN_PROTECTED_ASSET', Storage::disk(DishAsset::PROTECTED_DISK)->get($path));
    }

    public function test_shared_file_delete_failure_preserves_both_references_and_retries_safely(): void
    {
        [$first, $path] = $this->createLegacyPublicAsset();
        $second = $first->replicate();
        $second->uuid = (string) Str::uuid();
        $second->saveOrFail();
        $public = Storage::disk('public');
        $failing = Mockery::mock($public)->makePartial();
        $failing->shouldReceive('delete')->once()->with($path)->andReturn(false);
        Storage::getFacadeRoot()->set('public', $failing);

        $this->artisan('dish-assets:migrate-to-protected')->assertFailed();
        $this->assertSame(DishAsset::PROTECTED_DISK, $first->fresh()->storage_disk);
        $this->assertSame('public', $second->fresh()->storage_disk);
        $this->assertSame('QA_RUN_PROTECTED_ASSET', $public->get($path));
        $this->assertSame('QA_RUN_PROTECTED_ASSET', Storage::disk(DishAsset::PROTECTED_DISK)->get($path));

        Storage::getFacadeRoot()->set('public', $public);
        $this->artisan('dish-assets:migrate-to-protected')->assertSuccessful();
        $this->assertSame(DishAsset::PROTECTED_DISK, $first->fresh()->storage_disk);
        $this->assertSame(DishAsset::PROTECTED_DISK, $second->fresh()->storage_disk);
        $this->assertSame(2, DishAsset::query()->count());
        $this->assertFalse($public->exists($path));
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
