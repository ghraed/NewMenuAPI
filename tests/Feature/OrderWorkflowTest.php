<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\TableSession;
use App\Models\User;
use App\Services\GuestMenuSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_restaurant_creation_adds_ten_default_tables_and_guest_can_order_after_table_pin_verification(): void
    {
        $restaurant = $this->createRestaurant();
        $dish = $this->createDish($restaurant, 'Burger Deluxe', 12.50, 'published');
        $this->createDish($restaurant, 'Hidden Draft', 8.00, 'draft');

        $this->assertSame(
            ['T01', 'T02', 'T03', 'T04', 'T05', 'T06', 'T07', 'T08', 'T09', 'T10'],
            $restaurant->tables()->orderBy('name')->pluck('name')->all()
        );

        $session = $this->openGuestTable(1);
        $token = $this->verifyCurrentTablePin(1, $this->activeSessionPin());

        $response = $this->postJson("/api/table-session/{$session->id}/order", [
            'notes' => 'No onions',
            'items' => [
                [
                    'dish_id' => $dish->id,
                    'quantity' => 2,
                ],
            ],
        ], $this->guestHeaders($token));

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
            'table_session_id' => $session->id,
            'table_reference' => 'T01',
            'status' => Order::STATUS_PENDING_STAFF_CONFIRMATION,
            'subtotal' => '25.00',
            'total' => '25.00',
        ]);
    }

    public function test_guest_cannot_create_orders_without_verified_table_access(): void
    {
        $restaurant = $this->createRestaurant();
        $dish = $this->createDish($restaurant, 'Secret Dish', 9.50, 'published');
        $session = $this->openGuestTable(1);

        $response = $this->postJson("/api/table-session/{$session->id}/order", [
            'items' => [
                [
                    'dish_id' => $dish->id,
                    'quantity' => 1,
                ],
            ],
        ]);

        $response->assertForbidden();
    }

    public function test_staff_can_list_pending_confirmation_orders_for_their_restaurant(): void
    {
        $restaurant = $this->createRestaurant();
        $otherRestaurant = $this->createRestaurant();
        $staff = $this->createStaffUser($restaurant, ['T02']);
        $ownedOrder = $this->createPendingOrder($restaurant, 'T02');
        $this->createPendingOrder($restaurant, 'T03');
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
        $staff = $this->createStaffUser($restaurant, ['T04']);
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

    public function test_staff_can_complete_pos_checkout_in_one_step(): void
    {
        $restaurant = $this->createRestaurant();
        $staff = $this->createStaffUser($restaurant, ['T02']);
        $dish = $this->createDish($restaurant, 'POS Burger', 12.50, 'published');

        Sanctum::actingAs($staff);

        $response = $this->postJson('/api/pos/checkout', [
            'table_reference' => 'T02',
            'payment_method' => 'cash',
            'amount_received' => 30,
            'vat_rate' => 10,
            'discount_type' => 'fixed',
            'discount_value' => 5,
            'items' => [
                [
                    'dish_id' => $dish->id,
                    'quantity' => 2,
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('order.status', Order::STATUS_ACCOUNTED)
            ->assertJsonPath('order.table_reference', 'T02')
            ->assertJsonPath('order.confirmed_by.id', $staff->id)
            ->assertJsonPath('order.accounted_by.id', $staff->id)
            ->assertJsonPath('order.invoice.subtotal', '25.00')
            ->assertJsonPath('order.invoice.discount_amount', '5.00')
            ->assertJsonPath('order.invoice.taxable_subtotal', '20.00')
            ->assertJsonPath('order.invoice.vat_amount', '2.00')
            ->assertJsonPath('order.invoice.total', '22.00')
            ->assertJsonPath('payment.method', 'cash')
            ->assertJsonPath('payment.amount_received', '30.00')
            ->assertJsonPath('payment.change_due', '8.00');

        $orderId = $response->json('order.id');
        $this->assertIsInt($orderId);

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'restaurant_id' => $restaurant->id,
            'status' => Order::STATUS_ACCOUNTED,
            'table_reference' => 'T02',
            'confirmed_by' => $staff->id,
            'accounted_by' => $staff->id,
            'subtotal' => '25.00',
            'discount_amount' => '5.00',
            'vat_amount' => '2.00',
            'total' => '22.00',
        ]);
    }

    public function test_pos_checkout_rejects_cash_payment_when_received_amount_is_too_low(): void
    {
        $restaurant = $this->createRestaurant();
        $staff = $this->createStaffUser($restaurant, ['T03']);
        $dish = $this->createDish($restaurant, 'POS Juice', 9.00, 'published');

        Sanctum::actingAs($staff);

        $response = $this->postJson('/api/pos/checkout', [
            'table_reference' => 'T03',
            'payment_method' => 'cash',
            'amount_received' => 5,
            'items' => [
                [
                    'dish_id' => $dish->id,
                    'quantity' => 1,
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Cash received is less than the order total.');

        $this->assertDatabaseMissing('orders', [
            'restaurant_id' => $restaurant->id,
            'status' => Order::STATUS_ACCOUNTED,
            'table_reference' => 'T03',
            'total' => '9.00',
        ]);
    }

    public function test_staff_can_update_pending_orders_by_editing_quantities_removing_items_and_adding_new_dishes(): void
    {
        $restaurant = $this->createRestaurant();
        $staff = $this->createStaffUser($restaurant, ['T03']);
        $burger = $this->createDish($restaurant, 'Classic Burger', 10.00, 'published');
        $juice = $this->createDish($restaurant, 'Fresh Juice', 5.00, 'published');
        $salad = $this->createDish($restaurant, 'Garden Salad', 7.50, 'published');

        $order = $this->createEditablePendingOrder($restaurant, 'T03', [
            ['dish' => $burger, 'quantity' => 1, 'unit_price' => 9.00],
            ['dish' => $juice, 'quantity' => 2],
        ]);

        $burger->update(['price' => 15.00]);
        $salad->update(['price' => 8.50]);

        Sanctum::actingAs($staff);

        $response = $this->patchJson("/api/orders/{$order->id}", [
            'items' => [
                [
                    'dish_id' => $burger->id,
                    'quantity' => 3,
                ],
                [
                    'dish_id' => $salad->id,
                    'quantity' => 1,
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('order.status', Order::STATUS_PENDING_STAFF_CONFIRMATION)
            ->assertJsonPath('order.invoice.subtotal', '35.50')
            ->assertJsonPath('order.invoice.total', '35.50')
            ->assertJsonCount(2, 'order.items')
            ->assertJsonPath('order.items.0.dish_id', $burger->id)
            ->assertJsonPath('order.items.0.unit_price', '9.00')
            ->assertJsonPath('order.items.0.quantity', 3)
            ->assertJsonPath('order.items.0.line_subtotal', '27.00')
            ->assertJsonPath('order.items.1.dish_id', $salad->id)
            ->assertJsonPath('order.items.1.unit_price', '8.50')
            ->assertJsonPath('order.items.1.quantity', 1)
            ->assertJsonPath('order.items.1.line_subtotal', '8.50');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => Order::STATUS_PENDING_STAFF_CONFIRMATION,
            'subtotal' => '35.50',
            'total' => '35.50',
        ]);

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'dish_id' => $burger->id,
            'unit_price' => '9.00',
            'quantity' => 3,
            'line_subtotal' => '27.00',
        ]);

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'dish_id' => $salad->id,
            'unit_price' => '8.50',
            'quantity' => 1,
            'line_subtotal' => '8.50',
        ]);

        $this->assertDatabaseMissing('order_items', [
            'order_id' => $order->id,
            'dish_id' => $juice->id,
        ]);
    }

    public function test_staff_can_cancel_pending_orders(): void
    {
        $restaurant = $this->createRestaurant();
        $staff = $this->createStaffUser($restaurant, ['T05']);
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

    public function test_staff_cannot_confirm_orders_for_unassigned_tables(): void
    {
        $restaurant = $this->createRestaurant();
        $staff = $this->createStaffUser($restaurant, ['T01']);
        $order = $this->createPendingOrder($restaurant, 'T02');

        Sanctum::actingAs($staff);

        $response = $this->postJson("/api/orders/{$order->id}/confirm");

        $response->assertForbidden();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => Order::STATUS_PENDING_STAFF_CONFIRMATION,
            'confirmed_by' => null,
        ]);
    }

    public function test_staff_can_list_published_dishes_for_the_waiter_editor(): void
    {
        $restaurant = $this->createRestaurant();
        $staff = $this->createStaffUser($restaurant, ['T02']);
        $otherRestaurant = $this->createRestaurant();

        $published = $this->createDish($restaurant, 'Burger Deluxe', 12.50, 'published');
        $this->createDish($restaurant, 'Draft Soup', 7.00, 'draft');
        $otherDish = $this->createDish($otherRestaurant, 'Other Burger', 15.00, 'published');

        Sanctum::actingAs($staff);

        $response = $this->getJson('/api/dishes/published');

        $response->assertOk()
            ->assertJsonCount(1, 'dishes')
            ->assertJsonPath('dishes.0.id', $published->id)
            ->assertJsonPath('dishes.0.name', 'Burger Deluxe')
            ->assertJsonPath('dishes.0.price', 12.5)
            ->assertJsonMissing(['id' => $otherDish->id]);
    }

    public function test_staff_cannot_update_orders_for_unassigned_tables(): void
    {
        $restaurant = $this->createRestaurant();
        $staff = $this->createStaffUser($restaurant, ['T01']);
        $burger = $this->createDish($restaurant, 'Classic Burger', 12.50, 'published');
        $order = $this->createEditablePendingOrder($restaurant, 'T02', [
            ['dish' => $burger, 'quantity' => 1],
        ]);

        Sanctum::actingAs($staff);

        $response = $this->patchJson("/api/orders/{$order->id}", [
            'items' => [
                [
                    'dish_id' => $burger->id,
                    'quantity' => 2,
                ],
            ],
        ]);

        $response->assertForbidden();

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'dish_id' => $burger->id,
            'quantity' => 1,
        ]);
    }

    public function test_only_pending_orders_can_be_edited(): void
    {
        $restaurant = $this->createRestaurant();
        $staff = $this->createStaffUser($restaurant, ['T07']);
        $burger = $this->createDish($restaurant, 'Classic Burger', 12.50, 'published');

        $statuses = [
            Order::STATUS_STAFF_CONFIRMED,
            Order::STATUS_STAFF_CANCELLED,
            Order::STATUS_ACCOUNTED,
        ];

        foreach ($statuses as $status) {
            $order = $this->createEditablePendingOrder($restaurant, 'T07', [
                ['dish' => $burger, 'quantity' => 1],
            ]);
            $order->update(['status' => $status]);

            Sanctum::actingAs($staff);

            $response = $this->patchJson("/api/orders/{$order->id}", [
                'items' => [
                    [
                        'dish_id' => $burger->id,
                        'quantity' => 2,
                    ],
                ],
            ]);

            $response->assertStatus(422)
                ->assertJsonPath('message', 'Only orders waiting for staff confirmation can be edited.');

            $this->assertDatabaseHas('orders', [
                'id' => $order->id,
                'status' => $status,
            ]);
        }
    }

    public function test_legacy_pending_orders_with_null_dish_ids_cannot_be_edited(): void
    {
        $restaurant = $this->createRestaurant();
        $staff = $this->createStaffUser($restaurant, ['T08']);
        $burger = $this->createDish($restaurant, 'Classic Burger', 12.50, 'published');
        $order = $this->createPendingOrder($restaurant, 'T08');

        Sanctum::actingAs($staff);

        $response = $this->patchJson("/api/orders/{$order->id}", [
            'items' => [
                [
                    'dish_id' => $burger->id,
                    'quantity' => 2,
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('items');
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

    private function createStaffUser(Restaurant $restaurant, array $tableNames = []): User
    {
        $staff = User::factory()->staff()->create();
        $restaurant->staffUsers()->attach($staff->id);

        if ($tableNames !== []) {
            $tableIds = $restaurant->tables()
                ->whereIn('name', $tableNames)
                ->pluck('id')
                ->all();

            $staff->assignedTables()->sync($tableIds);
        }

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

    private function createEditablePendingOrder(Restaurant $restaurant, string $tableReference, array $items): Order
    {
        $tableId = $restaurant->tables()
            ->where('name', $tableReference)
            ->value('id');

        $subtotal = collect($items)->sum(function (array $item): float {
            $unitPrice = (float) ($item['unit_price'] ?? $item['dish']->price);

            return $unitPrice * $item['quantity'];
        });

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

        foreach ($items as $item) {
            /** @var Dish $dish */
            $dish = $item['dish'];
            $unitPrice = (float) ($item['unit_price'] ?? $dish->price);

            OrderItem::query()->create([
                'order_id' => $order->id,
                'dish_id' => $dish->id,
                'dish_name' => $dish->name,
                'unit_price' => number_format($unitPrice, 2, '.', ''),
                'quantity' => $item['quantity'],
                'line_subtotal' => number_format($unitPrice * $item['quantity'], 2, '.', ''),
            ]);
        }

        return $order;
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
            'X-Guest-Device-Id' => 'order-workflow-device',
            'X-Guest-Access-Token' => $token,
        ]);
    }
}
