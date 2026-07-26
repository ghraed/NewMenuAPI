<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RestaurantConfigurationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_restaurant_profile_fields_and_preserve_menu_categories(): void
    {
        $admin = User::factory()->admin()->create();
        $restaurant = Restaurant::factory()->for($admin, 'user')->create([
            'name' => 'Original Name',
            'profile' => [
                'menu_categories' => ['Main Courses', 'Desserts'],
                'short_description' => 'Old summary',
            ],
        ]);

        Sanctum::actingAs($admin);

        $response = $this->patchJson('/api/restaurant/profile', [
            'name' => 'Updated Restaurant',
            'legal_business_name' => 'Updated Hospitality LLC',
            'primary_phone' => '+96170111222',
            'whatsapp_phone' => '+96170333444',
            'contact_email' => 'hello@example.com',
            'website_url' => 'https://updated.example.com',
            'address_line_1' => 'Hamra Street',
            'city' => 'Beirut',
            'country' => 'Lebanon',
            'tax_registration_number' => 'TAX-7788',
            'vat_registration_number' => 'VAT-9900',
            'service_hours' => 'Daily 9:00-23:00',
            'short_description' => 'Fresh menu with Arabic and English service.',
        ]);

        $response->assertOk()
            ->assertJsonPath('restaurant.name', 'Updated Restaurant')
            ->assertJsonPath('profile.legal_business_name', 'Updated Hospitality LLC')
            ->assertJsonPath('profile.primary_phone', '+96170111222')
            ->assertJsonPath('profile.contact_email', 'hello@example.com')
            ->assertJsonPath('profile.tax_registration_number', 'TAX-7788')
            ->assertJsonPath('restaurant.menu_categories.0', 'Main Courses')
            ->assertJsonPath('restaurant.menu_categories.1', 'Desserts');

        $restaurant->refresh();

        $this->assertSame('Updated Restaurant', $restaurant->name);
        $this->assertSame('Updated Hospitality LLC', $restaurant->profile['legal_business_name']);
        $this->assertSame('TAX-7788', $restaurant->profile['tax_registration_number']);
        $this->assertSame(['Main Courses', 'Desserts'], $restaurant->profile['menu_categories']);
    }

    public function test_admin_can_update_currency_settings_with_secondary_currency_and_exchange_rate(): void
    {
        $admin = User::factory()->admin()->create();
        $restaurant = Restaurant::factory()->for($admin, 'user')->create([
            'currency' => 'USD',
            'other_currency' => 'EUR',
            'dollar_rate' => '1.00',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->patchJson('/api/restaurant/currency-settings', [
            'currency' => 'USD',
            'other_currency' => 'LBP',
            'dollar_rate' => 89500.75,
        ]);

        $response->assertOk()
            ->assertJsonPath('currency', 'USD')
            ->assertJsonPath('other_currency', 'LBP')
            ->assertJsonPath('dollar_rate', '89500.75');

        $restaurant->refresh();

        $this->assertSame('USD', $restaurant->currency);
        $this->assertSame('LBP', $restaurant->other_currency);
        $this->assertSame('89500.75', $restaurant->dollar_rate);
    }

    public function test_currency_settings_reject_same_secondary_currency_and_non_positive_rate(): void
    {
        $admin = User::factory()->admin()->create();
        Restaurant::factory()->for($admin, 'user')->create([
            'currency' => 'USD',
            'other_currency' => 'EUR',
        ]);

        Sanctum::actingAs($admin);

        $sameCurrency = $this->patchJson('/api/restaurant/currency-settings', [
            'currency' => 'USD',
            'other_currency' => 'USD',
            'dollar_rate' => 1,
        ]);

        $sameCurrency->assertStatus(422)
            ->assertJsonValidationErrors(['other_currency']);

        $zeroRate = $this->patchJson('/api/restaurant/currency-settings', [
            'currency' => 'USD',
            'other_currency' => 'LBP',
            'dollar_rate' => 0,
        ]);

        $zeroRate->assertStatus(422)
            ->assertJsonValidationErrors(['dollar_rate']);
    }

    public function test_logo_upload_accepts_valid_images_and_rejects_invalid_files(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        $restaurant = Restaurant::factory()->for($admin, 'user')->create();

        Sanctum::actingAs($admin);

        $success = $this->postJson('/api/restaurant/profile/logo', [
            'logo' => UploadedFile::fake()->image('brand.png', 240, 240)->size(256),
        ]);

        $success->assertOk()
            ->assertJsonPath('restaurant.id', $restaurant->id);

        $restaurant->refresh();

        $this->assertNotNull($restaurant->logo_path);
        Storage::disk('public')->assertExists($restaurant->logo_path);

        $invalidType = $this->postJson('/api/restaurant/profile/logo', [
            'logo' => UploadedFile::fake()->create('brand.pdf', 100, 'application/pdf'),
        ]);

        $invalidType->assertStatus(422)
            ->assertJsonValidationErrors(['logo']);

        $oversized = $this->postJson('/api/restaurant/profile/logo', [
            'logo' => UploadedFile::fake()->image('oversized.jpg', 400, 400)->size(4096),
        ]);

        $oversized->assertStatus(422)
            ->assertJsonValidationErrors(['logo']);
    }

    public function test_profile_and_currency_routes_require_restaurant_context(): void
    {
        $admin = User::factory()->admin()->create();

        Sanctum::actingAs($admin);

        $profile = $this->getJson('/api/restaurant/profile');
        $profile->assertStatus(403);

        $currency = $this->patchJson('/api/restaurant/currency-settings', [
            'currency' => 'USD',
            'other_currency' => 'LBP',
            'dollar_rate' => 90000,
        ]);

        $currency->assertStatus(403);
    }

    public function test_restaurant_configuration_updates_are_isolated_per_tenant(): void
    {
        $adminA = User::factory()->admin()->create();
        $adminB = User::factory()->admin()->create();

        $restaurantA = Restaurant::factory()->for($adminA, 'user')->create([
            'name' => 'Tenant A',
            'currency' => 'USD',
            'other_currency' => 'EUR',
            'dollar_rate' => '1.00',
        ]);
        $restaurantB = Restaurant::factory()->for($adminB, 'user')->create([
            'name' => 'Tenant B',
            'currency' => 'USD',
            'other_currency' => 'LBP',
            'dollar_rate' => '90000.00',
        ]);

        Sanctum::actingAs($adminA);

        $profile = $this->getJson('/api/restaurant/profile');
        $profile->assertOk()
            ->assertJsonPath('restaurant.id', $restaurantA->id)
            ->assertJsonPath('restaurant.name', 'Tenant A');

        $this->patchJson('/api/restaurant/currency-settings', [
            'currency' => 'USD',
            'other_currency' => 'QAR',
            'dollar_rate' => 3.64,
        ])->assertOk();

        $restaurantA->refresh();
        $restaurantB->refresh();

        $this->assertSame('QAR', $restaurantA->other_currency);
        $this->assertSame('LBP', $restaurantB->other_currency);
        $this->assertSame('90000.00', $restaurantB->dollar_rate);
    }
}
