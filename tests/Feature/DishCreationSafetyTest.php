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
        $this->disk = Storage::fake('public');
        $prefix = 'QA_RUN_20261006_'.bin2hex(random_bytes(4));
        $user = User::factory()->admin()->create(['name' => $prefix, 'email' => $prefix.'@example.invalid']);
        $restaurant = Restaurant::factory()->for($user)->create(['name' => $prefix, 'slug' => strtolower($prefix), 'profile' => ['menu_categories' => ['Mains']]]);
        $this->reference = Dish::factory()->for($restaurant)->create(['name' => $prefix.'_reference', 'category' => 'Mains']);
        $ingredient = Ingredient::factory()->for($restaurant)->create(['name' => $prefix.'_ingredient']);
        $path = "dishes/{$this->reference->id}/QA_RUN_existing.glb";
        $this->disk->put($path, 'QA_RUN_original_model');
        DishAsset::create([
            'uuid' => (string) Str::uuid(), 'dish_id' => $this->reference->id, 'asset_type' => 'glb',
            'storage_disk' => 'public', 'file_path' => $path, 'glb_path' => $path,
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
        Storage::set('public', $broken);

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
        Storage::set('public', $broken);
        $this->post('/api/dishes', $this->requestData(), ['Accept' => 'application/json'])->assertStatus(500);
        $this->assertNoPartialSave();
        Storage::set('public', $this->disk);

        $this->post('/api/dishes', $this->requestData(), ['Accept' => 'application/json'])->assertCreated()->assertJsonCount(2, 'assets');
        $this->assertSame(1, Dish::where('name', $this->payload['name'])->count());
        $this->assertCount(count($this->originalFiles) + 2, $this->disk->allFiles());
    }
}
