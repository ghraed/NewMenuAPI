<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_create_a_pending_order_for_published_dishes(): void
    {
        $restaurant = $this->createRestaurant();
        $dish = $this->createDish($restaurant, 'Burger Deluxe', 12.50, 'published');
        $this->createDish($restaurant, 'Hidden Draft', 8.00, 'draft');

        $response = $this->postJson("/api/menu/{$restaurant->slug}/orders", [
            'guest_name' => 'Nora Guest',
            'guest_phone' => '+96170000000',
            'guest_email' => 'nora@example.com',
            'notes' => 'No onions',
            'items' => [
                [
                    'dish_id' => $dish->id,
                    'quantity' => 2,
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('order.status', Order::STATUS_PENDING_CONFIRMATION)
            ->assertJsonPath('order.guest_name', 'Nora Guest')
            ->assertJsonPath('order.items.0.dish_id', $dish->id)
            ->assertJsonPath('order.items.0.quantity', 2)
            ->assertJsonPath('order.invoice.subtotal', '25.00')
            ->assertJsonPath('order.invoice.discount_amount', '0.00')
            ->assertJsonPath('order.invoice.vat_amount', '0.00')
            ->assertJsonPath('order.invoice.total', '25.00');

        $this->assertDatabaseHas('orders', [
            'restaurant_id' => $restaurant->id,
            'guest_name' => 'Nora Guest',
            'status' => Order::STATUS_PENDING_CONFIRMATION,
            'subtotal' => '25.00',
            'total' => '25.00',
        ]);
        $this->assertDatabaseHas('order_items', [
            'dish_id' => $dish->id,
            'dish_name' => 'Burger Deluxe',
            'quantity' => 2,
            'line_subtotal' => '25.00',
        ]);
    }

    public function test_guest_cannot_create_orders_with_draft_dishes(): void
    {
        $restaurant = $this->createRestaurant();
        $draftDish = $this->createDish($restaurant, 'Secret Dish', 9.50, 'draft');

        $response = $this->postJson("/api/menu/{$restaurant->slug}/orders", [
            'guest_name' => 'Nora Guest',
            'items' => [
                [
                    'dish_id' => $draftDish->id,
                    'quantity' => 1,
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('items');
    }

    public function test_staff_can_list_pending_confirmation_orders_for_their_restaurant(): void
    {
        $restaurant = $this->createRestaurant();
        $otherRestaurant = $this->createRestaurant();
        $staff = $this->createStaffUser($restaurant);
        $ownedOrder = $this->createPendingOrder($restaurant);
        $this->createPendingOrder($otherRestaurant);

        Sanctum::actingAs($staff);

        $response = $this->getJson('/api/orders/pending-confirmation');

        $response->assertOk()
            ->assertJsonCount(1, 'orders')
            ->assertJsonPath('orders.0.id', $ownedOrder->id)
            ->assertJsonPath('orders.0.status', Order::STATUS_PENDING_CONFIRMATION);
    }

    public function test_staff_can_confirm_pending_orders_with_vat_and_discount(): void
    {
        $restaurant = $this->createRestaurant();
        $staff = $this->createStaffUser($restaurant);
        $order = $this->createPendingOrder($restaurant, [
            ['name' => 'Burger Deluxe', 'price' => 10.00, 'quantity' => 2],
            ['name' => 'Fresh Juice', 'price' => 5.00, 'quantity' => 1],
        ]);

        Sanctum::actingAs($staff);

        $response = $this->postJson("/api/orders/{$order->id}/confirm", [
            'vat_rate' => 10,
            'discount_type' => 'percentage',
            'discount_value' => 20,
        ]);

        $response->assertOk()
            ->assertJsonPath('order.status', Order::STATUS_CONFIRMED)
            ->assertJsonPath('order.invoice.discount_amount', '5.00')
            ->assertJsonPath('order.invoice.taxable_subtotal', '20.00')
            ->assertJsonPath('order.invoice.vat_amount', '2.00')
            ->assertJsonPath('order.invoice.total', '22.00')
            ->assertJsonPath('order.confirmed_by.id', $staff->id)
            ->assertJsonPath('order.confirmed_by.role', User::ROLE_STAFF);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => Order::STATUS_CONFIRMED,
            'confirmed_by' => $staff->id,
            'discount_type' => 'percentage',
            'discount_amount' => '5.00',
            'vat_amount' => '2.00',
            'total' => '22.00',
        ]);
    }

    public function test_staff_cannot_use_admin_dish_management_endpoints(): void
    {
        $restaurant = $this->createRestaurant();
        $staff = $this->createStaffUser($restaurant);

        Sanctum::actingAs($staff);

        $response = $this->postJson('/api/dishes', [
            'name' => 'Unauthorized Dish',
            'description' => 'Should be blocked',
            'price' => 9.99,
            'category' => 'Main',
        ]);

        $response->assertForbidden();
    }

    public function test_admin_can_still_manage_dishes_and_confirm_orders(): void
    {
        $admin = User::factory()->admin()->create();
        $restaurant = $this->createRestaurant($admin);
        $order = $this->createPendingOrder($restaurant);

        Sanctum::actingAs($admin);

        $dishResponse = $this->postJson('/api/dishes', [
            'name' => 'Admin Special',
            'description' => 'Still manageable by admins',
            'price' => 14.75,
            'category' => 'Special',
            'status' => 'draft',
        ]);

        $dishResponse->assertCreated()
            ->assertJsonPath('name', 'Admin Special');

        $confirmResponse = $this->postJson("/api/orders/{$order->id}/confirm", [
            'vat_rate' => 5,
        ]);

        $confirmResponse->assertOk()
            ->assertJsonPath('order.status', Order::STATUS_CONFIRMED)
            ->assertJsonPath('order.confirmed_by.id', $admin->id)
            ->assertJsonPath('order.confirmed_by.role', User::ROLE_ADMIN);
    }

    private function createRestaurant(?User $user = null): Restaurant
    {
        $owner = $user ?? User::factory()->admin()->create();

        return Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'name' => 'Order Workflow Restaurant '.Str::upper(Str::random(3)),
            'slug' => 'order-workflow-'.Str::lower(Str::random(8)),
            'description' => 'Restaurant for order workflow tests',
            'address' => 'Beirut',
        ]);
    }

    private function createDish(Restaurant $restaurant, string $name, float $price, string $status): Dish
    {
        return Dish::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => $name,
            'description' => $name.' description',
            'price' => $price,
            'category' => 'Main',
            'status' => $status,
        ]);
    }

    private function createStaffUser(Restaurant $restaurant): User
    {
        $staff = User::factory()->staff()->create();
        $restaurant->staffUsers()->attach($staff->id);

        return $staff;
    }

    private function createPendingOrder(Restaurant $restaurant, ?array $items = null): Order
    {
        $itemDefinitions = $items ?? [
            ['name' => 'Classic Burger', 'price' => 12.50, 'quantity' => 1],
        ];

        $subtotal = collect($itemDefinitions)
            ->sum(fn (array $item) => $item['price'] * $item['quantity']);

        $order = Order::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'order_number' => 'ORD-TEST-'.Str::upper(Str::random(6)),
            'status' => Order::STATUS_PENDING_CONFIRMATION,
            'guest_name' => 'Walk-in Guest',
            'subtotal' => number_format($subtotal, 2, '.', ''),
            'taxable_subtotal' => number_format($subtotal, 2, '.', ''),
            'total' => number_format($subtotal, 2, '.', ''),
        ]);

        foreach ($itemDefinitions as $itemDefinition) {
            OrderItem::query()->create([
                'order_id' => $order->id,
                'dish_id' => null,
                'dish_name' => $itemDefinition['name'],
                'unit_price' => number_format($itemDefinition['price'], 2, '.', ''),
                'quantity' => $itemDefinition['quantity'],
                'line_subtotal' => number_format($itemDefinition['price'] * $itemDefinition['quantity'], 2, '.', ''),
            ]);
        }

        return $order;
    }
}
