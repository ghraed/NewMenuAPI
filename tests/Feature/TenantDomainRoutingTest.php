<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\Feature;
use App\Models\Restaurant;
use App\Models\RestaurantDomain;
use App\Models\RestaurantFeature;
use App\Models\TableSession;
use App\Models\User;
use App\Services\GuestMenuSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantDomainRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_host_based_guest_menu_resolves_to_domain_restaurant(): void
    {
        $alpha = $this->createRestaurant('alpha-kitchen');
        $sigma = $this->createRestaurant('sigma-kitchen');

        $this->attachDomain($alpha, 'alpha.rozer.fun');
        $this->attachDomain($sigma, 'sigma.rozer.fun');

        $alphaDish = $this->createDish($alpha, 'Alpha Burger');
        $this->createDish($sigma, 'Sigma Sushi');

        $response = $this->withHeaders(['Host' => 'alpha.rozer.fun'])
            ->getJson('/api/menu/dishes');

        $response->assertOk()
            ->assertJsonPath('restaurant.id', $alpha->id)
            ->assertJsonPath('dishes.0.id', $alphaDish->id);
    }

    public function test_unknown_host_returns_not_found_for_host_based_guest_routes(): void
    {
        $this->createRestaurant('alpha-kitchen');

        $this->withHeaders(['Host' => 'gamma.rozer.fun'])
            ->getJson('/api/menu/dishes')
            ->assertNotFound();
    }

    public function test_slug_routes_remain_backward_compatible_even_when_host_is_unknown(): void
    {
        $restaurant = $this->createRestaurant('legacy-slug');
        $this->createDish($restaurant, 'Legacy Dish');

        $response = $this->withHeaders(['Host' => 'gamma.rozer.fun'])
            ->getJson("/api/menu/{$restaurant->slug}/dishes");

        $response->assertOk()
            ->assertJsonPath('restaurant.slug', $restaurant->slug);
    }

    public function test_table_context_and_guest_orders_are_isolated_per_host_tenant(): void
    {
        $alpha = $this->createRestaurant('alpha-kitchen');
        $sigma = $this->createRestaurant('sigma-kitchen');
        $this->attachDomain($alpha, 'alpha.rozer.fun');
        $this->attachDomain($sigma, 'sigma.rozer.fun');

        $alphaDish = $this->createDish($alpha, 'Alpha Burger');
        $this->createDish($sigma, 'Sigma Sushi');

        $this->withHeaders(['Host' => 'alpha.rozer.fun'])
            ->getJson('/api/menu/table/1')
            ->assertOk()
            ->assertJsonPath('restaurant.id', $alpha->id);

        Sanctum::actingAs($alpha->user);
        $this->postJson('/api/table-sessions/activate', [
            'table_id' => $alpha->tables()->orderBy('name')->firstOrFail()->id,
        ])->assertOk();

        $pin = $this->activeSessionPin();

        $verify = $this->withHeaders([
            'Host' => 'alpha.rozer.fun',
            'X-Guest-Device-Id' => 'tenant-domain-test-device',
        ])->postJson('/api/menu/table/1/verify-pin', [
            'pin' => $pin,
        ]);

        $verify->assertOk();
        $token = (string) $verify->json('guest_access.token');

        $this->withHeaders([
            'Host' => 'alpha.rozer.fun',
            'X-Guest-Device-Id' => 'tenant-domain-test-device',
            'X-Guest-Access-Token' => $token,
        ])->postJson('/api/menu/orders', [
            'table_reference' => 'T01',
            'items' => [
                [
                    'dish_id' => $alphaDish->id,
                    'quantity' => 1,
                ],
            ],
        ])->assertCreated();

        $this->withHeaders([
            'Host' => 'sigma.rozer.fun',
            'X-Guest-Device-Id' => 'tenant-domain-test-device',
            'X-Guest-Access-Token' => $token,
        ])->postJson('/api/menu/orders', [
            'table_reference' => 'T01',
            'items' => [
                [
                    'dish_id' => $alphaDish->id,
                    'quantity' => 1,
                ],
            ],
        ])->assertForbidden();
    }

    public function test_host_based_guest_menu_resolves_to_restaurant_custom_domain_column(): void
    {
        $restaurant = $this->createRestaurant('custom-domain-kitchen');
        $restaurant->update([
            'custom_domain' => 'custom.example.com',
        ]);
        $this->createDish($restaurant, 'Custom Domain Dish');

        $response = $this->withHeaders(['Host' => 'custom.example.com'])
            ->getJson('/api/menu/dishes');

        $response->assertOk()
            ->assertJsonPath('restaurant.id', $restaurant->id)
            ->assertJsonPath('restaurant.slug', $restaurant->slug);
    }

    public function test_host_based_guest_menu_resolves_www_variant_of_restaurant_custom_domain(): void
    {
        $restaurant = $this->createRestaurant('custom-www-kitchen');
        $restaurant->update([
            'custom_domain' => 'custom-www.example.com',
        ]);
        $this->createDish($restaurant, 'Custom WWW Dish');

        $response = $this->withHeaders(['Host' => 'www.custom-www.example.com'])
            ->getJson('/api/menu/dishes');

        $response->assertOk()
            ->assertJsonPath('restaurant.id', $restaurant->id)
            ->assertJsonPath('restaurant.slug', $restaurant->slug);
    }

    private function createRestaurant(string $slug): Restaurant
    {
        $owner = User::factory()->admin()->create();

        $restaurant = Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'name' => Str::headline($slug),
            'slug' => $slug,
            'description' => 'Tenant routing test restaurant',
            'address' => 'Beirut',
        ]);

        foreach (['qr_menu', 'table_ordering', 'waiter_call', 'custom_domain'] as $featureKey) {
            $this->enableFeature($restaurant, $featureKey);
        }

        return $restaurant;
    }

    private function attachDomain(Restaurant $restaurant, string $domain, string $kind = 'subdomain'): void
    {
        RestaurantDomain::query()->create([
            'restaurant_id' => $restaurant->id,
            'domain' => $domain,
            'kind' => $kind,
            'is_primary' => true,
            'verified_at' => now(),
        ]);
    }

    private function createDish(Restaurant $restaurant, string $name): Dish
    {
        return Dish::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => $name,
            'description' => 'Published dish for tenant routing tests',
            'price' => 12.5,
            'category' => 'Main',
            'status' => 'published',
        ]);
    }

    private function activeSessionPin(): string
    {
        $session = TableSession::query()->latest('id')->firstOrFail();
        $pin = app(GuestMenuSessionService::class)->currentPlainPin($session);

        $this->assertIsString($pin);

        return $pin;
    }

    private function enableFeature(Restaurant $restaurant, string $key): void
    {
        $feature = Feature::query()->updateOrCreate(
            ['key' => $key],
            [
                'name' => Str::title(str_replace('_', ' ', $key)),
                'description' => 'Tenant routing test feature',
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
