<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\DishIngredient;
use App\Models\Feature;
use App\Models\Ingredient;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RestaurantFeature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PosCheckoutCompensationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Restaurant $restaurant;

    private Dish $dish;

    private Ingredient $ingredient;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'QA_RUN_'.(getenv('QA_RUN_ID') ?: 'pos').'_'.bin2hex(random_bytes(4));
        $this->admin = User::factory()->admin()->create(['name' => $this->prefix, 'email' => $this->prefix.'@example.invalid']);
        $this->restaurant = Restaurant::factory()->create(['user_id' => $this->admin->id, 'name' => $this->prefix, 'slug' => strtolower($this->prefix), 'currency' => 'EUR', 'dollar_rate' => '1.23']);
        foreach (['table_ordering', 'finance_dashboard', 'expense_management', 'vat_invoices', 'ingredient_stock_deduction'] as $key) {
            $feature = Feature::query()->firstOrCreate(['key' => $key], ['name' => $key, 'category' => 'Testing', 'is_active_by_default' => false]);
            RestaurantFeature::query()->create(['restaurant_id' => $this->restaurant->id, 'feature_id' => $feature->id, 'enabled' => true]);
        }
        $this->dish = Dish::factory()->published()->create(['restaurant_id' => $this->restaurant->id, 'name' => $this->prefix.'_dish', 'price' => '10.00']);
        $this->ingredient = Ingredient::factory()->pieces(20)->create(['restaurant_id' => $this->restaurant->id, 'name' => $this->prefix]);
        DishIngredient::query()->create(['dish_id' => $this->dish->id, 'ingredient_id' => $this->ingredient->id, 'quantity' => '1.000', 'unit' => Ingredient::UNIT_PIECE]);
        Sanctum::actingAs($this->admin);
    }

    private function payload(array $intent = [], array $extra = []): array
    {
        return array_replace(['table_reference' => 'POS-WALK-IN', 'payment_method' => 'cash', 'items' => [array_merge(['dish_id' => $this->dish->id, 'quantity' => 1], $intent)]], $extra);
    }

    public static function modes(): array
    {
        return [
            'complimentary' => [['status' => 'compensated', 'compensation_type' => 'complimentary'], '0.00'],
            'full waiver' => [['status' => 'problematic', 'compensation_type' => 'full_waiver'], '0.00'],
            'cancelled financial line' => [['status' => 'cancelled', 'compensation_type' => 'none'], '0.00'],
            'percentage' => [['status' => 'compensated', 'compensation_type' => 'partial_discount', 'partial_discount_percentage' => 33.33], '6.67'],
            'fixed per unit' => [['status' => 'problematic', 'compensation_type' => 'partial_discount', 'partial_discount_type' => 'fixed', 'partial_discount_value' => 2.55], '7.45'],
        ];
    }

    #[DataProvider('modes')]
    public function test_validated_modes_persist_exact_money_approval_and_consume_stock_once(array $intent, string $expected): void
    {
        $intent += ['compensation_reason' => 'quality_issue', 'compensation_note' => $this->prefix, 'approved_by_staff_id' => 999999, 'approved_by_staff_name' => 'FORGED', 'approved_by_staff_role' => 'super_admin', 'approved_at' => '2000-01-01', 'original_unit_price' => 0.01, 'final_unit_price' => 0.01];
        $payload = $this->payload($intent);
        $headers = ['X-Idempotency-Key' => $this->prefix];
        $response = $this->postJson('/api/pos/checkout', $payload, $headers)->assertCreated()->assertJsonPath('order.invoice.total', $expected)->assertJsonPath('payment.total', $expected);
        $id = $response->json('order.id');
        $order = Order::findOrFail($id);
        $item = $order->items()->firstOrFail();
        $this->assertSame('10.00', $item->unit_price);
        $this->assertSame('10.00', $item->original_unit_price);
        $this->assertSame($expected, $item->final_unit_price);
        $this->assertSame($expected, $item->line_subtotal);
        $this->assertSame($intent['status'], $item->status);
        $this->assertSame($intent['compensation_type'], $item->compensation_type);
        $this->assertSame($this->admin->id, $item->approved_by_staff_id);
        $this->assertSame($this->admin->name, $item->approved_by_staff_name);
        $this->assertSame('admin', $item->approved_by_staff_role);
        $this->assertTrue($item->approved_at->isToday());
        $this->assertSame('quality_issue', $item->compensation_reason);
        $this->assertSame($this->prefix, $item->compensation_note);
        $invoice = Invoice::where('invoice_number', $order->invoice_number)->firstOrFail();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame($expected, $invoice->total);
        $this->assertSame($expected, $invoice->items()->firstOrFail()->line_total);
        $this->assertSame($expected, $invoice->items()->firstOrFail()->unit_price);
        $this->assertSame('EUR', $invoice->currency);
        $this->assertSame('1.2300', $invoice->exchange_rate);
        $this->assertSame('19.000', $this->ingredient->fresh()->current_stock_quantity);
        // A lost acknowledgement may be retried even after catalog prices change.
        $this->dish->update(['price' => '99.00']);
        $this->ingredient->update(['current_stock_quantity' => '0.000']);
        $this->postJson('/api/pos/checkout', $payload, $headers)->assertOk()->assertJsonPath('order.id', $id)->assertJsonPath('payment.total', $expected);
        $this->assertSame(1, Order::count());
        $this->assertSame(1, Invoice::count());
        $this->assertSame('0.000', $this->ingredient->fresh()->current_stock_quantity);
        $this->assertSame(1, $item->ingredientUsages()->count());
    }

    public function test_mixed_cart_tax_service_order_discount_and_currency_use_adjusted_subtotal(): void
    {
        $normal = Dish::factory()->published()->create(['restaurant_id' => $this->restaurant->id, 'name' => $this->prefix.'_normal', 'price' => '3.35']);
        $payload = $this->payload(['quantity' => 2, 'status' => 'compensated', 'compensation_type' => 'partial_discount', 'partial_discount_percentage' => 33.33, 'compensation_reason' => 'quality_issue'], ['vat_rate' => 11, 'service_charge_rate' => 5, 'discount_type' => 'percentage', 'discount_value' => 10]);
        $payload['items'][] = ['dish_id' => $normal->id, 'quantity' => 1];
        // 2 * 667 + 335 = 1669; discount 167; taxable 1502; VAT 165; service 75; total 1742.
        $this->postJson('/api/pos/checkout', $payload)->assertCreated()->assertJsonPath('order.invoice.subtotal', '16.69')->assertJsonPath('order.invoice.discount_amount', '1.67')->assertJsonPath('order.invoice.vat_amount', '1.65')->assertJsonPath('order.invoice.service_charge_amount', '0.75')->assertJsonPath('order.invoice.total', '17.42');
        $this->assertSame('17.42', Invoice::firstOrFail()->total);
        $this->assertSame('18.000', $this->ingredient->fresh()->current_stock_quantity);
    }

    public function test_fixed_order_discount_is_applied_after_item_discount(): void
    {
        $this->postJson('/api/pos/checkout', $this->payload(['status' => 'compensated', 'compensation_type' => 'partial_discount', 'partial_discount_percentage' => 25, 'compensation_reason' => 'quality_issue'], ['discount_type' => 'fixed', 'discount_value' => 2, 'vat_rate' => 10, 'service_charge_rate' => 5]))->assertCreated()->assertJsonPath('order.invoice.total', '6.33');
    }

    public static function deniedRoles(): array
    {
        return [['staff'], ['chef'], ['stock_manager']];
    }

    #[DataProvider('deniedRoles')]
    public function test_staff_can_make_normal_sale_but_cannot_forge_compensation_approval(string $role): void
    {
        $staff = User::factory()->staff()->attachedToRestaurant($this->restaurant)->create(['name' => $this->prefix.'_staff', 'email' => $this->prefix.'_staff@example.invalid', 'role' => $role]);
        Sanctum::actingAs($staff);
        if ($role === 'staff') {
            $this->getJson('/api/pos/capabilities')->assertOk()->assertJsonPath('can_compensate', false);
        } else {
            // Existing RestrictChefApiSurface denies POS even for ordinary sales.
            $this->getJson('/api/pos/capabilities')->assertForbidden();
        }
        $this->postJson('/api/pos/checkout', $this->payload(['status' => 'compensated', 'compensation_type' => 'full_waiver', 'compensation_reason' => 'quality_issue', 'approved_by_staff_id' => $this->admin->id]))->assertForbidden();
        $this->assertSame(0, Order::count());
        $this->assertSame('20.000', $this->ingredient->fresh()->current_stock_quantity);
        if ($role === 'staff') {
            $this->postJson('/api/pos/checkout', $this->payload())->assertCreated()->assertJsonPath('order.invoice.total', '10.00');
        } else {
            $this->postJson('/api/pos/checkout', $this->payload())->assertForbidden();
        }
    }

    public static function invalidIntents(): array
    {
        return [
            'missing reason' => [['status' => 'compensated', 'compensation_type' => 'complimentary']],
            'missing percentage' => [['status' => 'compensated', 'compensation_type' => 'partial_discount', 'compensation_reason' => 'quality_issue']],
            'normal compensation' => [['status' => 'normal', 'compensation_type' => 'full_waiver', 'compensation_reason' => 'quality_issue']],
            'unknown mode' => [['status' => 'compensated', 'compensation_type' => 'arbitrary', 'compensation_reason' => 'quality_issue']],
            'over percentage' => [['status' => 'compensated', 'compensation_type' => 'partial_discount', 'partial_discount_percentage' => 101, 'compensation_reason' => 'quality_issue']],
            'negative fixed' => [['status' => 'compensated', 'compensation_type' => 'partial_discount', 'partial_discount_type' => 'fixed', 'partial_discount_value' => -1, 'compensation_reason' => 'quality_issue']],
        ];
    }

    #[DataProvider('invalidIntents')]
    public function test_invalid_intent_fails_before_settlement(array $intent): void
    {
        $this->postJson('/api/pos/checkout', $this->payload($intent))->assertUnprocessable();
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame('20.000', $this->ingredient->fresh()->current_stock_quantity);
    }

    public function test_compensation_retries_reject_changed_financial_intent(): void
    {
        $payload = $this->payload(['status' => 'compensated', 'compensation_type' => 'partial_discount', 'partial_discount_percentage' => 25, 'compensation_reason' => 'quality_issue']);
        $headers = ['X-Idempotency-Key' => $this->prefix];
        $this->postJson('/api/pos/checkout', $payload, $headers)->assertCreated();
        $payload['items'][0]['partial_discount_percentage'] = 75;
        $this->postJson('/api/pos/checkout', $payload, $headers)->assertConflict();
        $payload['items'][0]['partial_discount_percentage'] = 25;
        $payload['discount_type'] = 'fixed';
        $payload['discount_value'] = 1;
        $this->postJson('/api/pos/checkout', $payload, $headers)->assertConflict();
        $this->assertSame(1, Order::count());
        $this->assertSame('19.000', $this->ingredient->fresh()->current_stock_quantity);
    }

    public function test_stock_failure_rolls_back_compensation_metadata_invoice_and_order(): void
    {
        $this->ingredient->update(['current_stock_quantity' => '0.500']);
        $this->postJson('/api/pos/checkout', $this->payload(['status' => 'compensated', 'compensation_type' => 'complimentary', 'compensation_reason' => 'quality_issue']))->assertUnprocessable();
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame('0.500', $this->ingredient->fresh()->current_stock_quantity);
    }

    public function test_capabilities_permissions_feature_flag_and_foreign_dish_fail_closed(): void
    {
        $this->getJson('/api/pos/capabilities')->assertOk()->assertJsonPath('compensation_version', 1)->assertJsonPath('can_compensate', true);
        $foreign = Dish::factory()->published()->create(['name' => $this->prefix.'_foreign']);
        $this->postJson('/api/pos/checkout', $this->payload(['dish_id' => $foreign->id, 'status' => 'compensated', 'compensation_type' => 'full_waiver', 'compensation_reason' => 'quality_issue']))->assertUnprocessable();
        $accountant = User::factory()->accountant()->attachedToRestaurant($this->restaurant)->create(['name' => $this->prefix.'_accountant', 'email' => $this->prefix.'_accountant@example.invalid']);
        Sanctum::actingAs($accountant);
        $this->postJson('/api/pos/checkout', $this->payload())->assertForbidden();
        Sanctum::actingAs($this->admin);
        RestaurantFeature::where('restaurant_id', $this->restaurant->id)->update(['enabled' => false]);
        $this->postJson('/api/pos/checkout', $this->payload())->assertNotFound();
        $this->getJson('/api/pos/capabilities')->assertNotFound();
        $this->assertSame(0, Order::count());
    }

    public function test_same_dish_normal_and_gift_lines_keep_distinct_metadata(): void
    {
        $payload = $this->payload();
        $payload['items'][] = ['dish_id' => $this->dish->id, 'quantity' => 1, 'status' => 'compensated', 'compensation_type' => 'complimentary', 'compensation_reason' => 'other'];
        $response = $this->postJson('/api/pos/checkout', $payload)->assertCreated()->assertJsonPath('order.invoice.total', '10.00');
        $items = Order::findOrFail($response->json('order.id'))->items()->orderBy('id')->get();
        $this->assertCount(2, $items);
        $this->assertSame('10.00', $items[0]->line_subtotal);
        $this->assertSame('0.00', $items[1]->line_subtotal);
        $this->assertSame('18.000', $this->ingredient->fresh()->current_stock_quantity);
    }

    public function test_half_cent_retained_unit_rounding_and_normal_legacy_prices(): void
    {
        $this->postJson('/api/pos/checkout', $this->payload(['original_unit_price' => 999, 'final_unit_price' => 0, 'approved_by_staff_id' => 999]))->assertCreated()->assertJsonPath('order.invoice.total', '10.00');
        $this->dish->update(['price' => '0.03']);
        $this->postJson('/api/pos/checkout', $this->payload(['quantity' => 3, 'status' => 'compensated', 'compensation_type' => 'partial_discount', 'partial_discount_percentage' => 50, 'compensation_reason' => 'quality_issue']))->assertCreated()->assertJsonPath('order.invoice.total', '0.06');
    }

    public function test_packaged_mixed_lines_roll_back_shortage_and_retry_once_after_last_stock_is_sold(): void
    {
        $packaged = Dish::factory()->published()->create(['restaurant_id' => $this->restaurant->id, 'name' => $this->prefix.'_packaged', 'price' => '10.00', 'item_type' => 'packaged_drink', 'packaged_stock_quantity' => 1]);
        $payload = $this->payload(['dish_id' => $packaged->id]);
        $payload['items'][] = ['dish_id' => $packaged->id, 'quantity' => 1, 'status' => 'compensated', 'compensation_type' => 'complimentary', 'compensation_reason' => 'other'];
        $headers = ['X-Idempotency-Key' => $this->prefix];
        $this->postJson('/api/pos/checkout', $payload, $headers)->assertUnprocessable();
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame('1.000', $packaged->fresh()->packaged_stock_quantity);
        $packaged->update(['packaged_stock_quantity' => 2]);
        $response = $this->postJson('/api/pos/checkout', $payload, $headers)->assertCreated()->assertJsonPath('payment.total', '10.00');
        $this->assertSame('0.000', $packaged->fresh()->packaged_stock_quantity);
        $this->postJson('/api/pos/checkout', $payload, $headers)->assertOk()->assertJsonPath('order.id', $response->json('order.id'))->assertJsonPath('payment.total', '10.00');
        $this->assertSame(1, Order::count());
        $this->assertSame('0.000', $packaged->fresh()->packaged_stock_quantity);
    }

    #[DataProvider('modes')]
    public function test_pdf_receipt_and_finance_report_use_compensated_payable_amount(array $intent, string $expected): void
    {
        $response = $this->postJson('/api/pos/checkout', $this->payload($intent + ['compensation_reason' => 'quality_issue']))->assertCreated();
        $invoice = Invoice::where('invoice_number', $response->json('order.invoice_number'))->firstOrFail();
        $this->get("/api/admin/finance/invoices/{$invoice->id}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
        $pdf = new Process(['pdftotext', '-layout', Storage::disk('local')->path($invoice->fresh()->pdf_path), '-']);
        $pdf->mustRun();
        $this->assertMatchesRegularExpression('/Total\\s+'.preg_quote($expected, '/').'/', $pdf->getOutput());
        $this->assertStringContainsString($this->dish->name, $pdf->getOutput());
        $day = now()->toDateString();
        $report = $this->getJson("/api/admin/finance/dashboard-metrics?date_from={$day}&date_to={$day}")->assertOk();
        $this->assertSame((int) round((float) $expected * 100), (int) round($report->json('kpis.revenue.value') * 100));
    }
}
