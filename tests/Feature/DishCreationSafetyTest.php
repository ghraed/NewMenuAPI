<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\DishAsset;
use App\Models\Ingredient;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DishCreationSafetyTest extends TestCase
{
    use RefreshDatabase;

    private FilesystemAdapter $disk;

    private Dish $reference;

    private array $payload;

    private array $originalCounts;

    private array $originalFiles;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disk = Storage::fake(DishAsset::PROTECTED_DISK);
        $prefix = 'QA_RUN_20261006_'.bin2hex(random_bytes(4));
        $user = User::factory()->admin()->create(['name' => $prefix, 'email' => $prefix.'@example.invalid']);
        $restaurant = Restaurant::factory()->for($user)->create(['name' => $prefix, 'slug' => strtolower($prefix), 'profile' => ['menu_categories' => ['Mains']]]);
        $this->reference = Dish::factory()->for($restaurant)->create(['name' => $prefix.'_reference', 'category' => 'Mains']);
        $ingredient = Ingredient::factory()->for($restaurant)->create(['name' => $prefix.'_ingredient']);
        $path = "dishes/{$this->reference->id}/QA_RUN_existing.glb";
        $this->disk->put($path, 'QA_RUN_original_model');
        DishAsset::create([
            'uuid' => (string) Str::uuid(), 'dish_id' => $this->reference->id, 'asset_type' => 'glb',
            'storage_disk' => DishAsset::PROTECTED_DISK, 'file_path' => $path, 'glb_path' => $path,
            'file_url' => '', 'file_size' => 21, 'mime_type' => 'model/gltf-binary',
        ]);
        $this->payload = [
            'name' => $prefix.'_new', 'price' => '9.25', 'category' => 'Mains', 'item_type' => 'prepared_dish',
            'status' => 'draft', 'suggested_dish_ids' => [$this->reference->id], 'related_dish_ids' => [$this->reference->id],
            'recipe_ingredients' => [['ingredient_id' => $ingredient->id, 'quantity_required' => '2.5']],
        ];
        $this->originalCounts = $this->counts();
        $this->originalFiles = $this->disk->allFiles();
        Sanctum::actingAs($user);
    }

    private function counts(): array
    {
        $counts = [];
        foreach (['dishes', 'dish_assets', 'dish_suggestions', 'dish_related_dishes', 'dish_ingredients'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    private function requestData(bool $withFiles = true): array
    {
        return $withFiles ? [
            ...$this->payload,
            'glb_file' => UploadedFile::fake()->createWithContent('QA_RUN_model.glb', 'QA_RUN_new_glb_bytes'),
            'usdz_file' => UploadedFile::fake()->createWithContent('QA_RUN_model.usdz', 'QA_RUN_new_usdz_bytes'),
        ] : $this->payload;
    }

    private function assertNoPartialSave(): void
    {
        $this->assertSame($this->originalCounts, $this->counts());
        $this->assertDatabaseMissing('dishes', ['name' => $this->payload['name']]);
        $this->assertSame($this->originalFiles, $this->disk->allFiles());
        $this->assertSame('QA_RUN_original_model', $this->disk->get($this->originalFiles[0]));
    }

    public static function uploadFailures(): array
    {
        return [
            'first model exception' => ['glb', true],
            'first model false result' => ['glb', false],
            'second model exception' => ['usdz', true],
            'second model false result' => ['usdz', false],
        ];
    }

    #[DataProvider('uploadFailures')]
    public function test_failed_upload_rolls_back_dish_relationships_and_files(string $type, bool $throws): void
    {
        $broken = Mockery::mock($this->disk)->makePartial();
        $broken->shouldReceive('putFileAs')->andReturnUsing(function (string $directory, mixed $file, string $name, array $options = []) use ($type, $throws): string|false {
            if (str_ends_with($name, '.'.$type)) {
                $this->disk->put("{$directory}/{$name}", 'QA_RUN_partial_upload');
                if ($throws) {
                    throw new RuntimeException('QA_RUN simulated upload failure');
                }

                return false;
            }

            return $this->disk->putFileAs($directory, $file, $name, $options);
        });
        Storage::set(DishAsset::PROTECTED_DISK, $broken);

        $this->post('/api/dishes', $this->requestData(), ['Accept' => 'application/json'])->assertStatus(500);
        $this->assertNoPartialSave();
    }

    public function test_second_asset_record_failure_rolls_back_creation_and_cleans_both_files(): void
    {
        Event::listen('eloquent.creating: '.DishAsset::class, function (DishAsset $asset): void {
            if ($asset->asset_type === 'usdz') {
                throw new RuntimeException('QA_RUN simulated asset record failure');
            }
        });

        $this->post('/api/dishes', $this->requestData(), ['Accept' => 'application/json'])->assertStatus(500);
        $this->assertNoPartialSave();
    }

    public function test_asset_url_save_failure_rolls_back_creation_and_cleans_uploaded_file(): void
    {
        Event::listen('eloquent.updating: '.DishAsset::class, function (): void {
            throw new RuntimeException('QA_RUN simulated asset URL update failure');
        });

        $this->post('/api/dishes', $this->requestData(), ['Accept' => 'application/json'])->assertStatus(500);
        $this->assertNoPartialSave();
    }

    public function test_response_loading_failure_rolls_back_creation_and_files(): void
    {
        Event::listen('eloquent.retrieved: '.DishAsset::class, function (DishAsset $asset): void {
            if ($asset->dish_id !== $this->reference->id) {
                throw new RuntimeException('QA_RUN simulated response loading failure');
            }
        });

        $this->post('/api/dishes', $this->requestData(), ['Accept' => 'application/json'])->assertStatus(500);
        $this->assertNoPartialSave();
    }

    public function test_success_saves_dish_models_and_relationships_with_readable_files(): void
    {
        $response = $this->post('/api/dishes', $this->requestData(), ['Accept' => 'application/json']);
        $response->assertCreated()->assertJsonPath('name', $this->payload['name'])
            ->assertJsonCount(2, 'assets')->assertJsonCount(1, 'suggested_dishes')
            ->assertJsonCount(1, 'related_dishes')->assertJsonCount(1, 'dish_ingredients');
        $dish = Dish::findOrFail($response->json('id'));
        foreach ($dish->assets as $asset) {
            $contents = "QA_RUN_new_{$asset->asset_type}_bytes";
            $this->assertSame($contents, $this->disk->get($asset->file_path));
            $stream = $this->get($asset->file_url)->assertOk();
            $this->assertSame($contents, $stream->streamedContent());
        }
        $this->assertSame('QA_RUN_original_model', $this->disk->get($this->originalFiles[0]));
    }

    public function test_creation_without_models_keeps_existing_supported_behavior(): void
    {
        $this->postJson('/api/dishes', $this->requestData(false))->assertCreated()->assertJsonCount(0, 'assets');
        $this->assertSame($this->originalFiles, $this->disk->allFiles());
    }

    public function test_invalid_file_is_rejected_before_creating_any_records_or_files(): void
    {
        $data = $this->requestData();
        $data['usdz_file'] = UploadedFile::fake()->createWithContent('QA_RUN_invalid.exe', 'QA_RUN_invalid_bytes');
        $this->post('/api/dishes', $data, ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('usdz_file');
        $this->assertNoPartialSave();
    }

    public function test_retry_after_failed_second_upload_creates_one_complete_dish(): void
    {
        $broken = Mockery::mock($this->disk)->makePartial();
        $broken->shouldReceive('putFileAs')->andReturnUsing(function (string $directory, mixed $file, string $name, array $options = []): string|false {
            if (str_ends_with($name, '.usdz')) {
                throw new RuntimeException('QA_RUN simulated second upload failure');
            }

            return $this->disk->putFileAs($directory, $file, $name, $options);
        });
        Storage::set(DishAsset::PROTECTED_DISK, $broken);
        $this->post('/api/dishes', $this->requestData(), ['Accept' => 'application/json'])->assertStatus(500);
        $this->assertNoPartialSave();
        Storage::set(DishAsset::PROTECTED_DISK, $this->disk);

        $this->post('/api/dishes', $this->requestData(), ['Accept' => 'application/json'])->assertCreated()->assertJsonCount(2, 'assets');
        $this->assertSame(1, Dish::where('name', $this->payload['name'])->count());
        $this->assertCount(count($this->originalFiles) + 2, $this->disk->allFiles());
    }

    public static function previewFailures(): array
    {
        return [['exception'], ['false']];
    }

    #[DataProvider('previewFailures')]
    public function test_failed_preview_upload_rolls_back_models_dish_and_relationships(string $failure): void
    {
        $broken = Mockery::mock($this->disk)->makePartial();
        $broken->shouldReceive('putFileAs')->andReturnUsing(function (string $directory, mixed $file, string $name, array $options = []) use ($failure): string|false {
            if (str_ends_with($name, '.jpg')) {
                $this->disk->put("{$directory}/{$name}", 'QA_RUN_partial_preview');
                if ($failure === 'exception') {
                    throw new RuntimeException('QA_RUN preview storage unavailable');
                }

                return false;
            }

            return $this->disk->putFileAs($directory, $file, $name, $options);
        });
        Storage::set(DishAsset::PROTECTED_DISK, $broken);
        $data = [...$this->requestData(), 'preview_file' => UploadedFile::fake()->image('QA_RUN_preview.jpg', 30, 30)];

        $this->post('/api/dishes', $data, ['Accept' => 'application/json'])->assertStatus(500);
        $this->assertNoPartialSave();
        Storage::set(DishAsset::PROTECTED_DISK, $this->disk);
        $response = $this->post('/api/dishes', $data, ['Accept' => 'application/json']);
        $response->assertCreated()->assertJsonCount(3, 'assets');
        $this->assertSame(1, Dish::where('name', $this->payload['name'])->count());
        $this->assertCount(count($this->originalFiles) + 3, $this->disk->allFiles());
    }

    public function test_preview_asset_record_failure_rolls_back_entire_creation(): void
    {
        Event::listen('eloquent.creating: '.DishAsset::class, function (DishAsset $asset): void {
            if ($asset->asset_type === 'preview_image') {
                throw new RuntimeException('QA_RUN preview record unavailable');
            }
        });
        $data = [...$this->requestData(), 'preview_file' => UploadedFile::fake()->image('QA_RUN_preview.jpg', 30, 30)];

        $this->post('/api/dishes', $data, ['Accept' => 'application/json'])->assertStatus(500);
        $this->assertNoPartialSave();
    }

    public function test_preview_and_models_are_created_together_and_preview_is_readable(): void
    {
        $file = UploadedFile::fake()->image('QA_RUN_preview.jpg', 30, 30);
        $contents = file_get_contents($file->getRealPath());
        $response = $this->post('/api/dishes', [...$this->requestData(), 'preview_file' => $file], ['Accept' => 'application/json']);
        $response->assertCreated()->assertJsonCount(3, 'assets');
        $preview = DishAsset::where('dish_id', $response->json('id'))->where('asset_type', 'preview_image')->firstOrFail();
        $this->assertSame('image/jpeg', $preview->mime_type);
        $this->assertSame('QA_RUN_preview.jpg', $preview->metadata['file_name']);
        $this->assertNull($preview->glb_path);
        $this->assertNull($preview->usdz_path);
        $stream = $this->get($preview->file_url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame($contents, $stream->streamedContent());
    }

    public function test_invalid_preview_is_rejected_before_creation(): void
    {
        $data = [...$this->requestData(), 'preview_file' => UploadedFile::fake()->createWithContent('QA_RUN_invalid.exe', 'QA_RUN_invalid')];
        $this->post('/api/dishes', $data, ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('preview_file');
        $this->assertNoPartialSave();
    }

    public function test_oversized_preview_is_rejected_before_creation(): void
    {
        $data = [...$this->requestData(), 'preview_file' => UploadedFile::fake()->image('QA_RUN_large.jpg')->size(51201)];
        $this->post('/api/dishes', $data, ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('preview_file');
        $this->assertNoPartialSave();
    }
}
