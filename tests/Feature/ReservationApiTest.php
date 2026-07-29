<?php

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\RestaurantFeature;
use App\Models\RoomPlan;
use App\Models\RoomPlanItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReservationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_and_transition_reservation_statuses(): void
    {
        $admin = User::factory()->admin()->create();
        $restaurant = $this->createRestaurant($admin);
        $this->enableFeature($restaurant, 'table_reservations');

        [$roomPlan, $firstTable] = $this->createPlanWithTable($restaurant, 'T-1', 4);
        [, $secondTable] = $this->createPlanWithTable($restaurant, 'T-2', 2, $roomPlan);

        $reservation = Reservation::query()->create([
            'restaurant_id' => $restaurant->id,
            'room_plan_id' => $roomPlan->id,
            'room_plan_item_id' => $firstTable->id,
            'customer_name' => 'Initial Guest',
            'customer_phone' => '+96170101010',
            'reservation_date' => '2026-05-15',
            'start_time' => '18:00',
            'end_time' => '19:00',
            'start_at' => '2026-05-15 18:00:00',
            'end_at' => '2026-05-15 19:00:00',
            'status' => Reservation::STATUS_RESERVED,
        ]);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/reservations/{$reservation->id}", [
            'room_plan_id' => $roomPlan->id,
            'room_plan_item_id' => $secondTable->id,
            'customer_name' => 'Updated Guest',
            'customer_phone' => '+96170202020',
            'reservation_date' => '2026-05-15',
            'start_time' => '19:00',
            'end_time' => '20:00',
            'notes' => 'Moved to another table',
        ])->assertOk()
            ->assertJsonPath('reservation.room_plan_item_id', $secondTable->id)
            ->assertJsonPath('reservation.customer_name', 'Updated Guest')
            ->assertJsonPath('reservation.notes', 'Moved to another table');

        $this->postJson("/api/admin/reservations/{$reservation->id}/complete")
            ->assertOk()
            ->assertJsonPath('reservation.status', Reservation::STATUS_COMPLETED);

        $this->postJson("/api/admin/reservations/{$reservation->id}/cancel")
            ->assertOk()
            ->assertJsonPath('reservation.status', Reservation::STATUS_CANCELLED);
    }

    public function test_public_reservation_flow_returns_availability_and_blocks_overlap(): void
    {
        $admin = User::factory()->admin()->create();
        $restaurant = $this->createRestaurant($admin);
        $this->enableFeature($restaurant, 'table_reservations');

        [$roomPlan, $tableItem] = $this->createPlanWithTable($restaurant, 'T-Patio', 6);

        $this->getJson('/api/reservations/room-plans')
            ->assertOk()
            ->assertJsonPath('room_plans.0.id', $roomPlan->id);

        $availabilityBefore = $this->getJson('/api/reservations/availability?room_plan_id='.$roomPlan->id.'&reservation_date=2026-05-05&start_time=19:00&end_time=20:00');
        $availabilityBefore->assertOk()
            ->assertJsonPath('availability.0.status', 'free')
            ->assertJsonPath('availability.0.is_selectable', true);

        $createResponse = $this->postJson('/api/reservations', [
            'room_plan_id' => $roomPlan->id,
            'room_plan_item_id' => $tableItem->id,
            'customer_name' => 'Lina Haddad',
            'customer_phone' => '+96170111222',
            'customer_email' => 'lina@example.com',
            'reservation_date' => '2026-05-05',
            'start_time' => '19:00',
            'end_time' => '20:00',
            'notes' => 'Birthday setup',
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('reservation.status', Reservation::STATUS_RESERVED)
            ->assertJsonPath('reservation.room_plan_item_id', $tableItem->id);

        $availabilityAfter = $this->getJson('/api/reservations/availability?room_plan_id='.$roomPlan->id.'&reservation_date=2026-05-05&start_time=19:15&end_time=19:45');
        $availabilityAfter->assertOk()
            ->assertJsonPath('availability.0.status', Reservation::STATUS_RESERVED)
            ->assertJsonPath('availability.0.is_selectable', false)
            ->assertJsonPath('availability.0.color', 'orange');

        $overlapResponse = $this->postJson('/api/reservations', [
            'room_plan_id' => $roomPlan->id,
            'room_plan_item_id' => $tableItem->id,
            'customer_name' => 'Blocked Guest',
            'customer_phone' => '+96170333444',
            'reservation_date' => '2026-05-05',
            'start_time' => '19:30',
            'end_time' => '20:30',
        ]);

        $overlapResponse->assertStatus(422)
            ->assertJsonValidationErrors(['overlap']);
    }

    public function test_competing_same_slot_requests_follow_conflict_policy_and_prevent_double_booking(): void
    {
        $admin = User::factory()->admin()->create();
        $restaurant = $this->createRestaurant($admin);
        $this->enableFeature($restaurant, 'table_reservations');

        [$roomPlan, $tableItem] = $this->createPlanWithTable($restaurant, 'Shared Table', 4);

        $payload = [
            'room_plan_id' => $roomPlan->id,
            'room_plan_item_id' => $tableItem->id,
            'customer_name' => 'First Guest',
            'customer_phone' => '+96170000001',
            'reservation_date' => '2026-05-16',
            'start_time' => '20:00',
            'end_time' => '21:00',
        ];

        $first = $this->postJson('/api/reservations', $payload);
        $second = $this->postJson('/api/reservations', [
            ...$payload,
            'customer_name' => 'Second Guest',
            'customer_phone' => '+96170000002',
        ]);

        $first->assertCreated();
        $second->assertStatus(422)
            ->assertJsonValidationErrors(['overlap']);

        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_cross_midnight_and_no_show_non_blocking_logic(): void
    {
        $admin = User::factory()->admin()->create();
        $restaurant = $this->createRestaurant($admin);
        $this->enableFeature($restaurant, 'table_reservations');

        [$roomPlan, $tableItem] = $this->createPlanWithTable($restaurant, 'Night Table', 4);

        $first = $this->postJson('/api/reservations', [
            'room_plan_id' => $roomPlan->id,
            'room_plan_item_id' => $tableItem->id,
            'customer_name' => 'Late Guest',
            'customer_phone' => '+96170123456',
            'reservation_date' => '2026-05-10',
            'start_time' => '23:30',
            'end_time' => '01:00',
        ]);

        $first->assertCreated();
        $reservationId = (int) $first->json('reservation.id');

        $crossOverlap = $this->postJson('/api/reservations', [
            'room_plan_id' => $roomPlan->id,
            'room_plan_item_id' => $tableItem->id,
            'customer_name' => 'Overlap Guest',
            'customer_phone' => '+96170999888',
            'reservation_date' => '2026-05-11',
            'start_time' => '00:30',
            'end_time' => '01:30',
        ]);

        $crossOverlap->assertStatus(422)
            ->assertJsonValidationErrors(['overlap']);

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/reservations/{$reservationId}/no-show")
            ->assertOk()
            ->assertJsonPath('reservation.status', Reservation::STATUS_NO_SHOW);

        $allowedNow = $this->postJson('/api/reservations', [
            'room_plan_id' => $roomPlan->id,
            'room_plan_item_id' => $tableItem->id,
            'customer_name' => 'Now Allowed',
            'customer_phone' => '+96170000111',
            'reservation_date' => '2026-05-11',
            'start_time' => '00:30',
            'end_time' => '01:30',
        ]);

        $allowedNow->assertCreated();
    }

    public function test_boundary_times_allow_back_to_back_reservations_without_overlap(): void
    {
        $admin = User::factory()->admin()->create();
        $restaurant = $this->createRestaurant($admin);
        $this->enableFeature($restaurant, 'table_reservations');

        [$roomPlan, $tableItem] = $this->createPlanWithTable($restaurant, 'Boundary Table', 4);

        $this->postJson('/api/reservations', [
            'room_plan_id' => $roomPlan->id,
            'room_plan_item_id' => $tableItem->id,
            'customer_name' => 'First Boundary Guest',
            'customer_phone' => '+96171111111',
            'reservation_date' => '2026-05-18',
            'start_time' => '18:00',
            'end_time' => '19:00',
        ])->assertCreated();

        $availability = $this->getJson('/api/reservations/availability?room_plan_id='.$roomPlan->id.'&reservation_date=2026-05-18&start_time=19:00&end_time=20:00');
        $availability->assertOk()
            ->assertJsonPath('availability.0.status', 'free')
            ->assertJsonPath('availability.0.is_selectable', true);

        $this->postJson('/api/reservations', [
            'room_plan_id' => $roomPlan->id,
            'room_plan_item_id' => $tableItem->id,
            'customer_name' => 'Second Boundary Guest',
            'customer_phone' => '+96172222222',
            'reservation_date' => '2026-05-18',
            'start_time' => '19:00',
            'end_time' => '20:00',
        ])->assertCreated();
    }

    public function test_feature_disabled_restaurant_cannot_access_public_reservation_routes(): void
    {
        $admin = User::factory()->admin()->create();
        $this->createRestaurant($admin);

        $this->getJson('/api/reservations/room-plans')->assertStatus(404);
        $this->postJson('/api/reservations', [
            'room_plan_id' => 1,
            'room_plan_item_id' => 1,
            'customer_name' => 'Blocked Guest',
            'customer_phone' => '+96173333333',
            'reservation_date' => '2026-05-20',
            'start_time' => '18:00',
            'end_time' => '19:00',
        ])->assertStatus(404);
    }

    public function test_validation_rejects_invalid_dates_and_times(): void
    {
        $admin = User::factory()->admin()->create();
        $restaurant = $this->createRestaurant($admin);
        $this->enableFeature($restaurant, 'table_reservations');

        [$roomPlan, $tableItem] = $this->createPlanWithTable($restaurant, 'Validation Table', 4);

        $this->postJson('/api/reservations', [
            'room_plan_id' => $roomPlan->id,
            'room_plan_item_id' => $tableItem->id,
            'customer_name' => 'Bad Input',
            'customer_phone' => '+96174444444',
            'reservation_date' => '2026-02-30',
            'start_time' => '18:00',
            'end_time' => '19:00',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['reservation_date']);

        $this->postJson('/api/reservations', [
            'room_plan_id' => $roomPlan->id,
            'room_plan_item_id' => $tableItem->id,
            'customer_name' => 'Bad Time',
            'customer_phone' => '+96175555555',
            'reservation_date' => '2026-05-20',
            'start_time' => '25:99',
            'end_time' => '26:00',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['start_time', 'end_time']);
    }

    public function test_cross_tenant_admin_reservation_access_is_rejected(): void
    {
        $primaryAdmin = User::factory()->admin()->create();
        $primaryRestaurant = $this->createRestaurant($primaryAdmin);
        $this->enableFeature($primaryRestaurant, 'table_reservations');
        [$roomPlan, $tableItem] = $this->createPlanWithTable($primaryRestaurant, 'Tenant A', 4);

        $reservation = Reservation::query()->create([
            'restaurant_id' => $primaryRestaurant->id,
            'room_plan_id' => $roomPlan->id,
            'room_plan_item_id' => $tableItem->id,
            'customer_name' => 'Tenant A Guest',
            'customer_phone' => '+96176666666',
            'reservation_date' => '2026-05-21',
            'start_time' => '18:00',
            'end_time' => '19:00',
            'start_at' => '2026-05-21 18:00:00',
            'end_at' => '2026-05-21 19:00:00',
            'status' => Reservation::STATUS_RESERVED,
        ]);

        $secondaryAdmin = User::factory()->admin()->create();
        $secondaryRestaurant = $this->createRestaurant($secondaryAdmin);
        $this->enableFeature($secondaryRestaurant, 'table_reservations');

        Sanctum::actingAs($secondaryAdmin);

        $this->patchJson("/api/admin/reservations/{$reservation->id}", [
            'customer_name' => 'Should Not Work',
        ])->assertStatus(404);

        $this->postJson("/api/admin/reservations/{$reservation->id}/cancel")
            ->assertStatus(404);
    }

    public function test_only_table_items_can_be_reserved(): void
    {
        $admin = User::factory()->admin()->create();
        $restaurant = $this->createRestaurant($admin);
        $this->enableFeature($restaurant, 'table_reservations');

        $roomPlan = RoomPlan::query()->create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Mixed Plan',
            'width' => 1200,
            'height' => 900,
        ]);

        $barItem = RoomPlanItem::query()->create([
            'room_plan_id' => $roomPlan->id,
            'type' => 'bar',
            'label' => 'Bar 1',
            'x' => 40,
            'y' => 40,
            'width' => 300,
            'height' => 120,
            'rotation' => 0,
            'z_index' => 1,
            'container' => 'room',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/reservations', [
            'room_plan_id' => $roomPlan->id,
            'room_plan_item_id' => $barItem->id,
            'customer_name' => 'Wrong Item',
            'customer_phone' => '+96170999000',
            'reservation_date' => '2026-05-14',
            'start_time' => '18:00',
            'end_time' => '19:00',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['room_plan_item_id']);
    }

    private function createRestaurant(User $owner): Restaurant
    {
        return Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'name' => 'Reservation Test '.Str::upper(Str::random(4)),
            'slug' => 'reservation-test-'.Str::lower(Str::random(8)),
            'description' => 'Test restaurant',
            'address' => 'Beirut',
        ]);
    }

    /**
     * @return array{RoomPlan, RoomPlanItem}
     */
    private function createPlanWithTable(Restaurant $restaurant, string $label, int $seats, ?RoomPlan $roomPlan = null): array
    {
        $roomPlan ??= RoomPlan::query()->create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Dining Plan',
            'width' => 1500,
            'height' => 1000,
        ]);

        $item = RoomPlanItem::query()->create([
            'room_plan_id' => $roomPlan->id,
            'type' => RoomPlanItem::TYPE_TABLE,
            'label' => $label,
            'x' => 100,
            'y' => 120,
            'width' => 120,
            'height' => 120,
            'rotation' => 0,
            'seats' => $seats,
            'z_index' => 1,
            'container' => RoomPlanItem::CONTAINER_ROOM,
            'is_active' => true,
        ]);

        return [$roomPlan, $item];
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
