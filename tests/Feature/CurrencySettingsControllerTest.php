<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CurrencySettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_restaurant_member_admin_can_update_currency_settings(): void
    {
        $owner = User::factory()->create();
        $memberAdmin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
        ]);

        $restaurant = Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'name' => 'Currency Settings Restaurant',
            'slug' => 'currency-settings-'.Str::lower(Str::random(6)),
            'currency' => 'USD',
            'other_currency' => 'LBP',
            'dollar_rate' => 1,
        ]);

        $restaurant->staffUsers()->attach($memberAdmin->id);

        Sanctum::actingAs($memberAdmin);

        $response = $this->patchJson('/api/restaurant/currency-settings', [
            'currency' => 'USD',
            'other_currency' => 'LBP',
            'dollar_rate' => 89500,
        ]);

        $response->assertOk()
            ->assertJsonPath('currency', 'USD')
            ->assertJsonPath('other_currency', 'LBP')
            ->assertJsonPath('dollar_rate', '1.00');

        $restaurant->refresh();

        $this->assertSame('USD', $restaurant->currency);
        $this->assertSame('LBP', $restaurant->other_currency);
        $this->assertSame('1.00', (string) $restaurant->dollar_rate);
    }
}
