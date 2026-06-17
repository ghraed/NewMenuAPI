<?php

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\Restaurant;
use App\Models\RestaurantFeature;
use App\Models\User;
use App\Services\TenantRestaurantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantRestaurantResolverCustomDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolver_matches_restaurant_by_custom_domain_column(): void
    {
        $alpha = $this->createRestaurant('alpha-custom', 'alpha.example.com');
        $sigma = $this->createRestaurant('sigma-custom', 'sigma.example.com');

        $resolved = app(TenantRestaurantResolver::class)->resolveFromSlugOrHost(
            null,
            $this->requestForHost('https://sigma.example.com/api/menu/dishes', 'sigma.example.com')
        );

        $this->assertSame($sigma->id, $resolved->id);
        $this->assertNotSame($alpha->id, $resolved->id);
    }

    public function test_resolver_matches_www_host_to_base_custom_domain(): void
    {
        $restaurant = $this->createRestaurant('www-custom', 'brand.example.com');

        $resolved = app(TenantRestaurantResolver::class)->resolveFromSlugOrHost(
            null,
            $this->requestForHost('https://www.brand.example.com/api/menu/dishes', 'www.brand.example.com')
        );

        $this->assertSame($restaurant->id, $resolved->id);
    }

    private function createRestaurant(string $slug, string $customDomain): Restaurant
    {
        $owner = User::factory()->admin()->create();

        $restaurant = Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'name' => Str::headline($slug),
            'slug' => $slug,
            'status' => 'active',
            'currency' => 'USD',
            'custom_domain' => $customDomain,
            'description' => 'Resolver custom domain test restaurant',
            'address' => 'Beirut',
        ]);

        $feature = Feature::query()->updateOrCreate(
            ['key' => 'custom_domain'],
            [
                'name' => 'Custom Domain',
                'description' => 'Resolver test feature',
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

        return $restaurant;
    }

    private function requestForHost(string $uri, string $host): Request
    {
        return Request::create($uri, 'GET', [], [], [], [
            'HTTP_HOST' => $host,
        ]);
    }
}
