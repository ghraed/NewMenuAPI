<?php

namespace Tests\Feature\Finance;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\BuildsRestaurantOrderFlow;
use Tests\TestCase;

class SessionInvoiceFinalizationTest extends TestCase
{
    use BuildsRestaurantOrderFlow;
    use RefreshDatabase;

    public function test_finalize_creates_paid_invoice_from_accounted_order_with_exact_tax_discount_and_server_side_pricing(): void
    {
        $restaurant = $this->createRestaurant();
        $dish = $this->createDish($restaurant, 'Server Priced Grill', 12.50);
        ['session' => $session, 'token' => $token] = $this->openGuestAccess($restaurant, 1);

        $orderResponse = $this->postJson("/api/table-session/{$session->id}/order", [
            'subtotal' => '0.01',
            'total' => '0.01',
            'items' => [
                [
                    'dish_id' => $dish->id,
                    'quantity' => 2,
                    'unit_price' => '0.01',
                    'line_subtotal' => '0.01',
                ],
            ],
        ], $this->guestHeaders($token));

        $orderResponse->assertCreated()
            ->assertJsonPath('order.invoice.subtotal', '25.00')
            ->assertJsonPath('order.items.0.unit_price', '12.50');

        $orderId = $orderResponse->json('order.id');
        $this->assertIsInt($orderId);

        Sanctum::actingAs($restaurant->user);
        $this->postJson("/api/orders/{$orderId}/confirm")->assertOk();
        $this->postJson("/api/orders/{$orderId}/account", [
            'vat_rate' => 10,
            'discount_type' => 'fixed',
            'discount_value' => 2,
        ])->assertOk();

        $finalizeResponse = $this->postJson("/api/table-sessions/{$session->id}/finalize", [
            'payment_method' => 'card',
            'payment_reference' => 'CARD-20260115-1',
        ]);

        $finalizeResponse->assertOk()
            ->assertJsonPath('invoice_number', 'INV-20260115-000001')
            ->assertJsonPath('invoice_status', Invoice::STATUS_PAID);

        $invoiceId = $finalizeResponse->json('invoice_id');
        $this->assertIsInt($invoiceId);

        $showResponse = $this->getJson("/api/admin/finance/invoices/{$invoiceId}");

        $showResponse->assertOk()
            ->assertJsonPath('invoice.status', Invoice::STATUS_PAID)
            ->assertJsonPath('invoice.subtotal', '25.00')
            ->assertJsonPath('invoice.discount_amount', '2.00')
            ->assertJsonPath('invoice.taxable_subtotal', '23.00')
            ->assertJsonPath('invoice.vat_amount', '2.30')
            ->assertJsonPath('invoice.total', '25.30')
            ->assertJsonPath('invoice.items.0.line_total', '25.00');
    }

