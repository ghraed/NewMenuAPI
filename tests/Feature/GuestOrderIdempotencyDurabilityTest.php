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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
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
        $webPush->shouldReceive('notifyPendingOrderCreated')->once();
        $mobilePush = $this->mock(MobilePushNotificationService::class);
        $mobilePush->shouldReceive('notifyPendingOrderCreated')->once();

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
}
