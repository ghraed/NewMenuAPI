<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\Feature;
use App\Models\PayrollPeriod;
use App\Models\Restaurant;
use App\Models\RestaurantFeature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class FinancePayrollApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_payroll_periods_and_overlaps_are_rejected(): void
    {
        [$admin, $restaurant] = $this->createAdminWithRestaurant('payroll-overlap');

        Sanctum::actingAs($admin);

        $firstCreate = $this->postJson('/api/admin/finance/payroll/periods', [
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-15',
            'notes' => ' first half ',
        ]);

        $firstCreate->assertCreated()
            ->assertJsonPath('period.status', PayrollPeriod::STATUS_DRAFT)
            ->assertJsonPath('period.notes', 'first half')
            ->assertJsonPath('period.period_start', '2026-05-01')
            ->assertJsonPath('period.period_end', '2026-05-15');

        $overlapCreate = $this->postJson('/api/admin/finance/payroll/periods', [
            'period_start' => '2026-05-10',
            'period_end' => '2026-05-20',
        ]);

        $overlapCreate->assertStatus(422)
            ->assertJsonValidationErrors(['period_start']);

        $secondCreate = $this->postJson('/api/admin/finance/payroll/periods', [
            'period_start' => '2026-05-16',
            'period_end' => '2026-05-31',
        ]);

        $secondCreate->assertCreated()
            ->assertJsonPath('period.status', PayrollPeriod::STATUS_DRAFT);

        $list = $this->getJson('/api/admin/finance/payroll/periods');

        $list->assertOk()
            ->assertJsonCount(2, 'periods');

        $this->assertDatabaseHas('payroll_periods', [
            'restaurant_id' => $restaurant->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-15',
            'status' => PayrollPeriod::STATUS_DRAFT,
        ]);
    }

    public function test_admin_can_upsert_entries_transition_status_and_read_summary_totals(): void
    {
        [$admin, $restaurant] = $this->createAdminWithRestaurant('payroll-summary');

        $staff = User::factory()->staff()->create(['name' => 'Payroll Staff']);
        $chef = User::factory()->chef()->create(['name' => 'Payroll Chef']);
        $restaurant->staffUsers()->attach([$staff->id, $chef->id]);

        $includedPeriod = PayrollPeriod::query()->create([
            'restaurant_id' => $restaurant->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-15',
            'status' => PayrollPeriod::STATUS_DRAFT,
        ]);

        $excludedDraftPeriod = PayrollPeriod::query()->create([
            'restaurant_id' => $restaurant->id,
            'period_start' => '2026-05-16',
            'period_end' => '2026-05-31',
            'status' => PayrollPeriod::STATUS_DRAFT,
        ]);

        Sanctum::actingAs($admin);

        $upsertIncluded = $this->putJson("/api/admin/finance/payroll/periods/{$includedPeriod->id}/entries", [
            'entries' => [
                [
                    'user_id' => $staff->id,
                    'base_amount_cents' => 100000,
                    'overtime_amount_cents' => 20000,
                    'bonus_amount_cents' => 5000,
                    'allowance_amount_cents' => 30000,
                    'reimbursement_amount_cents' => 2000,
                    'deduction_amount_cents' => 10000,
                    'tax_amount_cents' => 15000,
                    'currency' => 'usd',
                ],
                [
                    'user_id' => $chef->id,
                    'base_amount_cents' => 120000,
                    'overtime_amount_cents' => 0,
                    'bonus_amount_cents' => 10000,
                    'allowance_amount_cents' => 7000,
                    'reimbursement_amount_cents' => 3000,
                    'deduction_amount_cents' => 5000,
                    'tax_amount_cents' => 12000,
                ],
            ],
        ]);

        $upsertIncluded->assertOk()
            ->assertJsonPath('period.totals.gross_pay', 2970)
            ->assertJsonPath('period.totals.deductions', 150)
            ->assertJsonPath('period.totals.tax', 270)
            ->assertJsonPath('period.totals.net_pay', 2550)
            ->assertJsonPath('period.totals.employee_count', 2)
            ->assertJsonPath('period.entries.0.allowance_amount_cents', 30000)
            ->assertJsonPath('period.entries.0.reimbursement_amount_cents', 2000)
            ->assertJsonPath('period.entries.0.currency', 'USD');

        $upsertExcluded = $this->putJson("/api/admin/finance/payroll/periods/{$excludedDraftPeriod->id}/entries", [
            'entries' => [
                [
                    'user_id' => $staff->id,
                    'base_amount_cents' => 50000,
                    'tax_amount_cents' => 5000,
                ],
            ],
        ]);

        $upsertExcluded->assertOk();

        $approve = $this->patchJson("/api/admin/finance/payroll/periods/{$includedPeriod->id}", [
            'status' => PayrollPeriod::STATUS_APPROVED,
        ]);

        $approve->assertOk()
            ->assertJsonPath('period.status', PayrollPeriod::STATUS_APPROVED)
            ->assertJsonPath('period.processed_by.id', $admin->id)
            ->assertJsonPath('period.approved_at', fn ($value): bool => is_string($value) && $value !== '')
            ->assertJsonPath('period.paid_at', null);

        $defaultSummary = $this->getJson('/api/admin/finance/payroll/summary?date_from=2026-05-01&date_to=2026-05-31');

        $defaultSummary->assertOk()
            ->assertJsonPath('mode.period_status', 'approved_paid')
            ->assertJsonPath('totals.gross_pay', 2970)
            ->assertJsonPath('totals.deductions', 150)
            ->assertJsonPath('totals.tax', 270)
            ->assertJsonPath('totals.net_pay', 2550)
            ->assertJsonPath('totals.employee_count', 2);

        $allSummary = $this->getJson('/api/admin/finance/payroll/summary?date_from=2026-05-01&date_to=2026-05-31&period_status=all');

        $allSummary->assertOk()
            ->assertJsonPath('mode.period_status', 'all')
            ->assertJsonPath('totals.gross_pay', 3470)
            ->assertJsonPath('totals.tax', 320)
            ->assertJsonPath('totals.net_pay', 3000);

        $markPaid = $this->patchJson("/api/admin/finance/payroll/periods/{$includedPeriod->id}", [
            'status' => PayrollPeriod::STATUS_PAID,
        ]);

        $markPaid->assertOk()
            ->assertJsonPath('period.status', PayrollPeriod::STATUS_PAID)
            ->assertJsonPath('period.paid_at', fn ($value): bool => is_string($value) && $value !== '');

        $cannotEditPaidEntries = $this->putJson("/api/admin/finance/payroll/periods/{$includedPeriod->id}/entries", [
            'entries' => [
                [
                    'user_id' => $staff->id,
                    'base_amount_cents' => 99999,
                ],
            ],
        ]);

        $cannotEditPaidEntries->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_payroll_entries_reject_invalid_users_negative_net_and_cross_tenant_period_access(): void
    {
        [$adminA, $restaurantA] = $this->createAdminWithRestaurant('payroll-validate-a');
        [$adminB, $restaurantB] = $this->createAdminWithRestaurant('payroll-validate-b');

        $staffA = User::factory()->staff()->create();
        $staffB = User::factory()->staff()->create();

        $restaurantA->staffUsers()->attach($staffA->id);
        $restaurantB->staffUsers()->attach($staffB->id);

        $periodA = PayrollPeriod::query()->create([
            'restaurant_id' => $restaurantA->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-15',
            'status' => PayrollPeriod::STATUS_DRAFT,
        ]);

        $periodB = PayrollPeriod::query()->create([
            'restaurant_id' => $restaurantB->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-15',
            'status' => PayrollPeriod::STATUS_DRAFT,
        ]);

        Sanctum::actingAs($adminA);

        $crossTenantPeriodUpdate = $this->patchJson("/api/admin/finance/payroll/periods/{$periodB->id}", [
            'status' => PayrollPeriod::STATUS_APPROVED,
        ]);

        $crossTenantPeriodUpdate->assertNotFound();

        $nonEmployeeEntry = $this->putJson("/api/admin/finance/payroll/periods/{$periodA->id}/entries", [
            'entries' => [
                [
                    'user_id' => $staffB->id,
                    'base_amount_cents' => 1000,
                ],
            ],
        ]);

        $nonEmployeeEntry->assertStatus(422)
            ->assertJsonValidationErrors(['entries']);

        $negativeNetEntry = $this->putJson("/api/admin/finance/payroll/periods/{$periodA->id}/entries", [
            'entries' => [
                [
                    'user_id' => $staffA->id,
                    'base_amount_cents' => 1000,
                    'deduction_amount_cents' => 2000,
                ],
            ],
        ]);

        $negativeNetEntry->assertStatus(422)
            ->assertJsonValidationErrors(['entries.0.deduction_amount_cents']);
    }

    public function test_non_admin_user_cannot_access_payroll_endpoints(): void
    {
        [$admin, $restaurant] = $this->createAdminWithRestaurant('payroll-forbidden');

        $staff = User::factory()->staff()->create();
        $restaurant->staffUsers()->attach($staff->id);

        Sanctum::actingAs($staff);

        $response = $this->getJson('/api/admin/finance/payroll/periods');

        $response->assertForbidden();
    }

    public function test_query_monthly_mode_creates_and_reuses_period_container(): void
    {
        [$admin, $restaurant] = $this->createAdminWithRestaurant('payroll-query-monthly');
        Sanctum::actingAs($admin);

        $first = $this->postJson('/api/admin/finance/payroll/query', [
            'mode' => 'monthly',
            'year' => 2026,
            'month' => 4,
        ]);

        $first->assertOk()
            ->assertJsonPath('mode', 'monthly')
            ->assertJsonPath('window.date_from', '2026-04-01')
            ->assertJsonPath('window.date_to', '2026-04-30')
            ->assertJsonCount(1, 'periods')
            ->assertJsonPath('periods.0.period_start', '2026-04-01')
            ->assertJsonPath('periods.0.period_end', '2026-04-30');

        $second = $this->postJson('/api/admin/finance/payroll/query', [
            'mode' => 'monthly',
            'year' => 2026,
            'month' => 4,
        ]);

        $second->assertOk()
            ->assertJsonCount(1, 'periods');

        $this->assertDatabaseCount('payroll_periods', 1);
        $this->assertDatabaseHas('payroll_periods', [
            'restaurant_id' => $restaurant->id,
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
        ]);
    }

    public function test_query_range_mode_splits_by_existing_periods_and_creates_gap_periods(): void
    {
        [$admin, $restaurant] = $this->createAdminWithRestaurant('payroll-query-range');
        Sanctum::actingAs($admin);

        PayrollPeriod::query()->create([
            'restaurant_id' => $restaurant->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-10',
            'status' => PayrollPeriod::STATUS_DRAFT,
        ]);

        PayrollPeriod::query()->create([
            'restaurant_id' => $restaurant->id,
            'period_start' => '2026-05-15',
            'period_end' => '2026-05-20',
            'status' => PayrollPeriod::STATUS_DRAFT,
        ]);

        $query = $this->postJson('/api/admin/finance/payroll/query', [
            'mode' => 'range',
            'date_from' => '2026-05-01',
            'date_to' => '2026-05-20',
        ]);

        $query->assertOk()
            ->assertJsonPath('mode', 'range')
            ->assertJsonCount(3, 'periods')
            ->assertJsonPath('periods.0.period_start', '2026-05-01')
            ->assertJsonPath('periods.0.period_end', '2026-05-10')
            ->assertJsonPath('periods.1.period_start', '2026-05-11')
            ->assertJsonPath('periods.1.period_end', '2026-05-14')
            ->assertJsonPath('periods.2.period_start', '2026-05-15')
            ->assertJsonPath('periods.2.period_end', '2026-05-20');

        $this->assertDatabaseHas('payroll_periods', [
            'restaurant_id' => $restaurant->id,
            'period_start' => '2026-05-11',
            'period_end' => '2026-05-14',
        ]);
    }

    /**
     * @return array{0: User, 1: Restaurant}
     */
    private function createAdminWithRestaurant(string $suffix): array
    {
        $admin = User::factory()->admin()->create([
            'email' => "admin-{$suffix}@example.test",
        ]);

        $restaurant = Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'name' => 'Payroll Test '.Str::upper($suffix),
            'slug' => 'payroll-test-'.Str::lower($suffix).'-'.Str::lower(Str::random(5)),
            'description' => 'Payroll API test restaurant',
            'address' => 'Beirut',
        ]);

        $this->enableFeatureForRestaurant($restaurant, 'payroll_management');

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
}
