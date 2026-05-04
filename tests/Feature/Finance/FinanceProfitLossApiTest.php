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

final class FinanceProfitLossApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_get_profit_and_loss_summary_for_date_range(): void
    {
        [$adminA, $restaurantA] = $this->createAdminWithRestaurant('pl-alpha');
        [$adminB, $restaurantB] = $this->createAdminWithRestaurant('pl-beta');

        $categoryUtilities = ExpenseCategory::query()->create([
            'restaurant_id' => $restaurantA->id,
            'code' => 'utilities',
            'name' => 'Utilities',
            'is_active' => true,
        ]);
        $categoryMarketing = ExpenseCategory::query()->create([
            'restaurant_id' => $restaurantA->id,
            'code' => 'marketing',
            'name' => 'Marketing',
            'is_active' => true,
        ]);
        $categoryOtherTenant = ExpenseCategory::query()->create([
            'restaurant_id' => $restaurantB->id,
            'code' => 'other',
            'name' => 'Other',
            'is_active' => true,
        ]);

        $this->createInvoice($restaurantA->id, '2026-05-03', Invoice::STATUS_ISSUED, 120.50);
        $this->createInvoice($restaurantA->id, '2026-05-05', Invoice::STATUS_PAID, 79.50);
        $this->createInvoice($restaurantA->id, '2026-05-04', Invoice::STATUS_CANCELLED, 40.00);
        $this->createInvoice($restaurantA->id, '2026-04-30', Invoice::STATUS_ISSUED, 25.00);
        $this->createInvoice($restaurantB->id, '2026-05-04', Invoice::STATUS_ISSUED, 999.00);

        $this->createExpense($restaurantA->id, $categoryUtilities->id, '2026-05-03', Expense::STATUS_APPROVED, 10000, 1000);
        $this->createExpense($restaurantA->id, $categoryMarketing->id, '2026-05-04', Expense::STATUS_PAID, 2500, 250);
        $this->createExpense($restaurantA->id, $categoryUtilities->id, '2026-05-04', Expense::STATUS_DRAFT, 5000, 0);
        $this->createExpense($restaurantA->id, $categoryUtilities->id, '2026-05-04', Expense::STATUS_VOID, 9900, 100);
        $this->createExpense($restaurantA->id, $categoryUtilities->id, '2026-04-29', Expense::STATUS_PAID, 1000, 100);
        $this->createExpense($restaurantB->id, $categoryOtherTenant->id, '2026-05-04', Expense::STATUS_APPROVED, 50000, 0);

        Sanctum::actingAs($adminA);

        $response = $this->getJson('/api/admin/finance/profit-loss?date_from=2026-05-01&date_to=2026-05-05');

        $response->assertOk()
            ->assertJsonPath('date_from', '2026-05-01')
            ->assertJsonPath('date_to', '2026-05-05')
            ->assertJsonPath('mode.expense_status', 'approved_paid')
            ->assertJsonPath('totals.revenue', 200)
            ->assertJsonPath('totals.expenses', 137.50)
            ->assertJsonPath('totals.profit', 62.50)
            ->assertJsonPath('totals.profit_margin_percent', 31.25)
            ->assertJsonCount(2, 'expense_breakdown')
            ->assertJsonPath('expense_breakdown.0.expense_category_name', 'Utilities')
            ->assertJsonPath('expense_breakdown.0.total', 110)
            ->assertJsonPath('expense_breakdown.1.expense_category_name', 'Marketing')
            ->assertJsonPath('expense_breakdown.1.total', 27.50);
    }

    public function test_profit_and_loss_can_include_draft_expenses_when_requested(): void
    {
        [$admin, $restaurant] = $this->createAdminWithRestaurant('pl-gamma');
        $category = ExpenseCategory::query()->create([
            'restaurant_id' => $restaurant->id,
            'code' => 'ops',
            'name' => 'Operations',
            'is_active' => true,
        ]);

        $this->createInvoice($restaurant->id, '2026-05-05', Invoice::STATUS_ISSUED, 200.00);
        $this->createExpense($restaurant->id, $category->id, '2026-05-05', Expense::STATUS_APPROVED, 10000, 0);
        $this->createExpense($restaurant->id, $category->id, '2026-05-05', Expense::STATUS_DRAFT, 5000, 0);

        Sanctum::actingAs($admin);

        $defaultMode = $this->getJson('/api/admin/finance/profit-loss?date_from=2026-05-01&date_to=2026-05-10');
        $defaultMode->assertOk()
            ->assertJsonPath('totals.expenses', 100)
            ->assertJsonPath('totals.profit', 100);

        $allNonVoidMode = $this->getJson('/api/admin/finance/profit-loss?date_from=2026-05-01&date_to=2026-05-10&expense_status=all_non_void');
        $allNonVoidMode->assertOk()
            ->assertJsonPath('mode.expense_status', 'all_non_void')
            ->assertJsonPath('totals.expenses', 150)
            ->assertJsonPath('totals.profit', 50);
    }

    public function test_non_admin_user_cannot_access_profit_and_loss_endpoint(): void
    {
        $staff = User::factory()->staff()->create();
        [$admin, $restaurant] = $this->createAdminWithRestaurant('pl-forbidden');
        $restaurant->staffUsers()->attach($staff->id);

        Sanctum::actingAs($staff);

        $response = $this->getJson('/api/admin/finance/profit-loss');
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
            'name' => 'P&L '.Str::upper($suffix),
            'slug' => 'pl-'.Str::lower($suffix).'-'.Str::lower(Str::random(5)),
            'description' => 'P&L test restaurant',
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
