<?php

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\Restaurant;
use App\Models\RestaurantFeature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoomPlanEditorApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_room_plan_layout_persists_updates_and_reopens_with_saved_items(): void
    {
        $admin = User::factory()->admin()->create();
        $restaurant = $this->createRestaurant($admin);
        $this->enableFeature($restaurant, 'room_plan_editor');
        $this->enableFeature($restaurant, 'table_reservations');

        Sanctum::actingAs($admin);

        $roomPlanId = (int) $this->postJson('/api/room-plans', [
            'name' => 'Persistence Plan',
            'width' => 900,
            'height' => 700,
        ])->json('room_plan.id');

        $saveResponse = $this->putJson("/api/room-plans/{$roomPlanId}/items/bulk", [
            'items' => [
                [
                    'type' => 'table',
                    'label' => 'T-01',
                    'x' => 120,
                    'y' => 140,
                    'width' => 140,
                    'height' => 100,
                    'rotation' => 45,
                    'seats' => 6,
                    'z_index' => 3,
                    'container' => 'room',
                    'is_active' => true,
                ],
            ],
        ]);

        $saveResponse->assertOk()
            ->assertJsonPath('items.0.label', 'T-01')
            ->assertJsonPath('items.0.rotation', 45)
            ->assertJsonPath('items.0.seats', 6);

        $this->getJson("/api/room-plans/{$roomPlanId}")
            ->assertOk()
            ->assertJsonPath('room_plan.items.0.label', 'T-01')
            ->assertJsonPath('room_plan.items.0.x', 120)
            ->assertJsonPath('room_plan.items.0.y', 140)
            ->assertJsonPath('room_plan.items.0.rotation', 45)
            ->assertJsonPath('room_plan.items.0.container', 'room');
    }

    public function test_admin_can_manage_room_plan_editor_and_table_sync(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        $restaurant = $this->createRestaurant($admin);
        $this->enableFeature($restaurant, 'room_plan_editor');
        $this->enableFeature($restaurant, 'table_reservations');

        Sanctum::actingAs($admin);

        $createResponse = $this->postJson('/api/room-plans', [
            'name' => 'Main Hall',
            'width' => 1200,
            'height' => 900,
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('room_plan.name', 'Main Hall')
            ->assertJsonPath('room_plan.width', 1200)
            ->assertJsonPath('room_plan.height', 900);

        $roomPlanId = (int) $createResponse->json('room_plan.id');

        $this->patchJson("/api/room-plans/{$roomPlanId}", [
            'name' => 'Main Hall Updated',
            'width' => 1400,
            'height' => 1000,
        ])->assertOk()
            ->assertJsonPath('room_plan.name', 'Main Hall Updated')
            ->assertJsonPath('room_plan.width', 1400)
            ->assertJsonPath('room_plan.height', 1000);

        $uploadResponse = $this->postJson("/api/room-plans/{$roomPlanId}/background", [
            'file' => UploadedFile::fake()->image('plan.png', 1600, 1200),
        ]);

        $uploadResponse->assertOk()
            ->assertJsonPath('room_plan.id', $roomPlanId);

        $bulkResponse = $this->putJson("/api/room-plans/{$roomPlanId}/items/bulk", [
            'items' => [
                [
                    'type' => 'table',
                    'label' => 'VIP-A',
                    'x' => 100,
                    'y' => 150,
                    'width' => 120,
                    'height' => 120,
                    'rotation' => 0,
                    'seats' => 4,
                    'z_index' => 1,
                    'container' => 'room',
                    'is_active' => true,
                ],
                [
                    'type' => 'window',
                    'label' => 'South Window',
                    'x' => 450,
                    'y' => 80,
                    'width' => 220,
                    'height' => 50,
                    'rotation' => 0,
                    'z_index' => 2,
                    'container' => 'wrapper',
                    'is_active' => true,
                ],
            ],
        ]);

        $bulkResponse->assertOk()
            ->assertJsonCount(2, 'items');

        $tableItem = collect($bulkResponse->json('items'))->firstWhere('type', 'table');
        $this->assertIsArray($tableItem);

        $this->assertDatabaseHas('room_plan_items', [
            'id' => $tableItem['id'],
            'room_plan_id' => $roomPlanId,
            'type' => 'table',
            'label' => 'VIP-A',
            'seats' => 4,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('restaurant_tables', [
            'restaurant_id' => $restaurant->id,
            'name' => 'VIP-A',
            'is_active' => true,
            'seats' => 4,
        ]);

        $duplicateResponse = $this->postJson("/api/room-plans/{$roomPlanId}/items/{$tableItem['id']}/duplicate");
        $duplicateResponse->assertCreated();

        $duplicateItemId = (int) $duplicateResponse->json('item.id');
        $duplicateTableId = (int) $duplicateResponse->json('item.restaurant_table_id');

        $this->deleteJson("/api/room-plans/{$roomPlanId}/items/{$tableItem['id']}")
            ->assertOk();

        $this->deleteJson("/api/room-plans/{$roomPlanId}/items/{$duplicateItemId}")
            ->assertOk();

        $this->assertDatabaseHas('restaurant_tables', [
            'id' => $duplicateTableId,
            'is_active' => false,
        ]);

        $this->deleteJson("/api/room-plans/{$roomPlanId}")
            ->assertOk();

        $this->assertSoftDeleted('room_plans', [
            'id' => $roomPlanId,
        ]);
    }

    public function test_room_plan_item_bounds_are_validated(): void
    {
        $admin = User::factory()->admin()->create();
        $restaurant = $this->createRestaurant($admin);
        $this->enableFeature($restaurant, 'room_plan_editor');
        $this->enableFeature($restaurant, 'table_reservations');

        Sanctum::actingAs($admin);

        $roomPlanId = (int) $this->postJson('/api/room-plans', [
            'name' => 'Compact Plan',
            'width' => 800,
            'height' => 600,
        ])->json('room_plan.id');

        $response = $this->putJson("/api/room-plans/{$roomPlanId}/items/bulk", [
            'items' => [
                [
                    'type' => 'table',
                    'label' => 'Out of Bounds',
                    'x' => 780,
                    'y' => 200,
                    'width' => 50,
                    'height' => 50,
                    'rotation' => 0,
                    'seats' => 2,
                    'z_index' => 1,
                    'container' => 'room',
                    'is_active' => true,
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['bounds']);
    }

    public function test_duplicate_table_labels_are_auto_renamed_within_the_same_restaurant(): void
    {
        $admin = User::factory()->admin()->create();
        $restaurant = $this->createRestaurant($admin);
        $this->enableFeature($restaurant, 'room_plan_editor');
        $this->enableFeature($restaurant, 'table_reservations');

        Sanctum::actingAs($admin);

        $roomPlanId = (int) $this->postJson('/api/room-plans', [
            'name' => 'Label Plan',
            'width' => 800,
            'height' => 600,
        ])->json('room_plan.id');

        $response = $this->putJson("/api/room-plans/{$roomPlanId}/items/bulk", [
            'items' => [
                [
                    'type' => 'table',
                    'label' => 'VIP',
                    'x' => 40,
                    'y' => 40,
                    'width' => 100,
                    'height' => 100,
                    'rotation' => 0,
                    'seats' => 4,
                    'z_index' => 1,
                    'container' => 'room',
                    'is_active' => true,
                ],
                [
                    'type' => 'table',
                    'label' => 'VIP',
                    'x' => 180,
                    'y' => 40,
                    'width' => 100,
                    'height' => 100,
                    'rotation' => 0,
                    'seats' => 4,
                    'z_index' => 2,
                    'container' => 'room',
                    'is_active' => true,
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('items.0.label', 'VIP')
            ->assertJsonPath('items.1.label', 'VIP 2');

        $this->assertDatabaseHas('restaurant_tables', [
            'restaurant_id' => $restaurant->id,
            'name' => 'VIP',
        ]);
        $this->assertDatabaseHas('restaurant_tables', [
            'restaurant_id' => $restaurant->id,
            'name' => 'VIP 2',
        ]);
    }

    public function test_negative_coordinates_and_invalid_dimensions_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $restaurant = $this->createRestaurant($admin);
        $this->enableFeature($restaurant, 'room_plan_editor');

        Sanctum::actingAs($admin);

        $roomPlanId = (int) $this->postJson('/api/room-plans', [
            'name' => 'Validation Plan',
            'width' => 800,
            'height' => 600,
        ])->json('room_plan.id');

        $this->putJson("/api/room-plans/{$roomPlanId}/items/bulk", [
            'items' => [
                [
                    'type' => 'table',
                    'label' => 'Negative X',
                    'x' => -1,
                    'y' => 30,
                    'width' => 100,
                    'height' => 100,
                    'rotation' => 0,
                    'seats' => 4,
                    'z_index' => 1,
                    'container' => 'room',
                    'is_active' => true,
                ],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['x']);

        $this->putJson("/api/room-plans/{$roomPlanId}/items/bulk", [
            'items' => [
                [
                    'type' => 'table',
                    'label' => 'Zero Width',
                    'x' => 30,
                    'y' => 30,
                    'width' => 0,
                    'height' => 100,
                    'rotation' => 0,
                    'seats' => 4,
                    'z_index' => 1,
                    'container' => 'room',
                    'is_active' => true,
                ],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['width']);
    }

    public function test_cross_tenant_room_plan_access_is_rejected(): void
    {
        $primaryAdmin = User::factory()->admin()->create();
        $primaryRestaurant = $this->createRestaurant($primaryAdmin);
        $this->enableFeature($primaryRestaurant, 'room_plan_editor');

        Sanctum::actingAs($primaryAdmin);
        $roomPlanId = (int) $this->postJson('/api/room-plans', [
            'name' => 'Tenant A Plan',
            'width' => 900,
            'height' => 700,
        ])->json('room_plan.id');

        $secondaryAdmin = User::factory()->admin()->create();
        $secondaryRestaurant = $this->createRestaurant($secondaryAdmin);
        $this->enableFeature($secondaryRestaurant, 'room_plan_editor');

        Sanctum::actingAs($secondaryAdmin);

        $this->getJson("/api/room-plans/{$roomPlanId}")->assertStatus(404);
        $this->patchJson("/api/room-plans/{$roomPlanId}", [
            'name' => 'Should Not Work',
        ])->assertStatus(404);
        $this->deleteJson("/api/room-plans/{$roomPlanId}")->assertStatus(404);
    }

    private function createRestaurant(User $owner): Restaurant
    {
        return Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'name' => 'Room Plan Test '.Str::upper(Str::random(4)),
            'slug' => 'room-plan-test-'.Str::lower(Str::random(8)),
            'description' => 'Test restaurant',
            'address' => 'Beirut',
        ]);
    }

    private function enableFeature(Restaurant $restaurant, string $key): void
    {
        $feature = Feature::query()->updateOrCreate(
            ['key' => $key],
            [
                'name' => Str::title(str_replace('_', ' ', $key)),
                'description' => 'Test feature',
                'category' => 'Tests',
                'is_active_by_default' => false,
            ]
        );

        RestaurantFeature::query()->updateOrCreate(
            [
                'restaurant_id' => $restaurant->id,
                'feature_id' => $feature->id,
            ],
            [
                'enabled' => true,
            ]
        );
    }
}
