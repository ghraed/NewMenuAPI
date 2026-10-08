<?php

namespace Tests\Feature;

use App\Events\AccountingOrderCreated;
use App\Events\KitchenOrderCreated;
use App\Models\DishIngredient;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\User;
use App\Services\MobilePushNotificationService;
use App\Services\WebPushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\Feature\Concerns\BuildsRestaurantOrderFlow;
use Tests\TestCase;

class GuestOrderIdempotencyDurabilityTest extends TestCase
{
    use BuildsRestaurantOrderFlow;
    use RefreshDatabase;

    public function test_ambiguous_retry_survives_cache_loss_with_exactly_once_database_and_alert_effects(): void
    {
        $owner = User::factory()->admin()->create([
            'name' => 'QA_RUN_REL owner',
            'email' => 'qa_run_rel_owner_'.Str::lower(Str::random(8)).'@example.test',
        ]);
        $restaurant = $this->createRestaurant($owner, ['ingredient_stock_deduction'], [
            'name' => 'QA_RUN_REL restaurant',
            'slug' => 'qa-run-rel-'.Str::lower(Str::random(8)),
        ]);
        $dish = $this->createDish($restaurant, 'QA_RUN_REL durable dish', 11.25);
        $ingredient = Ingredient::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => 'QA_RUN_REL durable ingredient',
            'stock_unit' => Ingredient::UNIT_GRAM,
            'current_stock_quantity' => '100.000',
            'low_stock_threshold' => '0.000',
            'is_active' => true,
        ]);
        DishIngredient::query()->create([
            'dish_id' => $dish->id,
            'ingredient_id' => $ingredient->id,
            'quantity' => '2.000',
            'unit' => Ingredient::UNIT_GRAM,
        ]);
        $staff = $this->createStaffUser($restaurant, ['T01']);
        ['session' => $session, 'token' => $token] = $this->openGuestAccess($restaurant, 1);

        $webPush = $this->mock(WebPushNotificationService::class);
        $webPush->shouldReceive('notifyPendingOrderCreated')->once()->andReturn(['delivered' => [], 'retryable' => []]);
        $mobilePush = $this->mock(MobilePushNotificationService::class);
        $mobilePush->shouldReceive('notifyPendingOrderCreated')->once()->andReturn(['delivered' => [], 'retryable' => []]);

        $payload = [
            'notes' => 'QA_RUN_REL response dropped after commit',
            'items' => [['dish_id' => $dish->id, 'quantity' => 2]],
        ];
        $headers = array_merge($this->guestHeaders($token), [
            'X-Idempotency-Key' => 'QA_RUN_REL-durable-key',
        ]);

        $first = $this->postJson("/api/table-session/{$session->id}/order", $payload, $headers);
        $first->assertCreated();

        // Represents a worker restart/cache eviction after the database commit but
        // before the client receives the response.
        Cache::flush();

        $retry = $this->postJson("/api/table-session/{$session->id}/order", $payload, $headers);
        $retry->assertOk()->assertJsonPath('order.id', $first->json('order.id'));

        $orderId = (int) $first->json('order.id');
        $this->assertSame(1, Order::query()->where('table_session_id', $session->id)->count());
        $this->assertSame(1, DB::table('order_items')->where('order_id', $orderId)->count());
        $this->assertSame(0, DB::table('stock_movements')->where('order_id', $orderId)->count());
        $this->assertSame(0, DB::table('order_item_ingredient_usages')->where('order_id', $orderId)->count());
        $this->assertSame(0, DB::table('invoices')->where('restaurant_id', $restaurant->id)->count());
        $this->assertSame(1, DB::table('guest_order_idempotencies')->where('table_session_id', $session->id)->count());

        Event::fake([KitchenOrderCreated::class, AccountingOrderCreated::class]);
        Sanctum::actingAs($staff);
        $this->postJson("/api/orders/{$orderId}/confirm")->assertOk();

        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredient->id,
            'current_stock_quantity' => '96.000',
        ]);
        $this->assertSame(1, DB::table('stock_movements')->where('order_id', $orderId)->count());
        $this->assertSame(1, DB::table('order_item_ingredient_usages')->where('order_id', $orderId)->count());
        $this->assertSame(1, DB::table('invoices')->where('restaurant_id', $restaurant->id)->count());
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'payment_method' => null,
            'payment_reference' => null,
        ]);
        $this->assertDatabaseHas('invoices', [
            'restaurant_id' => $restaurant->id,
            'status' => 'draft',
            'payment_method' => null,
            'payment_reference' => null,
        ]);
        Event::assertDispatchedTimes(KitchenOrderCreated::class, 1);
        Event::assertDispatchedTimes(AccountingOrderCreated::class, 1);
    }

    public function test_reusing_a_durable_key_for_a_different_payload_is_rejected_without_side_effects(): void
    {
        $owner = User::factory()->admin()->create([
            'name' => 'QA_RUN_REL conflict owner',
            'email' => 'qa_run_rel_conflict_'.Str::lower(Str::random(8)).'@example.test',
        ]);
        $restaurant = $this->createRestaurant($owner, attributes: [
            'name' => 'QA_RUN_REL conflict restaurant',
            'slug' => 'qa-run-rel-conflict-'.Str::lower(Str::random(8)),
        ]);
        $dish = $this->createDish($restaurant, 'QA_RUN_REL conflict dish', 9.50);
        ['session' => $session, 'token' => $token] = $this->openGuestAccess($restaurant, 1);
        $headers = array_merge($this->guestHeaders($token), [
            'X-Idempotency-Key' => 'QA_RUN_REL-conflict-key',
        ]);

        $this->postJson("/api/table-session/{$session->id}/order", [
            'items' => [['dish_id' => $dish->id, 'quantity' => 1]],
        ], $headers)->assertCreated();

        Cache::flush();

        $this->postJson("/api/table-session/{$session->id}/order", [
            'items' => [['dish_id' => $dish->id, 'quantity' => 2]],
        ], $headers)->assertConflict();

        $this->assertSame(1, Order::query()->where('table_session_id', $session->id)->count());
        $this->assertSame(1, DB::table('order_items')->whereIn(
            'order_id',
            Order::query()->where('table_session_id', $session->id)->select('id')
        )->count());
    }

    public function test_timeout_after_provider_accept_remains_retryable_and_uses_one_outbox_effect(): void
    {
        $owner = User::factory()->admin()->create([
            'name' => 'QA_RUN_REL outbox owner',
            'email' => 'qa_run_rel_outbox_'.Str::lower(Str::random(8)).'@example.test',
        ]);
        $restaurant = $this->createRestaurant($owner, attributes: [
            'name' => 'QA_RUN_REL outbox restaurant',
            'slug' => 'qa-run-rel-outbox-'.Str::lower(Str::random(8)),
        ]);
        $dish = $this->createDish($restaurant, 'QA_RUN_REL outbox dish', 7.00);
        ['session' => $session, 'token' => $token] = $this->openGuestAccess($restaurant, 1);
        $web = $this->mock(WebPushNotificationService::class);
        $web->shouldReceive('notifyPendingOrderCreated')->once()->andThrow(new \RuntimeException('QA_RUN_REL timeout after provider accept'));
        $web->shouldReceive('notifyPendingOrderCreated')->once()->andReturn(['delivered' => ['recipient-a'], 'retryable' => []]);
        $mobile = $this->mock(MobilePushNotificationService::class);
        $mobile->shouldReceive('notifyPendingOrderCreated')->once()->andReturn(['delivered' => ['recipient-b'], 'retryable' => []]);
        $headers = array_merge($this->guestHeaders($token), ['X-Idempotency-Key' => 'QA_RUN_REL-outbox-key']);
        $payload = ['items' => [['dish_id' => $dish->id, 'quantity' => 1]]];

        $first = $this->postJson("/api/table-session/{$session->id}/order", $payload, $headers)->assertCreated();
        $orderId = (int) $first->json('order.id');
        $this->assertDatabaseHas('order_alert_outboxes', [
            'order_id' => $orderId,
            'web_push_delivered_at' => null,
        ]);

        Artisan::call('orders:deliver-pending-alerts');
        $outbox = DB::table('order_alert_outboxes')->where('order_id', $orderId)->first();
        $this->assertNotNull($outbox->web_push_delivered_at);
        $this->assertNotNull($outbox->mobile_push_delivered_at);
        $this->assertSame(1, Order::query()->where('table_session_id', $session->id)->count());
    }

    public function test_partial_recipient_failure_retries_only_the_failed_recipient(): void
    {
        $owner = User::factory()->admin()->create(['name' => 'QA_RUN_REL partial owner']);
        $restaurant = $this->createRestaurant($owner, attributes: [
            'name' => 'QA_RUN_REL partial restaurant',
            'slug' => 'qa-run-rel-partial-'.Str::lower(Str::random(8)),
        ]);
        $dish = $this->createDish($restaurant, 'QA_RUN_REL partial dish', 7.00);
        ['session' => $session, 'token' => $token] = $this->openGuestAccess($restaurant, 1);
        $web = $this->mock(WebPushNotificationService::class);
        $web->shouldReceive('notifyPendingOrderCreated')->once()->with(Mockery::type(Order::class), null)
            ->andReturn(['delivered' => ['recipient-ok'], 'retryable' => ['recipient-retry']]);
        $web->shouldReceive('notifyPendingOrderCreated')->once()->with(Mockery::type(Order::class), ['recipient-retry'])
            ->andReturn(['delivered' => ['recipient-retry'], 'retryable' => []]);
        $mobile = $this->mock(MobilePushNotificationService::class);
        $mobile->shouldReceive('notifyPendingOrderCreated')->once()->andReturn(['delivered' => [], 'retryable' => []]);

        $response = $this->postJson("/api/table-session/{$session->id}/order", [
            'items' => [['dish_id' => $dish->id, 'quantity' => 1]],
        ], array_merge($this->guestHeaders($token), ['X-Idempotency-Key' => 'QA_RUN_REL-partial-key']))->assertCreated();
        $orderId = (int) $response->json('order.id');
        $row = DB::table('order_alert_outboxes')->where('order_id', $orderId)->first();
        $this->assertNull($row->web_push_delivered_at);
        $this->assertSame(['recipient-retry'], json_decode($row->web_push_retryable_recipients, true));

        Artisan::call('orders:deliver-pending-alerts');
        $this->assertNotNull(DB::table('order_alert_outboxes')->where('order_id', $orderId)->value('web_push_delivered_at'));
    }
}
