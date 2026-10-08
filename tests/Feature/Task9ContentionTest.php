<?php

namespace Tests\Feature;

use App\Models\DishIngredient;
use App\Models\Ingredient;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\RoomPlan;
use App\Models\RoomPlanItem;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\BuildsRestaurantOrderFlow;
use Tests\Support\RunsContentionRequests;
use Tests\TestCase;

class Task9ContentionTest extends TestCase
{
    use BuildsRestaurantOrderFlow, DatabaseMigrations, RunsContentionRequests;

    private function fixture(): array
    {
        $prefix = 'QA_RUN_'.getenv('QA_RUN_ID').'_'.bin2hex(random_bytes(4));
        $owner = \App\Models\User::factory()->admin()->create(['name' => $prefix.'_admin', 'email' => $prefix.'@example.invalid']);
        $restaurant = $this->createRestaurant($owner, featureKeys: ['table_reservations', 'ingredient_stock_deduction'], attributes: ['name' => $prefix, 'slug' => strtolower(str_replace('_', '-', $prefix))]);
        $dish = $this->createDish($restaurant, $prefix.'_dish', 10);
        ['session' => $session, 'token' => $guestToken] = $this->openGuestAccess($restaurant, 1);
        $headers = ['Authorization' => 'Bearer '.$restaurant->user->createToken($prefix)->plainTextToken];

        return [$restaurant, $dish, $session, $guestToken, $headers, $prefix];
    }

