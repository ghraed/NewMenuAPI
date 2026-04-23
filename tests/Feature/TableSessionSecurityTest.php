<?php

namespace Tests\Feature;

use App\Events\TableWaveCreated;
use App\Models\Dish;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\TableSession;
use App\Models\User;
use App\Services\GuestMenuSessionService;
use Illuminate\Support\Facades\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TableSessionSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_table_page_loads_in_view_only_mode_without_auto_session_activation(): void
    {
        $restaurant = $this->createRestaurant();
        $this->createDish($restaurant, 'Session Burger', 12.50);

        $response = $this->getJson('/api/menu/table/2');

        $response->assertOk()
            ->assertJsonPath('table.number', 2)
            ->assertJsonPath('guest_access.verified', false)
            ->assertJsonPath('protected_actions.ordering_unlocked', false)
            ->assertJsonPath('table_session', null);

        $this->assertSame(0, TableSession::query()->count());
    }

    public function test_correct_pin_unlocks_guest_access_and_protected_ordering(): void
    {
        $restaurant = $this->createRestaurant();
        $dish = $this->createDish($restaurant, 'PIN Burger', 14.00);

        $session = $this->openGuestTable(1);
        $token = $this->verifyCurrentTablePin(1, $this->activeSessionPin());

        $response = $this->postJson("/api/table-session/{$session->id}/order", [
            'items' => [
                ['dish_id' => $dish->id, 'quantity' => 2],
            ],
        ], $this->guestHeaders($token));

        $response->assertCreated()
            ->assertJsonPath('order.table_session_id', $session->id)
            ->assertJsonPath('order.table_reference', 'T01');

        $this->assertDatabaseHas('orders', [
            'table_session_id' => $session->id,
            'restaurant_table_id' => $session->restaurant_table_id,
            'status' => Order::STATUS_PENDING_STAFF_CONFIRMATION,
        ]);
    }

    public function test_wrong_pin_attempts_increment_and_lock_after_five_attempts(): void
    {
        $this->createRestaurant();
        $this->openGuestTable(1);

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->postJson('/api/menu/table/1/verify-pin', [
                'pin' => '9999',
            ], $this->guestHeaders())
                ->assertStatus(422);
        }

        $fifthAttempt = $this->postJson('/api/menu/table/1/verify-pin', [
            'pin' => '9999',
        ], $this->guestHeaders());

        $fifthAttempt->assertStatus(423);

        $session = TableSession::query()->firstOrFail();

        $this->assertSame(5, $session->pin_attempts);
        $this->assertNotNull($session->pin_locked_until);
    }

    public function test_lockout_blocks_until_timeout_and_then_allows_correct_pin(): void
    {
        $this->createRestaurant();
        $this->openGuestTable(1);
        $correctPin = $this->activeSessionPin();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/menu/table/1/verify-pin', [
                'pin' => '9999',
            ], $this->guestHeaders());
        }

        $this->postJson('/api/menu/table/1/verify-pin', [
            'pin' => $correctPin,
        ], $this->guestHeaders())->assertStatus(423);

        $this->travel(11)->minutes();

        $this->postJson('/api/menu/table/1/verify-pin', [
            'pin' => $correctPin,
        ], $this->guestHeaders())
            ->assertOk()
            ->assertJsonPath('guest_access.verified', true);
    }

    public function test_protected_endpoint_without_authorization_fails(): void
    {
        $restaurant = $this->createRestaurant();
        $dish = $this->createDish($restaurant, 'Blocked Burger', 11.00);
        $session = $this->openGuestTable(1);

        $this->postJson("/api/table-session/{$session->id}/order", [
            'items' => [
                ['dish_id' => $dish->id, 'quantity' => 1],
            ],
        ])->assertForbidden();
    }

    public function test_finalize_revokes_guest_access_and_blocks_future_actions(): void
    {
        $restaurant = $this->createRestaurant();
        $dish = $this->createDish($restaurant, 'Finalize Burger', 13.00);
        $admin = $restaurant->user;
        $session = $this->openGuestTable(1);
        $token = $this->verifyCurrentTablePin(1, $this->activeSessionPin());

        Sanctum::actingAs($admin);
        $this->postJson("/api/table-sessions/{$session->id}/finalize")
            ->assertOk()
            ->assertJsonPath('table_session.status', TableSession::STATUS_CLOSED);

        $this->postJson("/api/table-session/{$session->id}/order", [
            'items' => [
                ['dish_id' => $dish->id, 'quantity' => 1],
            ],
        ], $this->guestHeaders($token))->assertStatus(403);
    }

    public function test_call_waiter_dispatches_realtime_table_wave_event(): void
    {
        Event::fake([TableWaveCreated::class]);

        $this->createRestaurant();
        $session = $this->openGuestTable(1);
        $token = $this->verifyCurrentTablePin(1, $this->activeSessionPin());

        $response = $this->postJson(
            "/api/table-session/{$session->id}/call-waiter",
            [],
            $this->guestHeaders($token)
        );

        $response->assertCreated()
            ->assertJsonPath('wave.request_type', 'call_waiter');

        Event::assertDispatched(TableWaveCreated::class, function (TableWaveCreated $event) use ($session) {
            return $event->wave->table_session_id === $session->id
                && $event->payload['request_type'] === 'call_waiter';
        });
    }

    public function test_request_bill_dispatches_realtime_table_wave_event_with_bill_type(): void
    {
        Event::fake([TableWaveCreated::class]);

        $this->createRestaurant();
        $session = $this->openGuestTable(1);
        $token = $this->verifyCurrentTablePin(1, $this->activeSessionPin());

        $response = $this->postJson(
            "/api/table-session/{$session->id}/request-bill",
            [],
            $this->guestHeaders($token)
        );

        $response->assertCreated()
            ->assertJsonPath('wave.request_type', 'request_bill');

        Event::assertDispatched(TableWaveCreated::class, function (TableWaveCreated $event) use ($session) {
            return $event->wave->table_session_id === $session->id
                && $event->payload['request_type'] === 'request_bill';
        });
    }

    public function test_staff_can_activate_a_table_and_receive_the_live_pin(): void
    {
        $restaurant = $this->createRestaurant();
        $admin = $restaurant->user;

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/table-sessions/activate', [
            'table_id' => $restaurant->tables()->orderBy('name')->firstOrFail()->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('table_session.status', TableSession::STATUS_ACTIVE)
            ->assertJsonPath('table_session.table_id', 1);

        $this->assertIsString($response->json('current_pin'));
        $this->assertSame(1, TableSession::query()->count());
    }

    public function test_reset_pin_invalidates_old_pin_and_old_guest_access(): void
    {
        $restaurant = $this->createRestaurant();
        $dish = $this->createDish($restaurant, 'Reset Burger', 15.00);
        $admin = $restaurant->user;
        $session = $this->openGuestTable(1);
        $oldPin = $this->activeSessionPin();
        $token = $this->verifyCurrentTablePin(1, $oldPin);

        $this->postJson("/api/table-session/{$session->id}/order", [
            'items' => [
                ['dish_id' => $dish->id, 'quantity' => 1],
            ],
        ], $this->guestHeaders($token))->assertCreated();

        Sanctum::actingAs($admin);
        $resetResponse = $this->postJson("/api/table-sessions/{$session->id}/reset-pin");

        $resetResponse->assertOk();
        $newPin = $resetResponse->json('current_pin');

        $this->assertIsString($newPin);
        $this->assertNotSame($oldPin, $newPin);

        $this->postJson('/api/menu/table/1/verify-pin', [
            'pin' => $oldPin,
        ], $this->guestHeaders())->assertStatus(422);

        $this->postJson("/api/table-session/{$session->id}/call-waiter", [], $this->guestHeaders($token))
            ->assertStatus(403);

        $this->assertSame(1, Order::query()->where('table_session_id', $session->id)->count());

        $this->postJson('/api/menu/table/1/verify-pin', [
            'pin' => $newPin,
        ], $this->guestHeaders())
            ->assertOk()
            ->assertJsonPath('guest_access.verified', true);
    }

    public function test_verified_guest_can_view_orders_for_the_active_table_session(): void
    {
        $restaurant = $this->createRestaurant();
        $dish = $this->createDish($restaurant, 'History Burger', 16.00);
        $session = $this->openGuestTable(1);
        $token = $this->verifyCurrentTablePin(1, $this->activeSessionPin());

        $this->postJson("/api/table-session/{$session->id}/order", [
            'items' => [
                ['dish_id' => $dish->id, 'quantity' => 2],
            ],
        ], $this->guestHeaders($token))->assertCreated();

        $response = $this->getJson("/api/table-session/{$session->id}/orders", $this->guestHeaders($token));

        $response->assertOk()
            ->assertJsonCount(1, 'orders')
            ->assertJsonPath('orders.0.table_session_id', $session->id)
            ->assertJsonPath('orders.0.items.0.dish_id', $dish->id)
            ->assertJsonPath('orders.0.items.0.quantity', 2);
    }

    public function test_old_session_pin_cannot_unlock_a_new_session_for_the_same_table(): void
    {
        $restaurant = $this->createRestaurant();
        $admin = $restaurant->user;
        $firstSession = $this->openGuestTable(1);
        $oldPin = $this->activeSessionPin();

        Sanctum::actingAs($admin);
        $this->postJson("/api/table-sessions/{$firstSession->id}/finalize")->assertOk();

        $secondSession = $this->openGuestTable(1);
        $newPin = $this->activeSessionPin();

        $this->assertNotSame($firstSession->id, $secondSession->id);
        $this->assertNotSame($oldPin, $newPin);

        $this->postJson('/api/menu/table/1/verify-pin', [
            'pin' => $oldPin,
        ], $this->guestHeaders())->assertStatus(422);

        $this->postJson('/api/menu/table/1/verify-pin', [
            'pin' => $newPin,
        ], $this->guestHeaders())
            ->assertOk()
            ->assertJsonPath('table_session.id', $secondSession->id);
    }

    public function test_revisiting_same_table_reuses_the_same_active_session_until_it_is_closed(): void
    {
        $this->createRestaurant();
        $this->openGuestTable(3);

        $firstResponse = $this->getJson('/api/menu/table/3');
        $secondResponse = $this->getJson('/api/menu/table/3');

        $firstResponse->assertOk();
        $secondResponse->assertOk();
        $this->assertSame(
            $firstResponse->json('table_session.id'),
            $secondResponse->json('table_session.id')
        );
        $this->assertSame(1, TableSession::query()->count());
    }

    private function createRestaurant(?User $owner = null): Restaurant
    {
        $user = $owner ?? User::factory()->admin()->create();

        $restaurant = Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'name' => 'Secure Table '.Str::upper(Str::random(3)),
            'slug' => 'secure-table-'.Str::lower(Str::random(8)),
            'description' => 'Secure table test restaurant',
            'address' => 'Beirut',
        ]);

        config(['app.guest_restaurant_slug' => $restaurant->slug]);

        return $restaurant->fresh('tables', 'user');
    }

    private function createDish(Restaurant $restaurant, string $name, float $price): Dish
    {
        return Dish::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => $name,
            'description' => 'Guest-facing secure dish',
            'price' => $price,
            'category' => 'Main',
            'status' => 'published',
        ]);
    }

    private function openGuestTable(int $tableNumber): TableSession
    {
        $restaurant = Restaurant::query()
            ->where('slug', config('app.guest_restaurant_slug'))
            ->with('user')
            ->firstOrFail();

        $table = $restaurant->tables()->orderBy('name')->get()->values()->get($tableNumber - 1);
        $this->assertNotNull($table);

        Sanctum::actingAs($restaurant->user);
        $this->postJson('/api/table-sessions/activate', [
            'table_id' => $table->id,
        ])->assertOk();

        return TableSession::query()
            ->where('table_number', $tableNumber)
            ->latest('id')
            ->firstOrFail();
    }

    private function activeSessionPin(): string
    {
        $session = TableSession::query()->latest('id')->firstOrFail();
        $pin = app(GuestMenuSessionService::class)->currentPlainPin($session);

        $this->assertIsString($pin);

        return $pin;
    }

    private function verifyCurrentTablePin(int $tableNumber, string $pin): string
    {
        $response = $this->postJson("/api/menu/table/{$tableNumber}/verify-pin", [
            'pin' => $pin,
        ], $this->guestHeaders());

        $response->assertOk();

        return (string) $response->json('guest_access.token');
    }

    private function guestHeaders(?string $token = null): array
    {
        return array_filter([
            'X-Guest-Device-Id' => 'test-device-1',
            'X-Guest-Access-Token' => $token,
        ]);
    }
}
