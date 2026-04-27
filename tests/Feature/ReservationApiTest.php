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
    private function createPlanWithTable(Restaurant $restaurant, string $label, int $seats): array
    {
        $roomPlan = RoomPlan::query()->create([
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
