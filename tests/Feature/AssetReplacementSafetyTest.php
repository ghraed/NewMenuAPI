<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\DishAsset;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AssetReplacementSafetyTest extends TestCase
{
    use RefreshDatabase;

    private FilesystemAdapter $disk;

    private Dish $target;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disk = Storage::fake(DishAsset::PROTECTED_DISK);
        $prefix = 'QA_RUN_20261006_'.bin2hex(random_bytes(4));
        $user = User::factory()->admin()->create(['name' => $prefix, 'email' => $prefix.'@example.invalid']);
        $this->restaurant = Restaurant::factory()->for($user)->create(['name' => $prefix, 'slug' => strtolower($prefix)]);
        $this->target = $this->dish('target');
        Sanctum::actingAs($user);
    }

    private function dish(string $label): Dish
    {
        return Dish::factory()->for($this->restaurant)->create(['name' => 'QA_RUN_20261006_'.$label]);
    }

    private function asset(Dish $dish, string $type, bool $writeFile = true): DishAsset
    {
        $extension = $type === 'preview_image' ? 'jpg' : $type;
        $fileName = "QA_RUN_20261006_model.{$extension}";
        $path = "dishes/{$dish->id}/{$fileName}";
        if ($writeFile) {
            $this->disk->put($path, "QA_RUN_original_{$dish->id}_{$type}");
        }

        return DishAsset::create([
            'uuid' => (string) Str::uuid(), 'dish_id' => $dish->id, 'asset_type' => $type,
            'storage_disk' => DishAsset::PROTECTED_DISK, 'file_path' => $path,
            'glb_path' => $type === 'glb' ? $path : null, 'usdz_path' => $type === 'usdz' ? $path : null,
            'file_url' => '', 'file_size' => 32,
            'mime_type' => match ($type) {
                'glb' => 'model/gltf-binary', 'usdz' => 'model/vnd.usdz+zip', default => 'image/jpeg'
            },
            'metadata' => ['file_name' => $fileName],
        ]);
    }

    private function upload(string $type): UploadedFile
    {
        return $type === 'preview_image'
            ? UploadedFile::fake()->image('QA_RUN_20261006_model.jpg', 30, 30)
            : UploadedFile::fake()->createWithContent("QA_RUN_20261006_model.{$type}", "QA_RUN_new_{$type}_bytes");
    }

    private function assertPreserved(DishAsset $asset): void
    {
        $this->assertDatabaseHas('dish_assets', ['id' => $asset->id, 'file_path' => $asset->file_path]);
        $this->assertSame("QA_RUN_original_{$asset->dish_id}_{$asset->asset_type}", $this->disk->get($asset->file_path));
    }

    public static function writeFailures(): array
    {
        $cases = [];
        foreach (['glb', 'usdz', 'preview_image'] as $type) {
            foreach (['exception', 'false'] as $failure) {
                $cases["{$type} {$failure}"] = [$type, $failure];
            }
        }

        return $cases;
    }

    #[DataProvider('writeFailures')]
    public function test_failed_replacement_preserves_previous_asset(string $type, string $failure): void
    {
        $old = $this->asset($this->target, $type);
        $broken = Mockery::mock($this->disk)->makePartial();
        $expectation = $broken->shouldReceive('putFileAs');
        if ($failure === 'exception') {
            $expectation->andThrow(new RuntimeException('QA_RUN simulated write failure'));
        } else {
            $expectation->andReturn(false);
        }
        Storage::set(DishAsset::PROTECTED_DISK, $broken);

        $this->post("/api/dishes/{$this->target->id}/assets", ['type' => $type, 'file' => $this->upload($type)], ['Accept' => 'application/json'])
            ->assertStatus(500);
        $this->assertPreserved($old);
        $this->assertSame([$old->file_path], $this->disk->allFiles());
    }

    public static function assetTypes(): array
    {
        return [['glb'], ['usdz'], ['preview_image']];
    }

    #[DataProvider('assetTypes')]
    public function test_same_filename_replacement_uses_new_path_and_cleans_old_file(string $type): void
    {
        $old = $this->asset($this->target, $type);
        $file = $this->upload($type);
        $contents = file_get_contents($file->getRealPath());

        $response = $this->post("/api/dishes/{$this->target->id}/assets", ['type' => $type, 'file' => $file], ['Accept' => 'application/json']);
        $response->assertCreated()->assertJsonPath('metadata.file_name', $file->getClientOriginalName());
        $new = DishAsset::findOrFail($response->json('id'));
        $this->assertNotSame($old->file_path, $new->file_path);
        $this->assertDatabaseMissing('dish_assets', ['id' => $old->id]);
        $this->disk->assertMissing($old->file_path);
        $this->assertSame($contents, $this->disk->get($new->file_path));
        $stream = $this->get($new->file_url)->assertOk();
        $this->assertSame($contents, $stream->streamedContent());
    }

    public function test_partial_file_write_is_cleaned_without_touching_previous_asset(): void
    {
        $old = $this->asset($this->target, 'glb');
        $broken = Mockery::mock($this->disk)->makePartial();
        $broken->shouldReceive('putFileAs')->andReturnUsing(function (string $directory, mixed $file, string $name): never {
            $this->disk->put("{$directory}/{$name}", 'QA_RUN_partial_file');
            throw new RuntimeException('QA_RUN interrupted file write');
        });
        Storage::set(DishAsset::PROTECTED_DISK, $broken);

        $this->post("/api/dishes/{$this->target->id}/assets", ['type' => 'glb', 'file' => $this->upload('glb')], ['Accept' => 'application/json'])
            ->assertStatus(500);
        $this->assertPreserved($old);
        $this->assertSame([$old->file_path], $this->disk->allFiles());
    }

    public function test_long_original_filename_fits_storage_path_and_is_kept_in_metadata(): void
    {
        $old = $this->asset($this->target, 'glb');
        $name = 'QA_RUN_'.str_repeat('m', 210).'.glb';
        $file = UploadedFile::fake()->createWithContent($name, 'QA_RUN_new_long_filename_bytes');

        $response = $this->post("/api/dishes/{$this->target->id}/assets", ['type' => 'glb', 'file' => $file], ['Accept' => 'application/json']);
        $response->assertCreated()->assertJsonPath('metadata.file_name', $name);
        $this->assertLessThanOrEqual(255, strlen($response->json('file_path')));
        $this->assertSame('QA_RUN_new_long_filename_bytes', $this->disk->get($response->json('file_path')));
        $this->disk->assertMissing($old->file_path);
    }

    public function test_long_source_filename_does_not_break_model_copy(): void
    {
        $this->asset($this->target, 'glb');
        $source = $this->dish('source');
        $asset = $this->asset($source, 'glb');
        $name = 'QA_RUN_'.str_repeat('m', 210).'.glb';
        $asset->update(['metadata' => ['file_name' => $name]]);

        $response = $this->postJson("/api/dishes/{$this->target->id}/copy-model", ['source_dish_id' => $source->id]);
        $response->assertOk()->assertJsonPath('assets.0.metadata.file_name', $name);
        $new = $this->target->assets()->firstOrFail();
        $this->assertLessThanOrEqual(255, strlen($new->file_path));
        $this->assertSame("QA_RUN_original_{$source->id}_glb", $this->disk->get($new->file_path));
    }

    public function test_database_failure_preserves_old_asset_and_cleans_new_upload(): void
    {
        $old = $this->asset($this->target, 'glb');
        Event::listen('eloquent.creating: '.DishAsset::class, function (): void {
            throw new RuntimeException('QA_RUN simulated asset record failure');
        });

        $this->post("/api/dishes/{$this->target->id}/assets", ['type' => 'glb', 'file' => $this->upload('glb')], ['Accept' => 'application/json'])
            ->assertStatus(500);
        $this->assertPreserved($old);
        $this->assertSame([$old->file_path], $this->disk->allFiles());
    }

    public function test_failure_during_old_record_deletion_rolls_back_replacement(): void
    {
        $old = $this->asset($this->target, 'glb');
        Event::listen('eloquent.deleting: '.DishAsset::class, function (): void {
            throw new RuntimeException('QA_RUN simulated old record deletion failure');
        });

        $this->post("/api/dishes/{$this->target->id}/assets", ['type' => 'glb', 'file' => $this->upload('glb')], ['Accept' => 'application/json'])
            ->assertStatus(500);
        $this->assertPreserved($old);
        $this->assertSame([$old->file_path], $this->disk->allFiles());
        $this->assertSame(1, $this->target->assets()->count());
    }

    public function test_missing_second_source_file_preserves_both_target_models(): void
    {
        $oldGlb = $this->asset($this->target, 'glb');
        $oldUsdz = $this->asset($this->target, 'usdz');
        $source = $this->dish('source');
        $sourceGlb = $this->asset($source, 'glb');
        $this->asset($source, 'usdz', false);
        $before = $this->disk->allFiles();

        $this->postJson("/api/dishes/{$this->target->id}/copy-model", ['source_dish_id' => $source->id])->assertStatus(500);
        $this->assertPreserved($oldGlb);
        $this->assertPreserved($oldUsdz);
        $this->assertPreserved($sourceGlb);
        $this->assertSame($before, $this->disk->allFiles());
    }

    public function test_second_model_record_failure_rolls_back_both_models_and_cleans_copies(): void
    {
        $oldGlb = $this->asset($this->target, 'glb');
        $oldUsdz = $this->asset($this->target, 'usdz');
        $source = $this->dish('source');
        $this->asset($source, 'glb');
        $this->asset($source, 'usdz');
        $before = $this->disk->allFiles();
        Event::listen('eloquent.creating: '.DishAsset::class, function (DishAsset $asset): void {
            if ($asset->asset_type === 'usdz') {
                throw new RuntimeException('QA_RUN simulated second record failure');
            }
        });

        $this->postJson("/api/dishes/{$this->target->id}/copy-model", ['source_dish_id' => $source->id])->assertStatus(500);
        $this->assertPreserved($oldGlb);
        $this->assertPreserved($oldUsdz);
        $this->assertSame($before, $this->disk->allFiles());
        $this->assertSame(2, $this->target->assets()->count());
    }

    public function test_successful_copy_replaces_models_without_touching_preview_or_source(): void
    {
        $oldGlb = $this->asset($this->target, 'glb');
        $oldUsdz = $this->asset($this->target, 'usdz');
        $preview = $this->asset($this->target, 'preview_image');
        $source = $this->dish('source');
        $sourceGlb = $this->asset($source, 'glb');
        $sourceUsdz = $this->asset($source, 'usdz');

        $this->postJson("/api/dishes/{$this->target->id}/copy-model", ['source_dish_id' => $source->id])
            ->assertOk()->assertJsonCount(3, 'assets');
        $this->assertDatabaseMissing('dish_assets', ['id' => $oldGlb->id]);
        $this->assertDatabaseMissing('dish_assets', ['id' => $oldUsdz->id]);
        $this->disk->assertMissing([$oldGlb->file_path, $oldUsdz->file_path]);
        $this->assertPreserved($preview);
        $this->assertPreserved($sourceGlb);
        $this->assertPreserved($sourceUsdz);
        foreach ($this->target->assets()->whereIn('asset_type', ['glb', 'usdz'])->get() as $asset) {
            $this->assertSame("QA_RUN_original_{$source->id}_{$asset->asset_type}", $this->disk->get($asset->file_path));
        }
    }

    public function test_glb_only_copy_removes_stale_usdz_after_success(): void
    {
        $oldGlb = $this->asset($this->target, 'glb');
        $oldUsdz = $this->asset($this->target, 'usdz');
        $source = $this->dish('source');
        $this->asset($source, 'glb');

        $this->postJson("/api/dishes/{$this->target->id}/copy-model", ['source_dish_id' => $source->id])->assertOk()->assertJsonCount(1, 'assets');
        $this->disk->assertMissing([$oldGlb->file_path, $oldUsdz->file_path]);
        $this->assertSame(0, $this->target->assets()->where('asset_type', 'usdz')->count());
    }

    public function test_replacement_does_not_delete_an_old_file_still_used_by_another_asset(): void
    {
        $old = $this->asset($this->target, 'glb');
        $sharedDish = $this->dish('shared');
        $shared = $old->replicate(['uuid']);
        $shared->uuid = (string) Str::uuid();
        $shared->dish_id = $sharedDish->id;
        $shared->save();

        $this->post("/api/dishes/{$this->target->id}/assets", ['type' => 'glb', 'file' => $this->upload('glb')], ['Accept' => 'application/json'])->assertCreated();
        $this->assertDatabaseMissing('dish_assets', ['id' => $old->id]);
        $this->assertDatabaseHas('dish_assets', ['id' => $shared->id]);
        $this->assertSame("QA_RUN_original_{$this->target->id}_glb", $this->disk->get($shared->file_path));
    }

    public function test_cleanup_failure_does_not_turn_a_successful_replacement_into_an_error(): void
    {
        $old = $this->asset($this->target, 'glb');
        $broken = Mockery::mock($this->disk)->makePartial();
        $broken->shouldReceive('delete')->with($old->file_path)->andThrow(new RuntimeException('QA_RUN cleanup unavailable'));
        Storage::set(DishAsset::PROTECTED_DISK, $broken);

        $response = $this->post("/api/dishes/{$this->target->id}/assets", ['type' => 'glb', 'file' => $this->upload('glb')], ['Accept' => 'application/json']);
        $response->assertCreated();
        $this->assertDatabaseMissing('dish_assets', ['id' => $old->id]);
        $this->disk->assertExists($response->json('file_path'));
    }

    public function test_other_tenant_model_cannot_replace_target(): void
    {
        $old = $this->asset($this->target, 'glb');
        $prefix = 'QA_RUN_20261006_foreign_'.bin2hex(random_bytes(4));
        $owner = User::factory()->create(['name' => $prefix, 'email' => $prefix.'@example.invalid']);
        $restaurant = Restaurant::factory()->for($owner)->create(['name' => $prefix, 'slug' => strtolower($prefix)]);
        $source = Dish::factory()->for($restaurant)->create(['name' => $prefix]);
        $this->asset($source, 'glb');

        $this->postJson("/api/dishes/{$this->target->id}/copy-model", ['source_dish_id' => $source->id])->assertNotFound();
        $this->assertPreserved($old);
    }
}
