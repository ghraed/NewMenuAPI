<?php

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\Restaurant;
use App\Models\RestaurantFeature;
use App\Models\RestaurantTable;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

class BroadcastChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_wave_channel_auth_requires_same_restaurant_and_table_assignment(): void
    {
        $restaurant = $this->createRestaurant('wave-auth');
        $otherRestaurant = $this->createRestaurant('wave-auth-other');
        $assignedTable = $this->createTable($restaurant, 'T01');
        $unassignedTable = $this->createTable($restaurant, 'T02');
        $foreignTable = $this->createTable($otherRestaurant, 'T01');

        $staff = User::factory()->staff()->attachedToRestaurant($restaurant)->create();
        $staff->assignedTables()->attach($assignedTable->id);

        $this->assertChannelAuthorized($staff, "restaurant.{$restaurant->id}.table.{$assignedTable->id}.waves");
        $this->assertChannelForbidden($staff, "restaurant.{$restaurant->id}.table.{$unassignedTable->id}.waves");
        $this->assertChannelForbidden($staff, "restaurant.{$otherRestaurant->id}.table.{$foreignTable->id}.waves");
    }

    public function test_kitchen_channel_auth_is_limited_to_admins_and_chefs_in_the_current_restaurant(): void
    {
        $restaurant = $this->createRestaurant('kitchen-auth');
        $otherRestaurant = $this->createRestaurant('kitchen-auth-other');

        $chef = User::factory()->chef()->attachedToRestaurant($restaurant)->create();
        $staff = User::factory()->staff()->attachedToRestaurant($restaurant)->create();
        $otherChef = User::factory()->chef()->attachedToRestaurant($otherRestaurant)->create();

        $this->assertChannelAuthorized($chef, "restaurant.{$restaurant->id}.kitchen");
        $this->assertChannelForbidden($staff, "restaurant.{$restaurant->id}.kitchen");
        $this->assertChannelForbidden($otherChef, "restaurant.{$restaurant->id}.kitchen");
    }

    public function test_accounting_channel_auth_matches_finance_http_permissions(): void
    {
        $restaurant = $this->createRestaurant('accounting-auth');

        $admin = User::factory()->admin()->create();
        $adminRestaurant = $this->createRestaurant('accounting-admin', $admin);
        $accountant = User::factory()->accountant()->attachedToRestaurant($restaurant)->create();
        $staff = User::factory()->staff()->attachedToRestaurant($restaurant)->create();

        $this->assertChannelAuthorized($restaurant->user, "restaurant.{$restaurant->id}.accounting");
        $this->assertChannelAuthorized($accountant, "restaurant.{$restaurant->id}.accounting");
        $this->assertChannelForbidden($staff, "restaurant.{$restaurant->id}.accounting");
        $this->assertChannelForbidden($admin, "restaurant.{$restaurant->id}.accounting");

        $this->assertNotSame($restaurant->id, $adminRestaurant->id);
    }

    private function assertChannelAuthorized(User $user, string $channelName): void
    {
        $this->verifyChannelAuthorization($user, $channelName);
        $this->addToAssertionCount(1);
    }

    private function assertChannelForbidden(User $user, string $channelName): void
    {
        try {
            $this->verifyChannelAuthorization($user, $channelName);
            $this->fail("Expected channel [{$channelName}] to deny access for user [{$user->id}].");
        } catch (AccessDeniedHttpException) {
            $this->addToAssertionCount(1);
        }
    }

    private function verifyChannelAuthorization(User $user, string $channelName): void
    {
        $request = Request::create('/api/broadcasting/auth', 'POST', [
            'socket_id' => '1234.5678',
            'channel_name' => $channelName,
        ]);

        $request->setUserResolver(fn (): User => $user);

        $broadcaster = app(BroadcastManager::class)->driver();
        $method = new \ReflectionMethod($broadcaster, 'verifyUserCanAccessChannel');
        $method->setAccessible(true);
        $method->invoke($broadcaster, $request, $channelName);
    }

    private function createRestaurant(string $slugPrefix, ?User $owner = null): Restaurant
    {
        $owner ??= User::factory()->admin()->create();

        $restaurant = Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'name' => 'Broadcast Auth '.Str::upper(Str::random(4)),
            'slug' => $slugPrefix.'-'.Str::lower(Str::random(6)),
            'description' => 'Broadcast auth test restaurant',
            'address' => 'Beirut',
        ]);

        $this->enableFeature($restaurant, 'realtime_staff_orders');

        return $restaurant->fresh('user');
    }

    private function createTable(Restaurant $restaurant, string $name): RestaurantTable
    {
        return tap(
            RestaurantTable::query()->firstOrCreate(
                [
                    'restaurant_id' => $restaurant->id,
                    'name' => $name,
                ],
                [
                    'is_active' => true,
                    'seats' => 4,
                ]
            ),
            fn (RestaurantTable $table) => $table->update([
                'is_active' => true,
                'seats' => 4,
            ])
        );
    }

    private function enableFeature(Restaurant $restaurant, string $key): void
    {
        $feature = Feature::query()->updateOrCreate(
            ['key' => $key],
            [
                'name' => Str::title(str_replace('_', ' ', $key)),
                'description' => 'Feature used by broadcast auth tests.',
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
