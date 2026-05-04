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

final class FinanceDashboardMetricsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_get_dashboard_metrics_with_previous_period_deltas(): void
    {
        [$adminA, $restaurantA] = $this->createAdminWithRestaurant('dm-alpha');
        [$adminB, $restaurantB] = $this->createAdminWithRestaurant('dm-beta');

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

        // Current period: 2026-05-01..2026-05-05
        $this->createInvoice($restaurantA->id, '2026-05-02', Invoice::STATUS_ISSUED, 120.00);
        $this->createInvoice($restaurantA->id, '2026-05-04', Invoice::STATUS_PAID, 80.00);
        $this->createInvoice($restaurantA->id, '2026-05-03', Invoice::STATUS_CANCELLED, 50.00);
        $this->createExpense($restaurantA->id, $categoryA->id, '2026-05-02', Expense::STATUS_APPROVED, 3000, 0);
        $this->createExpense($restaurantA->id, $categoryA->id, '2026-05-04', Expense::STATUS_PAID, 2000, 0);
        $this->createExpense($restaurantA->id, $categoryA->id, '2026-05-04', Expense::STATUS_DRAFT, 1000, 0);

        // Previous period auto-resolved: 2026-04-26..2026-04-30
        $this->createInvoice($restaurantA->id, '2026-04-28', Invoice::STATUS_ISSUED, 100.00);
        $this->createExpense($restaurantA->id, $categoryA->id, '2026-04-28', Expense::STATUS_APPROVED, 2500, 0);

        // Other tenant noise should be ignored
        $this->createInvoice($restaurantB->id, '2026-05-03', Invoice::STATUS_ISSUED, 999.00);
        $this->createExpense($restaurantB->id, $categoryB->id, '2026-05-03', Expense::STATUS_APPROVED, 90000, 0);

        Sanctum::actingAs($adminA);

        $response = $this->getJson('/api/admin/finance/dashboard-metrics?date_from=2026-05-01&date_to=2026-05-05');

        $response->assertOk()
            ->assertJsonPath('date_from', '2026-05-01')
            ->assertJsonPath('date_to', '2026-05-05')
            ->assertJsonPath('previous_date_from', '2026-04-26')
            ->assertJsonPath('previous_date_to', '2026-04-30')
            ->assertJsonPath('kpis.revenue.value', 200)
            ->assertJsonPath('kpis.revenue.previous', 100)
            ->assertJsonPath('kpis.revenue.delta', 100)
            ->assertJsonPath('kpis.revenue.delta_percent', 100)
            ->assertJsonPath('kpis.expenses.value', 50)
            ->assertJsonPath('kpis.expenses.previous', 25)
            ->assertJsonPath('kpis.expenses.delta', 25)
            ->assertJsonPath('kpis.expenses.delta_percent', 100)
            ->assertJsonPath('kpis.profit.value', 150)
            ->assertJsonPath('kpis.profit.previous', 75)
            ->assertJsonPath('kpis.profit.delta', 75)
            ->assertJsonPath('kpis.profit.delta_percent', 100)
            ->assertJsonPath('kpis.profit_margin_percent.value', 75)
            ->assertJsonPath('kpis.profit_margin_percent.previous', 75)
            ->assertJsonPath('kpis.profit_margin_percent.delta', 0)
            ->assertJsonPath('kpis.invoice_count.value', 2)
            ->assertJsonPath('kpis.invoice_count.previous', 1)
            ->assertJsonPath('kpis.invoice_count.delta', 1)
            ->assertJsonPath('kpis.invoice_count.delta_percent', 100)
            ->assertJsonPath('kpis.average_invoice_value.value', 100)
            ->assertJsonPath('kpis.average_invoice_value.previous', 100)
            ->assertJsonPath('kpis.average_invoice_value.delta', 0)
            ->assertJsonPath('kpis.average_invoice_value.delta_percent', 0);
    }

    public function test_dashboard_metrics_can_include_draft_expenses_when_requested(): void
    {
        [$admin, $restaurant] = $this->createAdminWithRestaurant('dm-gamma');
        $category = ExpenseCategory::query()->create([
            'restaurant_id' => $restaurant->id,
            'code' => 'ops',
            'name' => 'Operations',
            'is_active' => true,
        ]);

        $this->createInvoice($restaurant->id, '2026-05-02', Invoice::STATUS_PAID, 200.00);
        $this->createExpense($restaurant->id, $category->id, '2026-05-02', Expense::STATUS_APPROVED, 10000, 0);
        $this->createExpense($restaurant->id, $category->id, '2026-05-02', Expense::STATUS_DRAFT, 5000, 0);

        Sanctum::actingAs($admin);

        $defaultMode = $this->getJson('/api/admin/finance/dashboard-metrics?date_from=2026-05-01&date_to=2026-05-05');
        $defaultMode->assertOk()
            ->assertJsonPath('mode.expense_status', 'approved_paid')
            ->assertJsonPath('kpis.expenses.value', 100)
            ->assertJsonPath('kpis.profit.value', 100);

        $allNonVoidMode = $this->getJson('/api/admin/finance/dashboard-metrics?date_from=2026-05-01&date_to=2026-05-05&expense_status=all_non_void');
        $allNonVoidMode->assertOk()
            ->assertJsonPath('mode.expense_status', 'all_non_void')
            ->assertJsonPath('kpis.expenses.value', 150)
            ->assertJsonPath('kpis.profit.value', 50);
    }

    public function test_non_admin_user_cannot_access_dashboard_metrics_endpoint(): void
    {
        $staff = User::factory()->staff()->create();
        [$admin, $restaurant] = $this->createAdminWithRestaurant('dm-forbidden');
        $restaurant->staffUsers()->attach($staff->id);

        Sanctum::actingAs($staff);

        $response = $this->getJson('/api/admin/finance/dashboard-metrics');
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
            'name' => 'Dashboard '.Str::upper($suffix),
            'slug' => 'dashboard-'.Str::lower($suffix).'-'.Str::lower(Str::random(5)),
            'description' => 'Dashboard metrics test restaurant',
            'address' => 'Beirut',
        ]);

        $this->enableFeatureForRestaurant($restaurant, 'finance_dashboard');
        $this->enableFeatureForRestaurant($restaurant, 'expense_management');

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

    private function createInvoice(int $restaurantId, string $invoiceDate, string $status, float $total): void
    {
        Invoice::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurantId,
            'invoice_number' => 'INV-'.Str::upper(Str::random(10)),
            'invoice_date' => $invoiceDate,
            'status' => $status,
            'subtotal' => number_format($total, 2, '.', ''),
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
