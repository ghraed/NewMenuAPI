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

    public function test_restaurant_creation_adds_ten_default_tables_and_guest_can_order_by_table_reference(): void
    {
        $restaurant = $this->createRestaurant();
        $dish = $this->createDish($restaurant, 'Burger Deluxe', 12.50, 'published');
        $this->createDish($restaurant, 'Hidden Draft', 8.00, 'draft');

        $this->assertSame(
            ['T01', 'T02', 'T03', 'T04', 'T05', 'T06', 'T07', 'T08', 'T09', 'T10'],
            $restaurant->tables()->orderBy('name')->pluck('name')->all()
        );

        $response = $this->postJson("/api/menu/{$restaurant->slug}/orders", [
            'table_reference' => 'T01',
            'notes' => 'No onions',
            'items' => [
                [
                    'dish_id' => $dish->id,
                    'quantity' => 2,
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('order.status', Order::STATUS_PENDING_STAFF_CONFIRMATION)
            ->assertJsonPath('order.table_reference', 'T01')
            ->assertJsonPath('order.notes', 'No onions')
            ->assertJsonPath('order.items.0.dish_id', $dish->id)
            ->assertJsonPath('order.items.0.quantity', 2)
            ->assertJsonPath('order.invoice.subtotal', '25.00')
            ->assertJsonPath('order.invoice.discount_amount', '0.00')
            ->assertJsonPath('order.invoice.vat_amount', '0.00')
            ->assertJsonPath('order.invoice.total', '25.00');

        $this->assertDatabaseHas('orders', [
            'restaurant_id' => $restaurant->id,
            'restaurant_table_id' => $restaurant->tables()->where('name', 'T01')->value('id'),
            'table_reference' => 'T01',
            'status' => Order::STATUS_PENDING_STAFF_CONFIRMATION,
            'subtotal' => '25.00',
            'total' => '25.00',
        ]);
    }

    public function test_guest_cannot_create_orders_with_an_unknown_table_reference(): void
    {
        $restaurant = $this->createRestaurant();
        $dish = $this->createDish($restaurant, 'Secret Dish', 9.50, 'published');

        $response = $this->postJson("/api/menu/{$restaurant->slug}/orders", [
            'table_reference' => 'T99',
            'items' => [
                [
                    'dish_id' => $dish->id,
                    'quantity' => 1,
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('table_reference');
    }

    public function test_staff_can_list_pending_confirmation_orders_for_their_restaurant(): void
    {
        $restaurant = $this->createRestaurant();
        $otherRestaurant = $this->createRestaurant();
        $staff = $this->createStaffUser($restaurant);
        $ownedOrder = $this->createPendingOrder($restaurant, 'T02');
        $this->createPendingOrder($otherRestaurant, 'T03');

        Sanctum::actingAs($staff);

        $response = $this->getJson('/api/orders/pending-confirmation');

        $response->assertOk()
            ->assertJsonCount(1, 'orders')
            ->assertJsonPath('orders.0.id', $ownedOrder->id)
            ->assertJsonPath('orders.0.status', Order::STATUS_PENDING_STAFF_CONFIRMATION)
            ->assertJsonPath('orders.0.table_reference', 'T02');
    }

    public function test_staff_can_confirm_pending_orders_without_accounting_fields(): void
    {
        $restaurant = $this->createRestaurant();
        $staff = $this->createStaffUser($restaurant);
        $order = $this->createPendingOrder($restaurant, 'T04', [
            ['name' => 'Burger Deluxe', 'price' => 10.00, 'quantity' => 2],
            ['name' => 'Fresh Juice', 'price' => 5.00, 'quantity' => 1],
        ]);

        Sanctum::actingAs($staff);

        $response = $this->postJson("/api/orders/{$order->id}/confirm");

        $response->assertOk()
            ->assertJsonPath('order.status', Order::STATUS_STAFF_CONFIRMED)
            ->assertJsonPath('order.table_reference', 'T04')
            ->assertJsonPath('order.invoice.discount_amount', '0.00')
            ->assertJsonPath('order.invoice.vat_amount', '0.00')
            ->assertJsonPath('order.invoice.total', '25.00')
            ->assertJsonPath('order.confirmed_by.id', $staff->id)
            ->assertJsonPath('order.confirmed_by.role', User::ROLE_STAFF);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => Order::STATUS_STAFF_CONFIRMED,
            'confirmed_by' => $staff->id,
            'discount_amount' => '0.00',
            'vat_amount' => '0.00',
            'total' => '25.00',
        ]);
    }

    public function test_staff_can_cancel_pending_orders(): void
    {
        $restaurant = $this->createRestaurant();
        $staff = $this->createStaffUser($restaurant);
        $order = $this->createPendingOrder($restaurant, 'T05');

        Sanctum::actingAs($staff);

        $response = $this->postJson("/api/orders/{$order->id}/cancel");

        $response->assertOk()
            ->assertJsonPath('order.status', Order::STATUS_STAFF_CANCELLED)
            ->assertJsonPath('order.cancelled_by.id', $staff->id)
            ->assertJsonPath('order.table_reference', 'T05');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => Order::STATUS_STAFF_CANCELLED,
            'cancelled_by' => $staff->id,
        ]);
    }

    public function test_admin_can_still_manage_dishes_and_process_accounting(): void
    {
        $admin = User::factory()->admin()->create();
        $restaurant = $this->createRestaurant($admin);
        $order = $this->createPendingOrder($restaurant, 'T06');

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

        $confirmResponse = $this->postJson("/api/orders/{$order->id}/confirm");
        $confirmResponse->assertOk()
            ->assertJsonPath('order.status', Order::STATUS_STAFF_CONFIRMED)
            ->assertJsonPath('order.confirmed_by.id', $admin->id);

        $accountingListResponse = $this->getJson('/api/orders/accounting');
        $accountingListResponse->assertOk()
            ->assertJsonCount(1, 'orders')
            ->assertJsonPath('orders.0.id', $order->id);

        $accountResponse = $this->postJson("/api/orders/{$order->id}/account", [
            'vat_rate' => 5,
            'discount_type' => 'fixed',
            'discount_value' => 2,
        ]);

        $accountResponse->assertOk()
            ->assertJsonPath('order.status', Order::STATUS_ACCOUNTED)
            ->assertJsonPath('order.invoice.discount_amount', '2.00')
            ->assertJsonPath('order.invoice.taxable_subtotal', '10.50')
            ->assertJsonPath('order.invoice.vat_amount', '0.53')
            ->assertJsonPath('order.invoice.total', '11.03')
            ->assertJsonPath('order.accounted_by.id', $admin->id)
            ->assertJsonPath('order.accounted_by.role', User::ROLE_ADMIN);
    }

    public function test_staff_cannot_use_admin_dish_management_or_accounting_endpoints(): void
    {
        $restaurant = $this->createRestaurant();
        $staff = $this->createStaffUser($restaurant);

        Sanctum::actingAs($staff);

        $dishResponse = $this->postJson('/api/dishes', [
            'name' => 'Unauthorized Dish',
            'description' => 'Should be blocked',
            'price' => 9.99,
            'category' => 'Main',
        ]);
        $dishResponse->assertForbidden();

        $accountingResponse = $this->getJson('/api/orders/accounting');
        $accountingResponse->assertForbidden();
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

    private function createPendingOrder(Restaurant $restaurant, string $tableReference = 'T01', ?array $items = null): Order
    {
        $itemDefinitions = $items ?? [
            ['name' => 'Classic Burger', 'price' => 12.50, 'quantity' => 1],
        ];

        $tableId = $restaurant->tables()
            ->where('name', $tableReference)
            ->value('id');

        $subtotal = collect($itemDefinitions)
            ->sum(fn (array $item) => $item['price'] * $item['quantity']);

        $order = Order::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'restaurant_table_id' => $tableId,
            'order_number' => 'ORD-TEST-'.Str::upper(Str::random(6)),
            'status' => Order::STATUS_PENDING_STAFF_CONFIRMATION,
            'guest_name' => $tableReference,
            'table_reference' => $tableReference,
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
