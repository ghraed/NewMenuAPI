<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\Feature;
use App\Models\Restaurant;
use App\Models\RestaurantFeature;
use App\Models\RestaurantTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicMenuApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_slug_menu_returns_only_published_dishes_with_arabic_localization_and_tenant_isolation(): void
    {
        $restaurant = $this->createRestaurant('alpha-menu');
        $otherRestaurant = $this->createRestaurant('beta-menu');
        $this->enableFeature($restaurant, 'qr_menu');
        $this->enableFeature($otherRestaurant, 'qr_menu');

        Dish::factory()->for($restaurant)->published()->create([
            'name' => 'Mixed Grill',
            'name_ar' => 'مشاوي مشكلة',
            'description' => 'Sharing platter',
            'description_ar' => 'طبق للمشاركة',
            'category' => 'Main Courses',
            'category_ar' => 'الأطباق الرئيسية',
            'price' => 18.5,
        ]);
        Dish::factory()->for($restaurant)->create([
            'name' => 'Hidden Draft',
            'category' => 'Main Courses',
            'status' => 'draft',
        ]);
        Dish::factory()->for($otherRestaurant)->published()->create([
            'name' => 'Other Tenant Dish',
            'category' => 'Main Courses',
        ]);

        $response = $this->withHeaders(['Accept-Language' => 'ar'])
            ->getJson("/api/menu/{$restaurant->slug}/dishes");

        $response->assertOk()
            ->assertJsonPath('restaurant.slug', $restaurant->slug)
            ->assertJsonCount(1, 'dishes')
            ->assertJsonPath('dishes.0.name', 'مشاوي مشكلة')
            ->assertJsonPath('dishes.0.description', 'طبق للمشاركة')
            ->assertJsonPath('dishes.0.category', 'الأطباق الرئيسية');
    }

    public function test_public_menu_marks_unavailable_published_dishes_as_out_of_stock(): void
    {
        $restaurant = $this->createRestaurant('stock-menu');
        $this->enableFeature($restaurant, 'qr_menu');

        Dish::factory()->for($restaurant)->published()->create([
            'name' => 'Sparkling Water',
            'category' => 'Drinks',
            'item_type' => Dish::ITEM_TYPE_PACKAGED_DRINK,
            'packaged_unit' => 'bottle',
            'packaged_stock_quantity' => '0.000',
        ]);

        $response = $this->getJson("/api/menu/{$restaurant->slug}/dishes");

        $response->assertOk()
            ->assertJsonPath('dishes.0.name', 'Sparkling Water')
            ->assertJsonPath('dishes.0.is_orderable', false)
            ->assertJsonPath('dishes.0.is_out_of_stock', true);
    }

    public function test_public_menu_supports_empty_and_large_paginated_catalogs(): void
    {
        $emptyRestaurant = $this->createRestaurant('empty-menu');
        $this->enableFeature($emptyRestaurant, 'qr_menu');

        $emptyResponse = $this->getJson("/api/menu/{$emptyRestaurant->slug}/dishes?include_dishes=page&limit=20&offset=0&include_index=1");

        $emptyResponse->assertOk()
            ->assertJsonPath('restaurant.slug', $emptyRestaurant->slug)
            ->assertJsonPath('dishes_meta.total', 0)
            ->assertJsonCount(0, 'dishes_page')
            ->assertJsonCount(0, 'dish_index');

        $largeRestaurant = $this->createRestaurant('large-menu');
        $this->enableFeature($largeRestaurant, 'qr_menu');

        foreach (range(1, 25) as $index) {
            Dish::factory()->for($largeRestaurant)->published()->create([
                'name' => sprintf('Dish %02d', $index),
                'category' => 'Main Courses',
            ]);
        }

        $largeResponse = $this->getJson("/api/menu/{$largeRestaurant->slug}/dishes?include_dishes=page&limit=20&offset=0&include_index=1");

        $largeResponse->assertOk()
            ->assertJsonPath('dishes_meta.total', 25)
            ->assertJsonPath('dishes_meta.limit', 20)
            ->assertJsonPath('dishes_meta.offset', 0)
            ->assertJsonPath('dishes_meta.has_more', true)
            ->assertJsonPath('dishes_meta.next_offset', 20)
            ->assertJsonCount(20, 'dishes_page')
            ->assertJsonCount(25, 'dish_index');
    }

    public function test_public_menu_slug_and_table_routes_handle_invalid_slug_and_inactive_tables(): void
    {
        $restaurant = $this->createRestaurant('table-menu');
        $this->enableFeature($restaurant, 'qr_menu');

        $restaurant->tables()->where('name', 'T01')->update([
            'is_active' => true,
            'seats' => 8,
        ]);
        $restaurant->tables()->where('name', 'T02')->update([
            'is_active' => false,
            'seats' => 8,
        ]);
        $restaurant->tables()->where('name', 'T03')->update([
            'is_active' => true,
            'seats' => 8,
        ]);
        $restaurant->tables()->whereNotIn('name', ['T01', 'T02', 'T03'])->update([
            'is_active' => false,
        ]);

        Dish::factory()->for($restaurant)->published()->create([
            'name' => 'Table Dish',
            'category' => 'Main Courses',
        ]);

        $invalidSlug = $this->getJson('/api/menu/missing-slug/dishes');
        $invalidSlug->assertNotFound();

        $tablesResponse = $this->getJson("/api/menu/{$restaurant->slug}/tables");
        $tablesResponse->assertOk();

        $tableNames = collect($tablesResponse->json('tables'))->pluck('name')->all();
        $this->assertSame(['T01', 'T03'], $tableNames);
    }

    public function test_public_dish_detail_route_supports_direct_slug_access(): void
    {
        $restaurant = $this->createRestaurant('direct-dish');
        $this->enableFeature($restaurant, 'qr_menu');

        $dish = Dish::factory()->for($restaurant)->published()->create([
            'name' => 'Direct Dish',
            'description' => 'Visible from direct URL',
            'category' => 'Main Courses',
            'image_url' => null,
        ]);

        $response = $this->getJson("/api/menu/{$restaurant->slug}/dish/{$dish->id}");

        $response->assertOk()
            ->assertJsonPath('id', $dish->id)
            ->assertJsonPath('restaurant.slug', $restaurant->slug)
            ->assertJsonPath('image_url', null);
    }

    private function createRestaurant(string $slug): Restaurant
    {
        return Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory()->admin()->create()->id,
            'name' => Str::headline($slug),
            'slug' => $slug,
            'status' => 'active',
            'description' => 'Public menu test restaurant',
            'address' => 'Beirut',
            'currency' => 'USD',
            'other_currency' => 'LBP',
            'dollar_rate' => '1.00',
            'profile' => ['menu_categories' => ['Main Courses', 'Drinks']],
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
