<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\AuthCredentialCookie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\BuildsRestaurantOrderFlow;
use Tests\TestCase;

class LegacyMergeBoundaryTest extends TestCase
{
    use BuildsRestaurantOrderFlow;
    use RefreshDatabase;

    public function test_a_changed_cookie_cannot_create_financial_records_for_a_stale_browser_identity(): void
    {
        $a = User::factory()->admin()->create(['name' => 'QA_RUN_'.getenv('QA_RUN_ID').'_cookie_A']);
        $b = User::factory()->admin()->create(['name' => 'QA_RUN_'.getenv('QA_RUN_ID').'_cookie_B']);
        $ra = $this->createRestaurant($a);
        $rb = $this->createRestaurant($b);
        $login = $this->postJson('/api/auth/login', ['email' => $b->email, 'password' => 'password'], ['X-Rozer-Auth-Mode' => 'cookie-v1'])->assertOk();
        $cookie = collect($login->headers->getCookies())->first(fn ($value) => $value->getName() === AuthCredentialCookie::RESTAURANT);
        $this->assertNotNull($cookie);
        $this->withCredentials()->withUnencryptedCookie(AuthCredentialCookie::RESTAURANT, (string) $cookie->getValue())
            ->postJson('/api/admin/finance/invoices', ['invoice_date' => now()->toDateString(), 'status' => 'issued', 'currency' => 'USD', 'items' => [['name' => 'QA_RUN_stale_browser_line', 'quantity' => 1, 'unit_price' => 10]]], [
                'X-Rozer-Auth-Mode' => 'cookie-v1', 'X-Rozer-Expected-User' => (string) $a->id, 'X-Rozer-Expected-Restaurant' => (string) $ra->id,
            ])->assertStatus(409);
        $this->assertSame(0, Invoice::query()->whereIn('restaurant_id', [$ra->id, $rb->id])->count());
    }

    public function test_staff_replay_rechecks_table_assignment_before_returning_a_saved_response(): void
    {
        $restaurant = $this->createRestaurant();
        $dish = $this->createDish($restaurant, 'QA_RUN_'.getenv('QA_RUN_ID').'_assigned', 10);
        $staff = $this->createStaffUser($restaurant, ['T01']);
        ['session' => $session, 'token' => $token] = $this->openGuestAccess($restaurant, 1);
        $order = $this->postJson("/api/table-session/{$session->id}/order", ['items' => [['dish_id' => $dish->id, 'quantity' => 1]]], $this->guestHeaders($token))->assertCreated()->json('order.id');
        \Laravel\Sanctum\Sanctum::actingAs($staff);
        $headers = ['X-Idempotency-Key' => 'QA_RUN_'.getenv('QA_RUN_ID').'_staff_replay'];
        $this->postJson("/api/orders/{$order}/confirm", [], $headers)->assertOk();
        $staff->assignedTables()->detach();
        $this->postJson("/api/orders/{$order}/confirm", [], $headers)->assertForbidden();
    }
}
