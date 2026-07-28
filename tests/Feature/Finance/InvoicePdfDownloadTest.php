<?php

namespace Tests\Feature\Finance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Feature\Concerns\BuildsRestaurantOrderFlow;
use Tests\TestCase;

class InvoicePdfDownloadTest extends TestCase
{
    use BuildsRestaurantOrderFlow;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::disk('local')->deleteDirectory('invoices');
        Storage::disk('public')->deleteDirectory('logos/invoice-tests');
    }

    public function test_manual_invoice_pdf_download_generates_standard_pdf_with_mixed_language_content_and_large_item_lists(): void
    {
        $this->requirePdfTooling();

        $restaurant = $this->createRestaurant(attributes: [
            'name' => 'The Cedar Ledger House مطعم دفتر الأرز الطويل للغاية للاختبار',
        ]);

        Sanctum::actingAs($restaurant->user);

        $items = [
            [
                'name' => 'English Coffee',
                'quantity' => 1,
                'unit_price' => '4.50',
            ],
            [
                'name' => 'Arabic Tea شاي',
                'quantity' => 2,
                'unit_price' => '2.25',
            ],
        ];

        foreach (range(1, 18) as $index) {
            $items[] = [
                'name' => sprintf('Line Item %02d', $index),
                'quantity' => 1,
                'unit_price' => '1.10',
            ];
        }

        $invoiceId = $this->postJson('/api/admin/finance/invoices', [
            'invoice_date' => '2026-01-15',
            'status' => 'issued',
            'vat_rate' => 10,
            'service_charge_rate' => 5,
            'discount_type' => 'fixed',
            'discount_value' => 3,
            'currency' => 'EUR',
            'exchange_rate' => 1.2345,
            'payment_method' => 'card',
            'payment_reference' => 'PDF-STANDARD-1',
            'items' => $items,
        ])->assertCreated()->json('invoice.id');

        $this->assertIsInt($invoiceId);

        $response = $this->get("/api/admin/finance/invoices/{$invoiceId}/pdf");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');

        $invoice = $restaurant->invoices()->findOrFail($invoiceId);
        $this->assertSame('local', $invoice->pdf_disk);
        $this->assertIsString($invoice->pdf_path);
        $this->assertStringStartsWith("invoices/{$restaurant->id}/", $invoice->pdf_path);
        $this->assertNotNull($invoice->pdf_generated_at);
        Storage::disk('local')->assertExists($invoice->pdf_path);
        Storage::disk('public')->assertMissing($invoice->pdf_path);

        $text = $this->extractPdfText(Storage::disk('local')->path($invoice->pdf_path));

        $this->assertStringContainsString('Cedar Ledger House', $text);
        $this->assertStringContainsString('مطعم', $text);
        $this->assertStringContainsString('English Coffee', $text);
        $this->assertStringContainsString('شاي', $text);
        $this->assertStringContainsString('Line Item 18', $text);
        $this->assertStringContainsString('PDF-STANDARD-1', $text);
    }

    public function test_repeated_invoice_pdf_download_reuses_cached_private_file(): void
    {
        $this->requirePdfTooling();

        $restaurant = $this->createRestaurant();
        Sanctum::actingAs($restaurant->user);

        $invoiceId = $this->postJson('/api/admin/finance/invoices', [
            'invoice_date' => '2026-01-15',
            'items' => [
                [
                    'name' => 'Cached PDF Invoice',
                    'quantity' => 1,
                    'unit_price' => '9.99',
                ],
            ],
        ])->assertCreated()->json('invoice.id');

        $this->assertIsInt($invoiceId);

        $this->get("/api/admin/finance/invoices/{$invoiceId}/pdf")->assertOk();

        $firstInvoice = $restaurant->invoices()->findOrFail($invoiceId);
        $this->assertIsString($firstInvoice->pdf_path);
        $this->assertSame('local', $firstInvoice->pdf_disk);
        Storage::disk('local')->assertExists($firstInvoice->pdf_path);
        Storage::disk('public')->assertMissing($firstInvoice->pdf_path);

        $firstGeneratedAt = $firstInvoice->pdf_generated_at?->toIso8601String();
        $firstPath = $firstInvoice->pdf_path;

        $this->travel(2)->seconds();

        $this->get("/api/admin/finance/invoices/{$invoiceId}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $secondInvoice = $restaurant->invoices()->findOrFail($invoiceId);

        $this->assertSame($firstPath, $secondInvoice->pdf_path);
        $this->assertSame($firstGeneratedAt, $secondInvoice->pdf_generated_at?->toIso8601String());
    }

    public function test_split_invoice_pdf_download_includes_split_summary_and_restaurant_scoped_storage(): void
    {
        $this->requirePdfTooling();

        $restaurant = $this->createRestaurant(attributes: [
            'name' => 'Split House بيت التقسيم',
        ]);
        $dishA = $this->createDish($restaurant, 'Family Kebab كباب', 10.00);
        $dishB = $this->createDish($restaurant, 'Mint Tea شاي', 5.00);
        ['session' => $session, 'token' => $token] = $this->openGuestAccess($restaurant, 1);

        $orderId = $this->postJson("/api/table-session/{$session->id}/order", [
            'items' => [
                ['dish_id' => $dishA->id, 'quantity' => 2],
                ['dish_id' => $dishB->id, 'quantity' => 1],
            ],
        ], $this->guestHeaders($token))->assertCreated()->json('order.id');

        $this->assertIsInt($orderId);

        Sanctum::actingAs($restaurant->user);
        $this->postJson("/api/orders/{$orderId}/confirm")->assertOk();

        $orderItems = \App\Models\OrderItem::query()
            ->where('order_id', $orderId)
            ->orderBy('id')
            ->get();

        $this->patchJson("/api/table-session/{$session->id}/invoice-split", [
            'mode' => 'by_person_order',
            'split_count' => 2,
            'people' => [
                [
                    'person_index' => 1,
                    'items' => [
                        ['order_item_id' => $orderItems[0]->id, 'quantity' => 1],
                    ],
                ],
                [
                    'person_index' => 2,
                    'items' => [
                        ['order_item_id' => $orderItems[0]->id, 'quantity' => 1],
                        ['order_item_id' => $orderItems[1]->id, 'quantity' => 1],
                    ],
                ],
            ],
        ], $this->guestHeaders($token))->assertOk();

        $this->postJson("/api/orders/{$orderId}/account", [
            'vat_rate' => 5,
            'service_charge_rate' => 10,
            'discount_type' => 'fixed',
            'discount_value' => 2,
        ])->assertOk();

        $invoiceId = $this->postJson("/api/table-sessions/{$session->id}/finalize", [
            'payment_method' => 'card',
            'payment_reference' => 'PDF-SPLIT-1',
        ])->assertOk()->json('invoice_id');

        $this->assertIsInt($invoiceId);

        $response = $this->get("/api/admin/finance/invoices/{$invoiceId}/pdf");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');

        $invoice = $restaurant->invoices()->findOrFail($invoiceId);
        $this->assertSame('local', $invoice->pdf_disk);
        $this->assertIsString($invoice->pdf_path);
        $this->assertStringStartsWith("invoices/{$restaurant->id}/", $invoice->pdf_path);
        Storage::disk('local')->assertExists($invoice->pdf_path);
        Storage::disk('public')->assertMissing($invoice->pdf_path);

        $text = $this->extractPdfText(Storage::disk('local')->path($invoice->pdf_path));

        $this->assertStringContainsString('Split House', $text);
        $this->assertStringContainsString('بيت', $text);
        $this->assertStringContainsString('Split Summary', $text);
        $this->assertStringContainsString('Person 1', $text);
        $this->assertStringContainsString('Person 2', $text);
        $this->assertStringContainsString('Family Kebab', $text);
        $this->assertStringContainsString('شاي', $text);
    }

    public function test_invoice_pdf_download_requires_auth_and_is_tenant_isolated_and_rejects_invalid_ids(): void
    {
        $restaurantA = $this->createRestaurant();
        $restaurantB = $this->createRestaurant();

        Sanctum::actingAs($restaurantA->user);
        $invoiceId = $this->postJson('/api/admin/finance/invoices', [
            'invoice_date' => '2026-01-15',
            'items' => [
                [
                    'name' => 'Protected Invoice',
                    'quantity' => 1,
                    'unit_price' => '9.99',
                ],
            ],
        ])->assertCreated()->json('invoice.id');

        $this->assertIsInt($invoiceId);

        auth()->forgetGuards();
        $this->get("/api/admin/finance/invoices/{$invoiceId}/pdf")->assertStatus(401);

        Sanctum::actingAs($restaurantB->user);
        $this->get("/api/admin/finance/invoices/{$invoiceId}/pdf")->assertStatus(404);
        $this->get('/api/admin/finance/invoices/999999/pdf')->assertStatus(404);
    }

    public function test_invoice_pdf_download_returns_server_error_when_generation_fails(): void
    {
        $restaurant = $this->createRestaurant();
        Sanctum::actingAs($restaurant->user);

        $invoiceId = $this->postJson('/api/admin/finance/invoices', [
            'invoice_date' => '2026-01-15',
            'items' => [
                [
                    'name' => 'Failure Invoice',
                    'quantity' => 1,
                    'unit_price' => '5.00',
                ],
            ],
        ])->assertCreated()->json('invoice.id');

        $this->assertIsInt($invoiceId);

        config(['services.invoice_pdf.force_failure' => true]);

        $this->get("/api/admin/finance/invoices/{$invoiceId}/pdf")->assertStatus(500);
    }

    private function requirePdfTooling(): void
    {
        $browser = (new ExecutableFinder())->find('google-chrome')
            ?? (new ExecutableFinder())->find('google-chrome-stable')
            ?? (new ExecutableFinder())->find('chromium-browser')
            ?? (new ExecutableFinder())->find('chromium');
        $pdfToText = (new ExecutableFinder())->find('pdftotext');

        if (! is_string($browser) || $browser === '' || ! is_string($pdfToText) || $pdfToText === '') {
            $this->markTestSkipped('Invoice PDF tooling is not installed in this environment.');
        }
    }

    private function extractPdfText(string $pdfPath): string
    {
        $process = new Process(['pdftotext', '-layout', $pdfPath, '-']);
        $process->setTimeout(120);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        return $process->getOutput();
    }
}