    public function test_finalize_rolls_up_multiple_accounted_orders_excludes_unpaid_orders_and_reuses_existing_invoice(): void
    {
        $restaurant = $this->createRestaurant();
        $dishA = $this->createDish($restaurant, 'Paid Order A', 10.00);
        $dishB = $this->createDish($restaurant, 'Paid Order B', 7.50);
        $dishC = $this->createDish($restaurant, 'Unpaid Order', 12.00);
        ['session' => $session, 'token' => $token] = $this->openGuestAccess($restaurant, 1);

        $firstOrderId = $this->postJson("/api/table-session/{$session->id}/order", [
            'items' => [
                ['dish_id' => $dishA->id, 'quantity' => 1],
            ],
        ], $this->guestHeaders($token))->json('order.id');

        $secondOrderId = $this->postJson("/api/table-session/{$session->id}/order", [
            'items' => [
                ['dish_id' => $dishB->id, 'quantity' => 1],
            ],
        ], $this->guestHeaders($token))->json('order.id');

        $unpaidOrderId = $this->postJson("/api/table-session/{$session->id}/order", [
            'items' => [
                ['dish_id' => $dishC->id, 'quantity' => 1],
            ],
        ], $this->guestHeaders($token))->json('order.id');

        $this->assertIsInt($firstOrderId);
        $this->assertIsInt($secondOrderId);
        $this->assertIsInt($unpaidOrderId);

        Sanctum::actingAs($restaurant->user);
        foreach ([$firstOrderId, $secondOrderId, $unpaidOrderId] as $orderId) {
            $this->postJson("/api/orders/{$orderId}/confirm")->assertOk();
        }

        foreach ([$firstOrderId, $secondOrderId] as $orderId) {
            $this->postJson("/api/orders/{$orderId}/account", [])->assertOk();
        }

        $firstFinalize = $this->postJson("/api/table-sessions/{$session->id}/finalize", [
            'payment_method' => 'card',
            'payment_reference' => 'CARD-20260115-2',
        ]);

        $secondFinalize = $this->postJson("/api/table-sessions/{$session->id}/finalize", [
            'payment_method' => 'card',
            'payment_reference' => 'CARD-20260115-2',
        ]);

        $firstFinalize->assertOk()
            ->assertJsonPath('invoice_number', 'INV-20260115-000001');
        $secondFinalize->assertOk()
            ->assertJsonPath('invoice_id', $firstFinalize->json('invoice_id'))
            ->assertJsonPath('invoice_number', $firstFinalize->json('invoice_number'));

        $this->assertSame(1, Invoice::query()->count());

        $invoiceId = $firstFinalize->json('invoice_id');
        $this->assertIsInt($invoiceId);

        $this->getJson("/api/admin/finance/invoices/{$invoiceId}")
            ->assertOk()
            ->assertJsonPath('invoice.subtotal', '17.50')
            ->assertJsonPath('invoice.total', '17.50')
            ->assertJsonCount(2, 'invoice.items');

        $this->assertDatabaseHas('orders', [
            'id' => $firstOrderId,
            'invoice_number' => 'INV-20260115-000001',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $secondOrderId,
            'invoice_number' => 'INV-20260115-000001',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $unpaidOrderId,
            'status' => Order::STATUS_STAFF_CONFIRMED,
            'invoice_number' => 'INV-20260115-000003',
        ]);
    }

    public function test_finalize_returns_null_invoice_when_session_has_no_accounted_orders(): void
    {
        $restaurant = $this->createRestaurant();
        $dish = $this->createDish($restaurant, 'Confirmed Only Dish', 9.00);
        ['session' => $session, 'token' => $token] = $this->openGuestAccess($restaurant, 1);

        $orderId = $this->postJson("/api/table-session/{$session->id}/order", [
            'items' => [
                ['dish_id' => $dish->id, 'quantity' => 1],
            ],
        ], $this->guestHeaders($token))->json('order.id');

        $this->assertIsInt($orderId);

        Sanctum::actingAs($restaurant->user);
        $this->postJson("/api/orders/{$orderId}/confirm")->assertOk();

        $this->postJson("/api/table-sessions/{$session->id}/finalize", [
            'payment_method' => 'cash',
        ])->assertOk()
            ->assertJsonPath('invoice_id', null)
            ->assertJsonPath('invoice_number', null)
            ->assertJsonPath('invoice_status', null);

        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_finalize_preserves_cancelled_items_with_zero_line_totals(): void
    {
        $restaurant = $this->createRestaurant();
        $paidDish = $this->createDish($restaurant, 'Paid Dish', 15.00);
        $cancelledDish = $this->createDish($restaurant, 'Cancelled Dish', 5.00);
        ['session' => $session, 'token' => $token] = $this->openGuestAccess($restaurant, 1);

        $orderId = $this->postJson("/api/table-session/{$session->id}/order", [
            'items' => [
                ['dish_id' => $paidDish->id, 'quantity' => 1],
                ['dish_id' => $cancelledDish->id, 'quantity' => 1],
            ],
        ], $this->guestHeaders($token))->json('order.id');

        $this->assertIsInt($orderId);

        Sanctum::actingAs($restaurant->user);
        $this->postJson("/api/orders/{$orderId}/confirm")->assertOk();

        $orderItems = OrderItem::query()
            ->where('order_id', $orderId)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $orderItems);

        $this->postJson("/api/orders/{$orderId}/account", [
            'vat_rate' => 10,
            'items' => [
                [
                    'order_item_id' => $orderItems[0]->id,
                    'dish_id' => $paidDish->id,
                    'quantity' => 1,
                    'status' => 'normal',
                    'compensation_type' => 'none',
                ],
                [
                    'order_item_id' => $orderItems[1]->id,
                    'dish_id' => $cancelledDish->id,
                    'quantity' => 1,
                    'status' => 'cancelled',
                    'compensation_type' => 'full_waiver',
                    'compensation_reason' => 'Kitchen issue',
                ],
            ],
        ])->assertOk();

        $finalizeResponse = $this->postJson("/api/table-sessions/{$session->id}/finalize", [
            'payment_method' => 'card',
            'payment_reference' => 'CARD-20260115-3',
        ])->assertOk();

        $invoiceId = $finalizeResponse->json('invoice_id');
        $this->assertIsInt($invoiceId);

        $this->getJson("/api/admin/finance/invoices/{$invoiceId}")
            ->assertOk()
            ->assertJsonPath('invoice.subtotal', '15.00')
            ->assertJsonPath('invoice.vat_amount', '1.50')
            ->assertJsonPath('invoice.total', '16.50')
            ->assertJsonPath('invoice.items.0.line_total', '15.00')
            ->assertJsonPath('invoice.items.1.line_total', '0.00')
            ->assertJsonPath('invoice.items.1.status', 'cancelled');
    }