    public function test_simultaneous_reservations_persist_one_booking(): void
    {
        [$restaurant, , , , $headers, $prefix] = $this->fixture();
        $plan = RoomPlan::create(['restaurant_id' => $restaurant->id, 'name' => $prefix, 'width' => 1000, 'height' => 800]);
        $table = RoomPlanItem::create(['room_plan_id' => $plan->id, 'type' => 'table', 'label' => $prefix, 'x' => 1, 'y' => 1, 'width' => 100, 'height' => 100, 'seats' => 4, 'container' => 'room', 'is_active' => true]);
        $request = ['path' => '/api/admin/reservations', 'headers' => $headers, 'payload' => ['room_plan_id' => $plan->id, 'room_plan_item_id' => $table->id, 'customer_name' => $prefix, 'customer_phone' => 'QA_RUN_000', 'reservation_date' => '2026-01-16', 'start_time' => '19:00', 'end_time' => '20:00']];
        $other = $request;
        $other['payload']['customer_name'] .= '_other';
        // Room-plan lookup happens before the table row lock, inside each real transaction.
        $results = $this->raceRequests([$request, $other], 'room_plans', ['room_plan_items', $table->id]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([201, 422], $statuses);
        $this->assertSame(1, Reservation::where('room_plan_item_id', $table->id)->count());
        $this->assertSame(0, Invoice::where('restaurant_id', $restaurant->id)->count());
    }

    public function test_simultaneous_last_stock_confirmation_rolls_back_loser(): void
    {
        [$restaurant, $dish, $session, $guestToken, $headers, $prefix] = $this->fixture();
        $ingredient = Ingredient::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'restaurant_id' => $restaurant->id, 'name' => $prefix, 'stock_unit' => 'g', 'current_stock_quantity' => '1.000', 'low_stock_threshold' => '0.000', 'is_active' => true]);
        DishIngredient::create(['dish_id' => $dish->id, 'ingredient_id' => $ingredient->id, 'quantity' => '1.000', 'unit' => 'g']);
        $orders = [];
        foreach ([1, 2] as $i) {
            $orders[] = $this->postJson("/api/table-session/{$session->id}/order", ['notes' => $prefix.'_'.$i, 'items' => [['dish_id' => $dish->id, 'quantity' => 1]]], $this->guestHeaders($guestToken))->assertCreated()->json('order.id');
        }
        $requests = array_map(fn ($id) => ['path' => "/api/orders/$id/confirm", 'headers' => $headers, 'payload' => []], $orders);
        $results = $this->raceRequests($requests, 'orders', ['ingredients', $ingredient->id]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 422], $statuses);
        $this->assertSame('0.000', $ingredient->fresh()->current_stock_quantity);
        $this->assertSame(1, StockMovement::where('ingredient_id', $ingredient->id)->count());
        $this->assertSame(1, DB::table('order_item_ingredient_usages')->where('ingredient_id', $ingredient->id)->count());
        $this->assertSame(1, Order::whereIn('id', $orders)->where('status', Order::STATUS_PENDING_STAFF_CONFIRMATION)->count());
        $loser = Order::whereIn('id', $orders)->where('status', Order::STATUS_PENDING_STAFF_CONFIRMATION)->firstOrFail();
        $this->assertNull($loser->confirmed_by);
        $this->assertNull($loser->invoice_number);
    }

    public function test_simultaneous_guest_replay_creates_exactly_one_order(): void
    {
        [$restaurant, $dish, $session, $token, , $prefix] = $this->fixture();
        $request = ['path' => "/api/table-session/{$session->id}/order", 'headers' => [...$this->guestHeaders($token), 'X-Idempotency-Key' => $prefix], 'payload' => ['items' => [['dish_id' => $dish->id, 'quantity' => 1]]]];
        $results = $this->raceRequests([$request, $request], 'table_sessions');
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 201], $statuses);
        $this->assertSame(1, Order::where('table_session_id', $session->id)->count());
        // Match the persistent cache used by the independent QA HTTP workers.
        config(['cache.default' => 'file']);
        $replay = $this->postJson($request['path'], $request['payload'], $request['headers'])->assertOk();
        $this->assertSame(Order::where('table_session_id', $session->id)->firstOrFail()->id, $replay->json('order.id'));
        $this->assertSame(1, Order::where('table_session_id', $session->id)->count());
        $this->assertSame(0, StockMovement::where('restaurant_id', $restaurant->id)->count());
    }

    public function test_guest_replay_survives_server_cache_loss_without_duplicate_order(): void
    {
        [, $dish, $session, $token, , $prefix] = $this->fixture();
        $path = "/api/table-session/{$session->id}/order";
        $payload = ['items' => [['dish_id' => $dish->id, 'quantity' => 1]]];
        $headers = [...$this->guestHeaders($token), 'X-Idempotency-Key' => $prefix];
        $id = $this->postJson($path, $payload, $headers)->assertCreated()->json('order.id');
        \Illuminate\Support\Facades\Cache::flush();
        $this->postJson($path, $payload, $headers)->assertOk()->assertJsonPath('order.id', $id);
        $this->assertSame(1, Order::where('table_session_id', $session->id)->count());
        $payload['items'][0]['quantity'] = 2;
        $this->postJson($path, $payload, $headers)->assertStatus(409);
        $this->assertSame(1, Order::where('table_session_id', $session->id)->count());
    }

    public function test_known_pre_upgrade_request_is_adopted_without_rewriting_the_order(): void
    {
        [, $dish, $session, $token, , $prefix] = $this->fixture();
        $path = "/api/table-session/{$session->id}/order";
        $payload = ['items' => [['dish_id' => $dish->id, 'quantity' => 1]]];
        $id = $this->postJson($path, $payload, $this->guestHeaders($token))->assertCreated()->json('order.id');
        $first = $this->postJson($path, $payload, [...$this->guestHeaders($token), 'X-Idempotency-Key' => $prefix])->assertCreated()->json('order.id');
        // Retain its old cache metadata but remove only the new ledger fixture to
        // model a committed request made before the migration was deployed.
        DB::table('guest_order_idempotency')->where('order_id', $first)->delete();
        $this->postJson($path, $payload, [...$this->guestHeaders($token), 'X-Idempotency-Key' => $prefix])->assertOk()->assertJsonPath('order.id', $first);
        $this->assertSame(2, Order::where('table_session_id', $session->id)->count());
        $this->assertSame(1, DB::table('guest_order_idempotency')->where('order_id', $first)->count());
        $this->assertSame('10.00', Order::findOrFail($id)->total);
    }

    public function test_guest_order_rejection_leaves_no_request_identity_or_partial_order(): void
    {
        [, , $session, $token, , $prefix] = $this->fixture();
        $this->postJson("/api/table-session/{$session->id}/order", ['items' => [['dish_id' => 2147483647, 'quantity' => 1]]], [...$this->guestHeaders($token), 'X-Idempotency-Key' => $prefix])->assertStatus(422);
        $this->assertSame(0, DB::table('guest_order_idempotency')->where('table_session_id', $session->id)->count());
        $this->assertSame(0, Order::where('table_session_id', $session->id)->count());
    }

    public function test_simultaneous_finalization_keeps_one_paid_receipt(): void
    {
        [$restaurant, $dish, $session, $token, $headers] = $this->fixture();
        $id = $this->postJson("/api/table-session/{$session->id}/order", ['items' => [['dish_id' => $dish->id, 'quantity' => 1]]], $this->guestHeaders($token))->assertCreated()->json('order.id');
        Sanctum::actingAs($restaurant->user);
        $this->postJson("/api/orders/$id/confirm")->assertOk();
        $this->postJson("/api/orders/$id/account", ['vat_rate' => 10, 'discount_type' => 'fixed', 'discount_value' => 2])->assertOk();
        $request = ['path' => "/api/table-sessions/{$session->id}/finalize", 'headers' => $headers, 'payload' => ['payment_method' => 'cash', 'payment_reference' => 'QA_RUN_paid_receipt']];
        $results = $this->raceRequests([$request, $request], 'table_sessions', ['table_sessions', $session->id]);
        $this->assertSame([200, 200], array_column($results, 'status'));
        $this->assertSame($results[0]['body']['invoice_id'], $results[1]['body']['invoice_id']);
        $this->assertSame(1, Invoice::where('restaurant_id', $restaurant->id)->count());
        $invoice = Invoice::where('restaurant_id', $restaurant->id)->firstOrFail();
        $this->assertSame('8.80', $invoice->total);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame($invoice->id, $session->fresh()->finalized_invoice_id);
        $this->assertSame('closed', $session->fresh()->status);
        $this->assertSame('cash', $invoice->payment_method);
        $this->assertNotNull($invoice->paid_at);
    }
}
