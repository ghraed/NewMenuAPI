<?php

namespace Tests\Feature\Finance;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\PosComplaintAdjustment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('statusTransitionProvider')]
    public function test_invoice_status_transition_policy_is_enforced(
        string $currentStatus,
        string $nextStatus,
        bool $allowed
    ): void {
        $restaurant = $this->createRestaurant();
        Sanctum::actingAs($restaurant->user);

        $invoice = Invoice::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'invoice_number' => 'QA_RUN_TRANSITION_'.$currentStatus.'_'.$nextStatus,
            'invoice_date' => '2026-01-15',
            'status' => $currentStatus,
            'subtotal' => '10.00',
            'total' => '10.00',
            'paid_at' => $currentStatus === Invoice::STATUS_PAID ? now() : null,
        ]);

        $response = $this->patchJson("/api/admin/finance/invoices/{$invoice->id}", [
            'status' => $nextStatus,
        ]);

        if ($allowed) {
            $response->assertOk()->assertJsonPath('invoice.status', $nextStatus);
            $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => $nextStatus]);
        } else {
            $response->assertUnprocessable()->assertJsonValidationErrors(['status']);
            $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => $currentStatus]);
        }
    }

    public static function statusTransitionProvider(): array
    {
        $allowed = [
            Invoice::STATUS_DRAFT => [Invoice::STATUS_DRAFT, Invoice::STATUS_ISSUED, Invoice::STATUS_CANCELLED],
            Invoice::STATUS_ISSUED => [Invoice::STATUS_ISSUED, Invoice::STATUS_PAID, Invoice::STATUS_CANCELLED],
            Invoice::STATUS_PAID => [Invoice::STATUS_PAID],
            Invoice::STATUS_CANCELLED => [Invoice::STATUS_CANCELLED],
        ];
        $statuses = [Invoice::STATUS_DRAFT, Invoice::STATUS_ISSUED, Invoice::STATUS_PAID, Invoice::STATUS_CANCELLED];
        $rows = [];

        foreach ($statuses as $currentStatus) {
            foreach ($statuses as $nextStatus) {
                $rows["{$currentStatus} -> {$nextStatus}"] = [
                    $currentStatus,
                    $nextStatus,
                    in_array($nextStatus, $allowed[$currentStatus], true),
                ];
            }
        }

        return $rows;
    }

    #[DataProvider('terminalMutationProvider')]
    public function test_terminal_invoices_reject_accounting_mutations_without_changing_database_or_pdf_state(
        string $status,
        array $mutation
    ): void {
        $restaurant = $this->createRestaurant();
        Sanctum::actingAs($restaurant->user);

        $invoice = Invoice::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'invoice_number' => 'QA_RUN_TERMINAL_'.strtoupper($status).'_'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(6)),
            'invoice_date' => '2026-01-15',
            'status' => $status,
            'subtotal' => '20.00',
            'discount_type' => 'fixed',
            'discount_value' => '2.00',
            'discount_amount' => '2.00',
            'taxable_subtotal' => '18.00',
            'service_charge_rate' => '5.00',
            'service_charge_amount' => '0.90',
            'vat_rate' => '10.00',
            'vat_amount' => '1.80',
            'total' => '20.70',
            'currency' => 'USD',
            'exchange_rate' => '1.0000',
            'payment_method' => 'card',
            'payment_reference' => 'QA_RUN_PAYMENT',
            'pdf_disk' => 'local',
            'pdf_path' => 'invoices/qa-run-terminal.pdf',
            'pdf_generated_at' => '2026-01-15 12:00:00',
            'paid_at' => $status === Invoice::STATUS_PAID ? '2026-01-15 12:00:00' : null,
        ]);
        $invoice->items()->create([
            'name' => 'QA_RUN_ORIGINAL_ITEM',
            'quantity' => '2.000',
            'unit_price' => '10.00',
            'line_total' => '20.00',
            'order_index' => 0,
        ]);

        $beforeInvoice = $invoice->fresh()->getRawOriginal();
        $beforeItems = $invoice->items()->orderBy('id')->get()->map->getRawOriginal()->all();

        $this->patchJson("/api/admin/finance/invoices/{$invoice->id}", $mutation)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(array_keys($mutation));

        $this->assertSame($beforeInvoice, $invoice->fresh()->getRawOriginal());
        $this->assertSame($beforeItems, $invoice->items()->orderBy('id')->get()->map->getRawOriginal()->all());
    }

    public static function terminalMutationProvider(): array
    {
        $mutations = [
            'items' => ['items' => [['name' => 'QA_RUN_CHANGED_ITEM', 'quantity' => 1, 'unit_price' => 99]]],
            'discount tax and service' => [
                'discount_type' => 'percentage',
                'discount_value' => 25,
                'vat_rate' => 15,
                'service_charge_rate' => 12,
            ],
            'currency and exchange rate' => ['currency' => 'EUR', 'exchange_rate' => 1.25],
            'payment fields' => ['payment_method' => 'cash', 'payment_reference' => 'QA_RUN_CHANGED_PAYMENT'],
            'invoice date' => ['invoice_date' => '2026-02-01'],
        ];
        $rows = [];

        foreach ([Invoice::STATUS_PAID, Invoice::STATUS_CANCELLED] as $status) {
            foreach ($mutations as $label => $mutation) {
                $rows["{$status}: {$label}"] = [$status, $mutation];
            }
        }

        return $rows;
    }

    #[DataProvider('terminalStatusProvider')]
    public function test_terminal_invoices_allow_notes_only_without_invalidating_pdf(string $status): void
    {
        $restaurant = $this->createRestaurant();
        Sanctum::actingAs($restaurant->user);

        $invoice = Invoice::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'invoice_number' => 'QA_RUN_TERMINAL_NOTE_'.strtoupper($status),
            'invoice_date' => '2026-01-15',
            'status' => $status,
            'subtotal' => '10.00',
            'total' => '10.00',
            'pdf_disk' => 'local',
            'pdf_path' => 'invoices/qa-run-terminal-note.pdf',
            'pdf_generated_at' => '2026-01-15 12:00:00',
            'paid_at' => $status === Invoice::STATUS_PAID ? '2026-01-15 12:00:00' : null,
        ]);

        $this->patchJson("/api/admin/finance/invoices/{$invoice->id}", [
            'status' => $status,
            'notes' => 'QA_RUN audit annotation',
        ])->assertOk()
            ->assertJsonPath('invoice.status', $status)
            ->assertJsonPath('invoice.notes', 'QA_RUN audit annotation')
            ->assertJsonPath('invoice.pdf_available', true);

        $invoice->refresh();
        $this->assertSame('local', $invoice->pdf_disk);
        $this->assertSame('invoices/qa-run-terminal-note.pdf', $invoice->pdf_path);
        $this->assertSame('2026-01-15 12:00:00', $invoice->getRawOriginal('pdf_generated_at'));
    }

    public static function terminalStatusProvider(): array
    {
        return [
            'paid' => [Invoice::STATUS_PAID],
            'cancelled' => [Invoice::STATUS_CANCELLED],
        ];
    }

    public function test_revenue_trends_include_only_issued_and_paid_and_subtract_only_posted_refunds_by_posted_date(): void
    {
        $restaurant = $this->createRestaurant();
        Sanctum::actingAs($restaurant->user);

        foreach ([
            [Invoice::STATUS_DRAFT, '90.00'],
            [Invoice::STATUS_ISSUED, '100.00'],
            [Invoice::STATUS_PAID, '50.00'],
            [Invoice::STATUS_CANCELLED, '80.00'],
        ] as $index => [$status, $total]) {
            Invoice::query()->create([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'restaurant_id' => $restaurant->id,
                'invoice_number' => "QA_RUN_REVENUE_{$index}",
                'invoice_date' => '2026-01-15',
                'status' => $status,
                'subtotal' => $total,
                'total' => $total,
            ]);
        }

        $order = Order::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'order_number' => 'QA_RUN_REFUND_ORDER',
            'status' => Order::STATUS_ACCOUNTED,
            'guest_name' => 'QA_RUN_FINANCE',
            'subtotal' => '150.00',
            'taxable_subtotal' => '150.00',
            'total' => '150.00',
        ]);

        foreach ([
            ['draft', '40.00'],
            ['posted', '25.00'],
            ['void', '30.00'],
        ] as $index => [$status, $refundAmount]) {
            PosComplaintAdjustment::query()->create([
                'restaurant_id' => $restaurant->id,
                'original_order_id' => $order->id,
                'status' => $status,
                'complaint_reason' => 'QA_RUN finance parity',
                'accounting_bucket' => 'customer_complaint_loss',
                'refund_amount' => $refundAmount,
                'refund_payment_method' => 'cash',
                'affected_items' => [],
                'created_by' => $restaurant->user->id,
                'posted_at' => $status === 'posted' ? '2026-01-16 00:00:00' : null,
                'voided_at' => $status === 'void' ? '2026-01-16 00:00:00' : null,
            ]);
        }

        $this->getJson('/api/admin/finance/invoices/revenue-trends?range=daily&date_from=2026-01-15&date_to=2026-01-16')
            ->assertOk()
            ->assertJsonPath('points.0.bucket', '2026-01-15')
            ->assertJsonPath('points.0.gross_revenue', 150)
            ->assertJsonPath('points.0.refunds', 0)
            ->assertJsonPath('points.0.revenue', 150)
            ->assertJsonPath('points.0.invoice_count', 2)
            ->assertJsonPath('points.1.bucket', '2026-01-16')
            ->assertJsonPath('points.1.gross_revenue', 0)
            ->assertJsonPath('points.1.refunds', 25)
            ->assertJsonPath('points.1.revenue', -25)
            ->assertJsonPath('totals.revenue', 125)
            ->assertJsonPath('totals.invoice_count', 2);
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
