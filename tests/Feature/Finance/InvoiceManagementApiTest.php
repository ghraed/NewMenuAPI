<?php

namespace Tests\Feature\Finance;

use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\BuildsRestaurantOrderFlow;
use Tests\TestCase;

class InvoiceManagementApiTest extends TestCase
{
    use BuildsRestaurantOrderFlow;
    use RefreshDatabase;

    public function test_admin_can_create_manual_invoice_with_exact_decimal_rounding_and_sequential_numbers(): void
    {
        $restaurant = $this->createRestaurant();

        Sanctum::actingAs($restaurant->user);

        $firstResponse = $this->postJson('/api/admin/finance/invoices', [
            'invoice_date' => '2026-01-15',
            'status' => Invoice::STATUS_PAID,
            'notes' => 'Arabic English invoice',
            'items' => [
                [
                    'name' => 'Mix Grill',
                    'quantity' => '1.125',
                    'unit_price' => '3.33',
                ],
                [
                    'name' => 'Fresh Juice',
                    'quantity' => 2,
                    'unit_price' => '2.50',
                ],
            ],
        ]);

        $firstResponse->assertCreated()
            ->assertJsonPath('invoice.invoice_number', 'INV-20260115-0001')
            ->assertJsonPath('invoice.status', Invoice::STATUS_PAID)
            ->assertJsonPath('invoice.subtotal', '8.75')
            ->assertJsonPath('invoice.total', '8.75')
            ->assertJsonPath('invoice.items.0.quantity', '1.125')
            ->assertJsonPath('invoice.items.0.line_total', '3.75')
            ->assertJsonPath('invoice.items.1.line_total', '5.00');

        $this->assertNotNull($firstResponse->json('invoice.paid_at'));

        $secondResponse = $this->postJson('/api/admin/finance/invoices', [
            'invoice_date' => '2026-01-15',
            'status' => Invoice::STATUS_ISSUED,
            'items' => [
                [
                    'name' => 'Water',
                    'quantity' => 1,
                    'unit_price' => '1.00',
                ],
            ],
        ]);

        $secondResponse->assertCreated()
            ->assertJsonPath('invoice.invoice_number', 'INV-20260115-0002')
            ->assertJsonPath('invoice.subtotal', '1.00')
            ->assertJsonPath('invoice.total', '1.00');
    }

    public function test_manual_invoice_update_recalculates_totals_and_paid_timestamp_from_server_side_items(): void
    {
        $restaurant = $this->createRestaurant();
        Sanctum::actingAs($restaurant->user);

        $createResponse = $this->postJson('/api/admin/finance/invoices', [
            'invoice_date' => '2026-01-15',
            'status' => Invoice::STATUS_ISSUED,
            'items' => [
                [
                    'name' => 'Original',
                    'quantity' => 1,
                    'unit_price' => '10.00',
                ],
            ],
        ])->assertCreated();

        $invoiceId = $createResponse->json('invoice.id');
        $this->assertIsInt($invoiceId);

        $updateResponse = $this->patchJson("/api/admin/finance/invoices/{$invoiceId}", [
            'status' => Invoice::STATUS_PAID,
            'items' => [
                [
                    'name' => 'Weighted Item',
                    'quantity' => '1.125',
                    'unit_price' => '3.33',
                    'line_total' => '0.01',
                ],
                [
                    'name' => 'Coffee',
                    'quantity' => 3,
                    'unit_price' => '2.13',
                    'line_total' => '0.01',
                ],
            ],
        ]);

        $updateResponse->assertOk()
            ->assertJsonPath('invoice.status', Invoice::STATUS_PAID)
            ->assertJsonPath('invoice.subtotal', '10.14')
            ->assertJsonPath('invoice.total', '10.14')
            ->assertJsonPath('invoice.items.0.line_total', '3.75')
            ->assertJsonPath('invoice.items.1.line_total', '6.39');

        $this->assertNotNull($updateResponse->json('invoice.paid_at'));
    }

    public function test_manual_invoice_store_persists_service_charge_currency_exchange_rate_and_exact_totals(): void
    {
        $restaurant = $this->createRestaurant(attributes: [
            'currency' => 'LBP',
            'dollar_rate' => '89500.75',
        ]);

        Sanctum::actingAs($restaurant->user);

        $response = $this->postJson('/api/admin/finance/invoices', [
            'invoice_date' => '2026-01-15',
            'status' => Invoice::STATUS_ISSUED,
            'vat_rate' => 10,
            'service_charge_rate' => 5.5,
            'discount_type' => 'percentage',
            'discount_value' => 12.5,
            'currency' => 'EUR',
            'exchange_rate' => 1.2345,
            'payment_method' => 'card',
            'payment_reference' => 'REF-INV-100',
            'items' => [
                [
                    'name' => 'Family Meal',
                    'quantity' => 2,
                    'unit_price' => '10.00',
                ],
                [
                    'name' => 'Fresh Juice',
                    'quantity' => '1.125',
                    'unit_price' => '3.33',
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('invoice.subtotal', '23.75')
            ->assertJsonPath('invoice.discount_type', 'percentage')
            ->assertJsonPath('invoice.discount_value', '12.50')
            ->assertJsonPath('invoice.discount_amount', '2.97')
            ->assertJsonPath('invoice.taxable_subtotal', '20.78')
            ->assertJsonPath('invoice.service_charge_rate', '5.50')
            ->assertJsonPath('invoice.service_charge_amount', '1.14')
            ->assertJsonPath('invoice.vat_rate', '10.00')
            ->assertJsonPath('invoice.vat_amount', '2.08')
            ->assertJsonPath('invoice.total', '24.00')
            ->assertJsonPath('invoice.currency', 'EUR')
            ->assertJsonPath('invoice.exchange_rate', '1.2345')
            ->assertJsonPath('invoice.payment_method', 'card')
            ->assertJsonPath('invoice.payment_reference', 'REF-INV-100')
            ->assertJsonPath('invoice.pdf_available', false);
    }

    public function test_manual_invoice_store_rejects_empty_items_and_invalid_ids(): void
    {
        $restaurant = $this->createRestaurant();
        Sanctum::actingAs($restaurant->user);

        $this->postJson('/api/admin/finance/invoices', [
            'invoice_date' => '2026-01-15',
            'items' => [],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['items']);

        $this->getJson('/api/admin/finance/invoices/999999')->assertStatus(404);
    }

    public function test_manual_invoice_routes_are_tenant_isolated_and_require_authentication(): void
    {
        $restaurantA = $this->createRestaurant();
        $restaurantB = $this->createRestaurant();

        Sanctum::actingAs($restaurantA->user);
        $createResponse = $this->postJson('/api/admin/finance/invoices', [
            'invoice_date' => '2026-01-15',
            'items' => [
                [
                    'name' => 'Tenant A Invoice',
                    'quantity' => 1,
                    'unit_price' => '9.99',
                ],
            ],
        ])->assertCreated();

        $invoiceId = $createResponse->json('invoice.id');
        $this->assertIsInt($invoiceId);

        auth()->forgetGuards();
        $this->getJson("/api/admin/finance/invoices/{$invoiceId}")->assertStatus(401);

        Sanctum::actingAs($restaurantB->user);
        $this->getJson("/api/admin/finance/invoices/{$invoiceId}")->assertStatus(404);
        $this->patchJson("/api/admin/finance/invoices/{$invoiceId}", [
            'notes' => 'Cross-tenant overwrite attempt',
        ])->assertStatus(404);
    }
}
