<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Feature;
use App\Models\Invoice;
use App\Models\Restaurant;
use App\Models\RestaurantFeature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class FinanceTaxReportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_get_tax_report_totals_and_monthly_breakdown(): void
    {
        [$adminA, $restaurantA] = $this->createAdminWithRestaurant('tax-alpha');
        [$adminB, $restaurantB] = $this->createAdminWithRestaurant('tax-beta');

        $categoryA = ExpenseCategory::query()->create([
            'restaurant_id' => $restaurantA->id,
            'code' => 'ops',
            'name' => 'Operations',
            'is_active' => true,
        ]);
        $categoryB = ExpenseCategory::query()->create([
            'restaurant_id' => $restaurantB->id,
            'code' => 'other',
            'name' => 'Other',
            'is_active' => true,
        ]);

        // Restaurant A in range
        $this->createInvoice($restaurantA->id, '2026-05-02', Invoice::STATUS_ISSUED, 100.00, 111.00); // output VAT 11
        $this->createInvoice($restaurantA->id, '2026-05-10', Invoice::STATUS_PAID, 50.00, 55.00); // output VAT 5
        $this->createInvoice($restaurantA->id, '2026-05-15', Invoice::STATUS_CANCELLED, 70.00, 77.00); // ignored
        $this->createInvoice($restaurantA->id, '2026-04-30', Invoice::STATUS_ISSUED, 20.00, 22.00); // out of range

        $this->createExpense($restaurantA->id, $categoryA->id, '2026-05-03', Expense::STATUS_APPROVED, 10000, 300);
        $this->createExpense($restaurantA->id, $categoryA->id, '2026-05-04', Expense::STATUS_PAID, 10000, 200);
        $this->createExpense($restaurantA->id, $categoryA->id, '2026-05-05', Expense::STATUS_DRAFT, 10000, 100); // excluded by default
        $this->createExpense($restaurantA->id, $categoryA->id, '2026-05-06', Expense::STATUS_VOID, 10000, 999); // ignored

        // Other tenant noise
        $this->createInvoice($restaurantB->id, '2026-05-08', Invoice::STATUS_ISSUED, 100.00, 200.00);
        $this->createExpense($restaurantB->id, $categoryB->id, '2026-05-08', Expense::STATUS_APPROVED, 10000, 9999);

        Sanctum::actingAs($adminA);

        $response = $this->getJson('/api/admin/finance/tax-report?date_from=2026-05-01&date_to=2026-05-31');

        $response->assertOk()
            ->assertJsonPath('date_from', '2026-05-01')
            ->assertJsonPath('date_to', '2026-05-31')
            ->assertJsonPath('taxable_sales', 150)
            ->assertJsonPath('output_vat', 16)
            ->assertJsonPath('input_vat', 5)
            ->assertJsonPath('net_vat_payable', 11)
            ->assertJsonPath('mode.expense_status', 'approved_paid')
            ->assertJsonPath('totals.output_vat', 16)
            ->assertJsonPath('totals.input_vat', 5)
            ->assertJsonPath('totals.net_vat', 11)
            ->assertJsonPath('totals.payable_vat', 11)
            ->assertJsonPath('totals.refundable_vat', 0)
            ->assertJsonCount(1, 'breakdown')
            ->assertJsonPath('breakdown.0.bucket', '2026-05')
            ->assertJsonPath('breakdown.0.output_vat', 16)
            ->assertJsonPath('breakdown.0.input_vat', 5)
            ->assertJsonPath('breakdown.0.net_vat', 11);

        $aliasResponse = $this->getJson('/api/admin/finance/tax/summary?date_from=2026-05-01&date_to=2026-05-31');
        $aliasResponse->assertOk()
            ->assertJsonPath('taxable_sales', 150)
            ->assertJsonPath('net_vat_payable', 11);
    }

    public function test_tax_report_can_include_draft_expense_tax_when_requested(): void
    {
        [$admin, $restaurant] = $this->createAdminWithRestaurant('tax-gamma');
        $category = ExpenseCategory::query()->create([
            'restaurant_id' => $restaurant->id,
            'code' => 'ops',
            'name' => 'Operations',
            'is_active' => true,
        ]);

        $this->createInvoice($restaurant->id, '2026-05-12', Invoice::STATUS_PAID, 100.00, 111.00);
        $this->createExpense($restaurant->id, $category->id, '2026-05-12', Expense::STATUS_APPROVED, 10000, 200);
        $this->createExpense($restaurant->id, $category->id, '2026-05-12', Expense::STATUS_DRAFT, 10000, 300);

        Sanctum::actingAs($admin);

        $defaultMode = $this->getJson('/api/admin/finance/tax-report?date_from=2026-05-01&date_to=2026-05-31');
        $defaultMode->assertOk()
            ->assertJsonPath('totals.input_vat', 2)
            ->assertJsonPath('totals.net_vat', 9);

        $allNonVoid = $this->getJson('/api/admin/finance/tax-report?date_from=2026-05-01&date_to=2026-05-31&expense_status=all_non_void');
        $allNonVoid->assertOk()
            ->assertJsonPath('mode.expense_status', 'all_non_void')
            ->assertJsonPath('totals.input_vat', 5)
            ->assertJsonPath('totals.net_vat', 6);
    }

    public function test_non_admin_user_cannot_access_tax_report(): void
    {
        $staff = User::factory()->staff()->create();
        [$admin, $restaurant] = $this->createAdminWithRestaurant('tax-forbidden');
        $restaurant->staffUsers()->attach($staff->id);

        Sanctum::actingAs($staff);

        $response = $this->getJson('/api/admin/finance/tax-report');
        $response->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Restaurant}
     */
    private function createAdminWithRestaurant(string $suffix): array
    {
        $admin = User::factory()->admin()->create([
            'email' => "{$suffix}@example.test",
        ]);

        $restaurant = Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'name' => 'Tax '.Str::upper($suffix),
            'slug' => 'tax-'.Str::lower($suffix).'-'.Str::lower(Str::random(5)),
            'description' => 'Tax report test restaurant',
            'address' => 'Beirut',
        ]);

        $this->enableFeatureForRestaurant($restaurant, 'finance_dashboard');
        $this->enableFeatureForRestaurant($restaurant, 'expense_management');
        $this->enableFeatureForRestaurant($restaurant, 'vat_invoices');

        return [$admin, $restaurant];
    }

    private function enableFeatureForRestaurant(Restaurant $restaurant, string $featureKey): void
    {
        $feature = Feature::query()->firstOrCreate(
            ['key' => $featureKey],
            [
                'name' => ucwords(str_replace('_', ' ', $featureKey)),
                'description' => 'Test feature flag',
                'category' => 'finance',
                'is_active_by_default' => false,
            ]
        );

        RestaurantFeature::query()->updateOrCreate(
            [
                'restaurant_id' => $restaurant->id,
                'feature_id' => $feature->id,
            ],
            ['enabled' => true]
        );
    }

    private function createInvoice(
        int $restaurantId,
        string $invoiceDate,
        string $status,
        float $subtotal,
        float $total
    ): void {
        Invoice::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurantId,
            'invoice_number' => 'INV-'.Str::upper(Str::random(10)),
            'invoice_date' => $invoiceDate,
            'status' => $status,
            'subtotal' => number_format($subtotal, 2, '.', ''),
            'total' => number_format($total, 2, '.', ''),
        ]);
    }

    private function createExpense(
        int $restaurantId,
        int $categoryId,
        string $expenseDate,
        string $status,
        int $amountCents,
        int $taxAmountCents
    ): void {
        Expense::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurantId,
            'expense_category_id' => $categoryId,
            'expense_date' => $expenseDate,
            'amount_cents' => $amountCents,
            'tax_amount_cents' => $taxAmountCents,
            'currency' => 'USD',
            'status' => $status,
        ]);
    }
}
