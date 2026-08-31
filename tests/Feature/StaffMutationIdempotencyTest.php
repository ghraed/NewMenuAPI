<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\BuildsRestaurantOrderFlow;
use Tests\TestCase;

class StaffMutationIdempotencyTest extends TestCase
{
    use BuildsRestaurantOrderFlow;
    use RefreshDatabase;

    public function test_two_tabs_and_response_loss_replay_one_confirm_mutation(): void
    {
        [$restaurant, $staff, $orderId] = $this->pendingOrder();
        Sanctum::actingAs($staff);
        $headers = ['X-Idempotency-Key' => 'QA_RUN_REL-two-tabs-confirm'];

        $first = $this->postJson("/api/orders/{$orderId}/confirm", [], $headers)->assertOk();
        $retry = $this->postJson("/api/orders/{$orderId}/confirm", [], $headers)->assertOk();

        $retry->assertJsonPath('order.id', $first->json('order.id'));
        $this->assertSame(1, DB::table('staff_mutation_idempotencies')->where('user_id', $staff->id)->count());
        $this->assertSame(1, DB::table('invoices')->where('restaurant_id', $restaurant->id)->count());
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => Order::STATUS_STAFF_CONFIRMED]);
    }

    public function test_update_and_confirm_is_one_atomic_idempotent_operation(): void
    {
        [$restaurant, $staff, $orderId, $dish] = $this->pendingOrder();
        Sanctum::actingAs($staff);
        $headers = ['X-Idempotency-Key' => 'QA_RUN_REL-update-confirm'];
        $payload = ['items' => [['dish_id' => $dish->id, 'quantity' => 3]]];

        $this->postJson("/api/orders/{$orderId}/update-and-confirm", $payload, $headers)
            ->assertOk()
            ->assertJsonPath('order.status', Order::STATUS_STAFF_CONFIRMED)
            ->assertJsonPath('order.items.0.quantity', 3);
        $this->postJson("/api/orders/{$orderId}/update-and-confirm", $payload, $headers)->assertOk();

        $this->assertSame(1, DB::table('staff_mutation_idempotencies')->where('user_id', $staff->id)->count());
        $this->assertSame(1, DB::table('invoices')->where('restaurant_id', $restaurant->id)->count());
        $this->assertSame(1, DB::table('order_items')->where('order_id', $orderId)->count());
        $this->assertDatabaseHas('order_items', ['order_id' => $orderId, 'quantity' => 3]);
    }

    /** @return array{0:mixed,1:User,2:int,3:mixed} */
    private function pendingOrder(): array
    {
        $owner = User::factory()->admin()->create([
            'name' => 'QA_RUN_REL owner',
            'email' => 'qa_run_rel_'.Str::lower(Str::random(8)).'@example.test',
        ]);
        $restaurant = $this->createRestaurant($owner, attributes: [
            'name' => 'QA_RUN_REL mutation restaurant',
            'slug' => 'qa-run-rel-mutation-'.Str::lower(Str::random(8)),
        ]);
        $dish = $this->createDish($restaurant, 'QA_RUN_REL mutation dish', 8.50);
        $staff = $this->createStaffUser($restaurant, ['T01']);
        ['session' => $session, 'token' => $token] = $this->openGuestAccess($restaurant, 1);
        $response = $this->postJson("/api/table-session/{$session->id}/order", [
            'items' => [['dish_id' => $dish->id, 'quantity' => 1]],
        ], array_merge($this->guestHeaders($token), ['X-Idempotency-Key' => 'QA_RUN_REL-create-'.Str::uuid()]));
        $response->assertCreated();

        return [$restaurant, $staff, (int) $response->json('order.id'), $dish];
    }
}
