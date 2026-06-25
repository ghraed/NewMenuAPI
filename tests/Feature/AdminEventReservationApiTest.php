<?php

namespace Tests\Feature;

use App\Models\EventReservation;
use App\Models\Feature;
use App\Models\Restaurant;
use App\Models\RoomPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminEventReservationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_update_rejects_room_plan_from_another_restaurant(): void
    {
        $admin = User::factory()->admin()->create();
        $restaurant = $this->createRestaurant($admin);
        $this->enableFeature($restaurant, 'event_reservations');

        $otherAdmin = User::factory()->admin()->create();
        $otherRestaurant = $this->createRestaurant($otherAdmin);
        $foreignRoomPlan = RoomPlan::query()->create([
            'restaurant_id' => $otherRestaurant->id,
            'name' => 'Foreign Plan',
            'width' => 1200,
            'height' => 900,
        ]);

        $event = EventReservation::query()->create([
            'restaurant_id' => $restaurant->id,
            'title' => 'Private Dinner',
            'customer_name' => 'Dana',
            'customer_phone' => '+96170000111',
            'start_at' => '2026-07-01 19:00:00',
            'end_at' => '2026-07-01 22:00:00',
            'status' => EventReservation::STATUS_DRAFT,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/admin/events/{$event->id}", [
            'title' => 'Private Dinner Updated',
            'customer_name' => 'Dana',
            'customer_phone' => '+96170000111',
            'event_date' => '2026-07-01',
            'start_time' => '19:00',
            'end_time' => '22:00',
            'room_plan_id' => $foreignRoomPlan->id,
            'status' => EventReservation::STATUS_DRAFT,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['room_plan_id']);
    }

    private function createRestaurant(User $owner): Restaurant
    {
        return Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'name' => 'Events Test '.Str::upper(Str::random(4)),
            'slug' => 'events-test-'.Str::lower(Str::random(8)),
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

        $restaurant->features()->syncWithoutDetaching([
            $feature->id => ['enabled' => true],
        ]);
    }
}
