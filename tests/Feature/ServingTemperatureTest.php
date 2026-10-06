<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServingTemperatureTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $prefix = 'QA_RUN_20261006_'.bin2hex(random_bytes(4));
        $this->admin = User::factory()->admin()->create(['name' => $prefix, 'email' => $prefix.'@example.invalid']);
        Restaurant::factory()->for($this->admin)->create(['name' => $prefix, 'slug' => strtolower($prefix), 'profile' => ['menu_categories' => ['Drinks']]]);
        Sanctum::actingAs($this->admin);
    }

    private function payload(array $extra = []): array
    {
        return [
            'name' => 'QA_RUN_20261006_Drink_'.bin2hex(random_bytes(4)),
            'price' => '3.50', 'category' => 'Drinks', 'item_type' => 'packaged_drink',
            'packaged_stock_quantity' => 10, ...$extra,
        ];
    }

    public static function temperatures(): array
    {
        return [['cold'], ['room']];
    }

    #[DataProvider('temperatures')]
    public function test_temperature_is_saved_on_create_and_returned_by_reads(string $temperature): void
    {
        $response = $this->postJson('/api/dishes', $this->payload(['serving_temperature' => $temperature]));
        $response->assertCreated()->assertJsonPath('serving_temperature', $temperature);
        $id = $response->json('id');
        $this->assertDatabaseHas('dishes', ['id' => $id, 'serving_temperature' => $temperature]);
        $this->getJson("/api/dishes/{$id}")->assertOk()->assertJsonPath('serving_temperature', $temperature);
        $this->getJson('/api/dishes')->assertOk()->assertJsonPath('data.0.serving_temperature', $temperature);
    }

    public function test_temperature_can_be_updated_and_cleared(): void
    {
        $response = $this->postJson('/api/menu-items', $this->payload(['serving_temperature' => 'cold']));
        $response->assertCreated()->assertJsonPath('serving_temperature', 'cold');
        $id = $response->json('id');
        $this->patchJson("/api/menu-items/{$id}", ['serving_temperature' => 'room'])->assertOk()->assertJsonPath('serving_temperature', 'room');
        $this->getJson("/api/dishes/{$id}")->assertOk()->assertJsonPath('serving_temperature', 'room');
        $this->patchJson("/api/dishes/{$id}", ['serving_temperature' => null])->assertOk()->assertJsonPath('serving_temperature', null);
        $this->assertNull(Dish::findOrFail($id)->serving_temperature);
    }

    public function test_omitting_temperature_during_update_preserves_it(): void
    {
        $response = $this->postJson('/api/dishes', $this->payload(['serving_temperature' => 'cold']));
        $response->assertCreated()->assertJsonPath('serving_temperature', 'cold');
        $id = $response->json('id');
        $this->patchJson("/api/dishes/{$id}", ['name' => 'QA_RUN_20261006_Renamed'])->assertOk()->assertJsonPath('serving_temperature', 'cold');
    }

    public function test_unset_temperature_remains_null_for_legacy_callers(): void
    {
        $response = $this->postJson('/api/dishes', $this->payload());
        $response->assertCreated()->assertJsonStructure(['serving_temperature'])->assertJsonPath('serving_temperature', null);
    }

    public function test_empty_temperature_clears_existing_value(): void
    {
        $response = $this->postJson('/api/dishes', $this->payload(['serving_temperature' => 'room']));
        $response->assertCreated()->assertJsonPath('serving_temperature', 'room');
        $this->patchJson('/api/dishes/'.$response->json('id'), ['serving_temperature' => ''])->assertOk()->assertJsonPath('serving_temperature', null);
    }

    public static function invalidTemperatures(): array
    {
        return [['hot'], ['COLD'], [42], [['cold']]];
    }

    #[DataProvider('invalidTemperatures')]
    public function test_invalid_temperature_is_rejected_without_partial_create_or_update(mixed $value): void
    {
        $payload = $this->payload(['serving_temperature' => $value]);
        $this->postJson('/api/dishes', $payload)->assertUnprocessable()->assertJsonValidationErrors('serving_temperature');
        $this->assertDatabaseMissing('dishes', ['name' => $payload['name']]);
        $response = $this->postJson('/api/dishes', $this->payload(['serving_temperature' => 'cold']));
        $response->assertCreated();
        $this->patchJson('/api/dishes/'.$response->json('id'), ['name' => 'QA_RUN_20261006_ShouldNotSave', 'serving_temperature' => $value])
            ->assertUnprocessable()->assertJsonValidationErrors('serving_temperature');
        $this->assertDatabaseHas('dishes', ['id' => $response->json('id'), 'name' => $response->json('name'), 'serving_temperature' => 'cold']);
    }

    public function test_predefined_packaged_drink_activation_persists_temperature(): void
    {
        $this->postJson('/api/admin/menu-item-templates/activate', [
            'template_key' => 'pepsi', 'name' => 'QA_RUN_20261006_Template', 'packaged_stock_quantity' => 10, 'serving_temperature' => 'cold',
        ])->assertCreated()->assertJsonPath('dish.serving_temperature', 'cold');
    }

    public function test_other_tenant_cannot_change_the_temperature(): void
    {
        $response = $this->postJson('/api/dishes', $this->payload(['serving_temperature' => 'cold']));
        $response->assertCreated()->assertJsonPath('serving_temperature', 'cold');
        $prefix = 'QA_RUN_20261006_Foreign_'.bin2hex(random_bytes(4));
        $other = User::factory()->admin()->create(['name' => $prefix, 'email' => $prefix.'@example.invalid']);
        Restaurant::factory()->for($other)->create(['name' => $prefix, 'slug' => strtolower($prefix)]);
        Sanctum::actingAs($other);
        $this->patchJson('/api/dishes/'.$response->json('id'), ['serving_temperature' => 'room'])->assertNotFound();
        $this->assertDatabaseHas('dishes', ['id' => $response->json('id'), 'serving_temperature' => 'cold']);
    }
}
