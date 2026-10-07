<?php

namespace Tests\Feature;

use App\Models\EventReservation;
use App\Models\Feature;
use App\Models\Invoice;
use App\Models\Restaurant;
use App\Models\RestaurantFeature;
use App\Models\SuperAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\BuildsRestaurantOrderFlow;
use Tests\TestCase;

class RestaurantActivePolicyTest extends TestCase
{
    use BuildsRestaurantOrderFlow;
    use RefreshDatabase;

    private function tenant(string $status = 'active'): Restaurant
    {
        $prefix = 'QA_RUN_'.(getenv('QA_RUN_ID') ?: 'policy').'_'.bin2hex(random_bytes(4));
        $owner = User::factory()->admin()->create(['name' => $prefix, 'email' => $prefix.'@example.invalid']);

        return $this->createRestaurant($owner, ['table_reservations', 'event_reservations', 'custom_domain', 'ai_chatbot'], [
            'name' => $prefix, 'slug' => strtolower($prefix), 'status' => $status,
        ]);
    }

    public function test_old_token_cannot_create_sale_but_can_read_and_login_and_reactivation_restores_sales(): void
    {
        $restaurant = $this->tenant();
        $dish = $this->createDish($restaurant, $restaurant->name.'_dish', 10);
        $token = $restaurant->user->createToken('qa-policy')->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token];
        $payload = ['payment_method' => 'cash', 'items' => [['dish_id' => $dish->id, 'quantity' => 1]]];
        $restaurant->update(['status' => 'inactive']);
        $this->postJson('/api/pos/checkout', $payload, $headers)->assertForbidden()->assertJsonPath('code', 'restaurant_inactive');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->getJson('/api/auth/me', $headers)->assertOk();
        $this->postJson('/api/auth/login', ['email' => $restaurant->user->email, 'password' => 'password'])->assertOk();
        $restaurant->update(['status' => 'active']);
        $this->postJson('/api/pos/checkout', $payload, $headers)->assertCreated();
        $this->assertDatabaseCount('orders', 1);
    }

    public static function newCommerceRoutes(): array
    {
        return [
            'POS' => ['/api/pos/checkout', true],
            'new table session' => ['/api/table-sessions/activate', true],
            'admin reservation' => ['/api/admin/reservations', true],
            'event reservation' => ['/api/admin/events', true],
            'manual sale invoice' => ['/api/admin/finance/invoices', true],
            'public reservation' => ['/api/reservations', false],
            'host guest order' => ['/api/menu/orders', false],
            'slug guest order' => ['/api/menu/{slug}/orders', false],
            'chat order' => ['/api/chat/orders', false],
        ];
    }

    #[DataProvider('newCommerceRoutes')]
    public function test_new_commerce_is_blocked_before_validation(string $path, bool $staff): void
    {
        $restaurant = $this->tenant('inactive');
        if ($staff) {
            Sanctum::actingAs($restaurant->user);
        }
        $path = str_replace('{slug}', $restaurant->slug, $path);
        $this->postJson($path, [])->assertForbidden()->assertJsonPath('code', 'restaurant_inactive');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('table_sessions', 0);
    }

    public function test_guest_menu_remains_visible_by_slug_host_and_table_and_flags_still_apply(): void
    {
        $restaurant = $this->tenant('inactive');
        $restaurant->update(['custom_domain' => 'qa-policy.example.invalid']);
        $this->createDish($restaurant, $restaurant->name.'_dish', 10);
        $this->getJson('/api/menu/'.$restaurant->slug.'/dishes')->assertOk();
        $this->getJson('http://qa-policy.example.invalid/api/menu/dishes')->assertOk()->assertJsonPath('restaurant.id', $restaurant->id);
        $this->getJson('/api/menu/table/1')->assertOk()->assertJsonPath('restaurant.id', $restaurant->id);
        $this->postJson('http://qa-policy.example.invalid/api/menu/orders', [])->assertForbidden()->assertJsonPath('code', 'restaurant_inactive');
        $qr = Feature::where('key', 'qr_menu')->firstOrFail();
        RestaurantFeature::where('restaurant_id', $restaurant->id)->where('feature_id', $qr->id)->update(['enabled' => false]);
        $this->getJson('/api/menu/'.$restaurant->slug.'/dishes')->assertNotFound();
    }

    public function test_existing_guest_session_can_read_and_request_bill_but_cannot_add_orders_and_staff_can_finish_invoice(): void
    {
        $restaurant = $this->tenant();
        $dish = $this->createDish($restaurant, $restaurant->name.'_dish', 10);
        ['session' => $session, 'token' => $token] = $this->openGuestAccess($restaurant, 1);
        $payload = ['items' => [['dish_id' => $dish->id, 'quantity' => 1]]];
        $order = $this->postJson('/api/table-session/'.$session->id.'/order', $payload, $this->guestHeaders($token))->assertCreated()->json('order.id');
        $restaurant->update(['status' => 'inactive']);
        $this->postJson('/api/table-session/'.$session->id.'/order', $payload, $this->guestHeaders($token))->assertForbidden()->assertJsonPath('code', 'restaurant_inactive');
        $this->getJson('/api/table-session/'.$session->id.'/orders', $this->guestHeaders($token))->assertOk();
        $this->postJson('/api/table-session/'.$session->id.'/heartbeat', [], $this->guestHeaders($token))->assertOk();
        $this->postJson('/api/table-session/'.$session->id.'/request-bill', [], $this->guestHeaders($token))->assertCreated();
        Sanctum::actingAs($restaurant->user);
        $this->postJson('/api/orders/'.$order.'/confirm')->assertOk();
        $this->postJson('/api/kitchen/orders/'.$order.'/start')->assertOk();
        $this->postJson('/api/kitchen/orders/'.$order.'/ready')->assertOk();
        $this->postJson('/api/orders/'.$order.'/served')->assertOk();
        $this->postJson('/api/orders/'.$order.'/account')->assertOk();
        $invoice = $this->postJson('/api/table-sessions/'.$session->id.'/finalize', ['payment_method' => 'cash', 'payment_reference' => $restaurant->name.'_payment'])->assertOk()->json('invoice_id');
        $this->getJson('/api/admin/finance/invoices/'.$invoice)->assertOk()->assertJsonPath('invoice.status', Invoice::STATUS_PAID)->assertJsonPath('invoice.total', '10.00');
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_another_active_tenant_and_super_admin_recovery_are_unaffected(): void
    {
        $inactive = $this->tenant('inactive');
        $active = $this->tenant();
        $dish = $this->createDish($active, $active->name.'_dish', 10);
        Sanctum::actingAs($active->user);
        $this->postJson('/api/pos/checkout', ['payment_method' => 'cash', 'items' => [['dish_id' => $dish->id, 'quantity' => 1]]])->assertCreated();
        $super = User::factory()->saasOwner()->create(['name' => $active->name.'_super', 'email' => $active->name.'_super@example.invalid']);
        SuperAdmin::create(['name' => $super->name, 'email' => $super->email, 'password' => $super->password]);
        Sanctum::actingAs($super);
        $this->getJson('/api/super-admin/restaurants')->assertOk();
        $this->patchJson('/api/super-admin/restaurants/'.$inactive->id, ['name' => $inactive->name, 'slug' => $inactive->slug, 'status' => 'active', 'currency' => 'USD'])->assertOk();
        $this->assertSame('active', $inactive->fresh()->status);
    }

    public function test_existing_event_cannot_generate_new_order_and_pending_order_cannot_gain_items(): void
    {
        $restaurant = $this->tenant();
        $dish = $this->createDish($restaurant, $restaurant->name.'_dish', 10);
        ['session' => $session, 'token' => $token] = $this->openGuestAccess($restaurant, 1);
        $order = $this->postJson('/api/table-session/'.$session->id.'/order', ['items' => [['dish_id' => $dish->id, 'quantity' => 2]]], $this->guestHeaders($token))->assertCreated()->json('order.id');
        $event = EventReservation::create(['restaurant_id' => $restaurant->id, 'title' => $restaurant->name.'_event', 'customer_name' => $restaurant->name, 'customer_phone' => '0000000000', 'start_at' => now()->addDay(), 'end_at' => now()->addDay()->addHour(), 'status' => EventReservation::STATUS_CONFIRMED]);
        $restaurant->update(['status' => 'inactive']);
        Sanctum::actingAs($restaurant->user);
        $this->postJson('/api/admin/events/'.$event->id.'/generate-order-draft')->assertForbidden()->assertJsonPath('code', 'restaurant_inactive');
        $this->patchJson('/api/orders/'.$order, ['items' => [['dish_id' => $dish->id, 'quantity' => 3]]])->assertForbidden()->assertJsonPath('code', 'restaurant_inactive');
        $this->assertDatabaseHas('order_items', ['order_id' => $order, 'quantity' => 2]);
        $this->patchJson('/api/orders/'.$order, ['items' => [['dish_id' => $dish->id, 'quantity' => 1]]])->assertOk();
        $this->assertDatabaseHas('order_items', ['order_id' => $order, 'quantity' => 1]);
    }

    public function test_active_and_inactive_flags_and_role_permissions_remain_independent(): void
    {
        $restaurant = $this->tenant();
        $dish = $this->createDish($restaurant, $restaurant->name.'_dish', 10);
        $payload = ['payment_method' => 'cash', 'items' => [['dish_id' => $dish->id, 'quantity' => 1]]];
        Sanctum::actingAs($restaurant->user);
        $ordering = Feature::where('key', 'table_ordering')->firstOrFail();
        RestaurantFeature::where('restaurant_id', $restaurant->id)->where('feature_id', $ordering->id)->update(['enabled' => false]);
        $this->postJson('/api/pos/checkout', $payload)->assertNotFound();
        $restaurant->update(['status' => 'inactive']);
        $this->postJson('/api/pos/checkout', $payload)->assertNotFound();
        $this->getJson('/api/pos/compensation-report')->assertNotFound();
        $chef = User::factory()->chef()->attachedToRestaurant($restaurant)->create(['name' => $restaurant->name.'_chef']);
        Sanctum::actingAs($chef);
        $this->postJson('/api/pos/checkout', $payload)->assertForbidden();
        $this->assertDatabaseCount('orders', 0);
    }

    public static function staffRoles(): array
    {
        return [['staff'], ['chef'], ['accountant'], ['stockManager']];
    }

    #[DataProvider('staffRoles')]
    public function test_staff_roles_keep_login_and_existing_tokens_but_inactive_users_remain_denied(string $factoryRole): void
    {
        $restaurant = $this->tenant();
        $staff = User::factory()->{$factoryRole}()->attachedToRestaurant($restaurant)->create([
            'name' => $restaurant->name.'_staff', 'email' => $restaurant->name.'_staff@example.invalid',
        ]);
        $headers = ['Authorization' => 'Bearer '.$staff->createToken('qa-existing-staff')->plainTextToken];
        $restaurant->update(['status' => 'inactive']);
        $this->getJson('/api/auth/me', $headers)->assertOk()->assertJsonPath('user.id', $staff->id);
        $this->postJson('/api/auth/login', ['email' => $staff->email, 'password' => 'password'])->assertOk();
        $this->postJson('/api/pos/checkout', [], $headers)->assertForbidden();
        $staff->update(['is_active' => false]);
        $this->postJson('/api/auth/login', ['email' => $staff->email, 'password' => 'password'])->assertForbidden();
        // Feature-test requests share one container; real HTTP requests load the
        // token's user again. Discard the guard's earlier authenticated instance.
        Auth::forgetGuards();
        $this->getJson('/api/auth/me', $headers)->assertForbidden();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_guest_slug_policy_does_not_use_bearer_tenant_or_forged_restaurant_id(): void
    {
        $inactive = $this->tenant('inactive');
        $active = $this->tenant();
        $headers = ['Authorization' => 'Bearer '.$active->user->createToken('qa-active-guest')->plainTextToken];
        $this->postJson('/api/menu/'.$inactive->slug.'/orders', ['restaurant_id' => $active->id], $headers)
            ->assertForbidden()->assertJsonPath('code', 'restaurant_inactive');
        Sanctum::actingAs($inactive->user);
        $this->postJson('/api/pos/checkout', ['restaurant_id' => $active->id])
            ->assertForbidden()->assertJsonPath('code', 'restaurant_inactive');
        $this->assertDatabaseCount('orders', 0);
    }
}
