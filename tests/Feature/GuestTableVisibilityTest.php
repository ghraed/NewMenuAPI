<?php

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\Restaurant;
use App\Models\RestaurantFeature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GuestTableVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_tables_endpoint_hides_inactive_tables(): void
    {
        $owner = User::factory()->admin()->create();
        $restaurant = $this->createRestaurant($owner);
        $this->enableFeature($restaurant, 'qr_menu');

        $inactiveTable = $restaurant->tables()->where('name', 'T03')->firstOrFail();
        $inactiveTable->update(['is_active' => false]);

        $response = $this->getJson('/api/menu/tables');

        $response->assertOk();
        $tableNames = collect($response->json('tables'))->pluck('name')->all();

        $this->assertNotContains('T03', $tableNames);
        $this->assertContains('T01', $tableNames);
    }

    private function createRestaurant(User $owner): Restaurant
    {
        return Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'name' => 'Visibility Test '.Str::upper(Str::random(4)),
            'slug' => 'visibility-test-'.Str::lower(Str::random(8)),
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