    public function test_finalize_persists_service_charge_currency_exchange_rate_and_wallet_payment(): void
    {
        $restaurant = $this->createRestaurant(attributes: [
            'currency' => 'LBP',
            'dollar_rate' => '89500.75',
        ]);
        $dish = $this->createDish($restaurant, 'Festival Tray', 20.00);
        ['session' => $session, 'token' => $token] = $this->openGuestAccess($restaurant, 1);

        $orderId = $this->postJson("/api/table-session/{$session->id}/order", [
            'items' => [
                ['dish_id' => $dish->id, 'quantity' => 2],
            ],
        ], $this->guestHeaders($token))->json('order.id');

        $this->assertIsInt($orderId);

        Sanctum::actingAs($restaurant->user);
        $this->postJson("/api/orders/{$orderId}/confirm")->assertOk();
        $this->postJson("/api/orders/{$orderId}/account", [
            'vat_rate' => 5,
            'service_charge_rate' => 10,
            'discount_type' => 'fixed',
            'discount_value' => 2.5,
        ])->assertOk();

        $finalizeResponse = $this->postJson("/api/table-sessions/{$session->id}/finalize", [
            'payment_method' => 'wallet',
            'payment_reference' => 'WALLET-20260115-1',
        ])->assertOk();

        $invoiceId = $finalizeResponse->json('invoice_id');
        $this->assertIsInt($invoiceId);

        $this->getJson("/api/admin/finance/invoices/{$invoiceId}")
            ->assertOk()
            ->assertJsonPath('invoice.currency', 'LBP')
            ->assertJsonPath('invoice.exchange_rate', '89500.7500')
            ->assertJsonPath('invoice.payment_method', 'wallet')
            ->assertJsonPath('invoice.payment_reference', 'WALLET-20260115-1')
            ->assertJsonPath('invoice.subtotal', '40.00')
            ->assertJsonPath('invoice.discount_amount', '2.50')
            ->assertJsonPath('invoice.taxable_subtotal', '37.50')
            ->assertJsonPath('invoice.service_charge_rate', '10.00')
            ->assertJsonPath('invoice.service_charge_amount', '3.75')
            ->assertJsonPath('invoice.vat_rate', '5.00')
            ->assertJsonPath('invoice.vat_amount', '1.88')
            ->assertJsonPath('invoice.total', '43.13');
    }
}
