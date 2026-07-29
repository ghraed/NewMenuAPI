<?php

namespace Tests\Feature;

use App\Events\KitchenOrderReady;
use App\Events\KitchenOrderUpdated;
use App\Models\Dish;
use App\Models\Feature;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RestaurantFeature;
use App\Models\RestaurantTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KitchenWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_kitchen_active_queue_is_scoped_sorted_and_filterable_for_the_current_restaurant(): void
    {
        $restaurant = $this->createRestaurant();
        $chef = $this->createChefUser($restaurant);

        $newOrder = $this->createConfirmedKitchenOrder($restaurant, 'T01', Order::KITCHEN_STATUS_NEW, now()->subMinutes(15));
        $inProgressOrder = $this->createConfirmedKitchenOrder($restaurant, 'T02', Order::KITCHEN_STATUS_IN_PROGRESS, now()->subMinutes(10));
        $readyOrder = $this->createConfirmedKitchenOrder($restaurant, 'T03', Order::KITCHEN_STATUS_READY, now()->subMinutes(5));
        $this->createConfirmedKitchenOrder($restaurant, 'T04', Order::KITCHEN_STATUS_SERVED, now()->subMinutes(1));

        $otherRestaurant = $this->createRestaurant();
        $this->createConfirmedKitchenOrder($otherRestaurant, 'T01', Order::KITCHEN_STATUS_NEW, now()->subMinutes(20));

        Sanctum::actingAs($chef);

        $response = $this->getJson('/api/kitchen/orders');

        $response->assertOk()
            ->assertJsonCount(3, 'orders')
            ->assertJsonPath('orders.0.id', $newOrder->id)
            ->assertJsonPath('orders.1.id', $inProgressOrder->id)
            ->assertJsonPath('orders.2.id', $readyOrder->id)
            ->assertJsonPath('orders.0.kitchen_status', Order::KITCHEN_STATUS_NEW)
            ->assertJsonPath('orders.1.kitchen_status', Order::KITCHEN_STATUS_IN_PROGRESS)
            ->assertJsonPath('orders.2.kitchen_status', Order::KITCHEN_STATUS_READY);

        $this->getJson('/api/kitchen/orders?status=ready')
            ->assertOk()
            ->assertJsonCount(1, 'orders')
            ->assertJsonPath('orders.0.id', $readyOrder->id)
            ->assertJsonPath('orders.0.kitchen_status', Order::KITCHEN_STATUS_READY);
    }

    public function test_chef_can_progress_orders_through_ready_and_staff_can_mark_them_served(): void
    {
        Event::fake([KitchenOrderUpdated::class, KitchenOrderReady::class]);

        $restaurant = $this->createRestaurant();
        $chef = $this->createChefUser($restaurant);
        $order = $this->createConfirmedKitchenOrder($restaurant, 'T05', Order::KITCHEN_STATUS_NEW, now()->subMinutes(6));

        Sanctum::actingAs($chef);

        $this->postJson("/api/kitchen/orders/{$order->id}/start")
            ->assertOk()
            ->assertJsonPath('order.kitchen_status', Order::KITCHEN_STATUS_IN_PROGRESS)
            ->assertJsonPath('order.items.0.modifiers', []);

        $readyResponse = $this->postJson("/api/kitchen/orders/{$order->id}/ready");

        $readyResponse->assertOk()
            ->assertJsonPath('order.kitchen_status', Order::KITCHEN_STATUS_READY)
            ->assertJsonPath('order.special_requests', $order->notes);

        Event::assertDispatched(KitchenOrderUpdated::class, function (KitchenOrderUpdated $event) use ($order) {
            return $event->order->id === $order->id
                && ($event->payload['kitchen_status'] ?? null) === Order::KITCHEN_STATUS_READY;
        });

        Event::assertDispatched(KitchenOrderReady::class, function (KitchenOrderReady $event) use ($order) {
            return $event->order->id === $order->id
                && ($event->payload['kitchen_status'] ?? null) === Order::KITCHEN_STATUS_READY
                && ($event->payload['items'][0]['dish_name'] ?? null) === 'Kitchen Item';
        });

        Sanctum::actingAs($restaurant->user);

        $this->postJson("/api/orders/{$order->id}/served")
            ->assertOk()
            ->assertJsonPath('order.kitchen_status', Order::KITCHEN_STATUS_SERVED);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => Order::STATUS_STAFF_CONFIRMED,
            'kitchen_status' => Order::KITCHEN_STATUS_SERVED,
        ]);
    }

    public function test_invalid_kitchen_transitions_and_cross_tenant_access_are_rejected(): void
    {
        $restaurant = $this->createRestaurant();
        $chef = $this->createChefUser($restaurant);
        $pendingOrder = $this->createPendingKitchenOrder($restaurant, 'T06');
        $newOrder = $this->createConfirmedKitchenOrder($restaurant, 'T07', Order::KITCHEN_STATUS_NEW, now()->subMinutes(4));

        Sanctum::actingAs($chef);

        $this->postJson("/api/kitchen/orders/{$pendingOrder->id}/start")
            ->assertStatus(422);

        $this->postJson("/api/kitchen/orders/{$newOrder->id}/ready")
            ->assertStatus(422);

        $otherRestaurant = $this->createRestaurant();
        $otherChef = $this->createChefUser($otherRestaurant);

        Sanctum::actingAs($otherChef);

        $this->postJson("/api/kitchen/orders/{$newOrder->id}/start")
            ->assertNotFound();
    }

    private function createRestaurant(?User $owner = null): Restaurant
    {
        $user = $owner ?? User::factory()->admin()->create();

        $restaurant = Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'name' => 'Kitchen Workflow '.Str::upper(Str::random(3)),
            'slug' => 'kitchen-workflow-'.Str::lower(Str::random(8)),
            'description' => 'Kitchen workflow test restaurant',
            'address' => 'Beirut',
        ]);

        foreach (range(1, 10) as $number) {
            RestaurantTable::query()->updateOrCreate(
                [
                    'restaurant_id' => $restaurant->id,
                    'name' => sprintf('T%02d', $number),
                ],
                [
                    'is_active' => true,
                    'seats' => 4,
                ]
            );
        }

        foreach (['qr_menu', 'table_ordering', 'realtime_staff_orders'] as $featureKey) {
            $this->enableFeature($restaurant, $featureKey);
        }

        return $restaurant->fresh('tables', 'user');
    }

    private function createChefUser(Restaurant $restaurant): User
    {
        $chef = User::factory()->chef()->create();
        $restaurant->staffUsers()->attach($chef->id);

        return $chef;
    }

    private function createConfirmedKitchenOrder(
        Restaurant $restaurant,
        string $tableName,
        string $kitchenStatus,
        \DateTimeInterface $confirmedAt
    ): Order {
        $tableId = $restaurant->tables()->where('name', $tableName)->value('id');
        $confirmedBy = User::factory()->staff()->create();
        $restaurant->staffUsers()->attach($confirmedBy->id);

        $order = Order::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'restaurant_table_id' => $tableId,
            'status' => Order::STATUS_STAFF_CONFIRMED,
            'kitchen_status' => $kitchenStatus,
            'guest_name' => $tableName,
            'table_reference' => $tableName,
            'notes' => 'No peanuts, extra lemon.',
            'subtotal' => '12.00',
            'discount_value' => '0.00',
            'discount_amount' => '0.00',
            'taxable_subtotal' => '12.00',
            'vat_rate' => '0.00',
            'vat_amount' => '0.00',
            'total' => '12.00',
            'confirmed_by' => $confirmedBy->id,
            'confirmed_at' => $confirmedAt,
            'kitchen_started_at' => $kitchenStatus !== Order::KITCHEN_STATUS_NEW ? now()->subMinutes(3) : null,
            'kitchen_ready_at' => $kitchenStatus === Order::KITCHEN_STATUS_READY || $kitchenStatus === Order::KITCHEN_STATUS_SERVED
                ? now()->subMinute()
                : null,
            'kitchen_completed_at' => $kitchenStatus === Order::KITCHEN_STATUS_SERVED ? now() : null,
            'order_number' => 'ORD-'.Str::upper(Str::random(8)),
        ]);

        $order->items()->create([
            'dish_id' => Dish::factory()->published()->create([
                'restaurant_id' => $restaurant->id,
                'name' => 'Kitchen Item',
                'price' => 12,
            ])->id,
            'dish_name' => 'Kitchen Item',
            'unit_price' => '12.00',
            'quantity' => 1,
            'line_subtotal' => '12.00',
        ]);

        return $order->fresh(['items', 'confirmedBy']);
    }

    private function createPendingKitchenOrder(Restaurant $restaurant, string $tableName): Order
    {
        $tableId = $restaurant->tables()->where('name', $tableName)->value('id');

        $order = Order::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'restaurant_table_id' => $tableId,
            'status' => Order::STATUS_PENDING_STAFF_CONFIRMATION,
            'kitchen_status' => null,
            'guest_name' => $tableName,
            'table_reference' => $tableName,
            'subtotal' => '12.00',
            'discount_value' => '0.00',
            'discount_amount' => '0.00',
            'taxable_subtotal' => '12.00',
            'vat_rate' => '0.00',
            'vat_amount' => '0.00',
            'total' => '12.00',
            'order_number' => 'ORD-'.Str::upper(Str::random(8)),
        ]);

        $order->items()->create([
            'dish_id' => Dish::factory()->published()->create([
                'restaurant_id' => $restaurant->id,
                'name' => 'Pending Kitchen Item',
                'price' => 12,
            ])->id,
            'dish_name' => 'Pending Kitchen Item',
            'unit_price' => '12.00',
            'quantity' => 1,
            'line_subtotal' => '12.00',
        ]);

        return $order->fresh('items');
    }

    private function enableFeature(Restaurant $restaurant, string $key): void
    {
        $feature = Feature::query()->updateOrCreate(
            ['key' => $key],
            [
                'name' => Str::title(str_replace('_', ' ', $key)),
                'description' => 'Kitchen workflow test feature',
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
