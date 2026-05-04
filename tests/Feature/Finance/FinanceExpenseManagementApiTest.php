<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Feature;
use App\Models\Restaurant;
use App\Models\RestaurantFeature;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class FinanceExpenseManagementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_manage_expense_categories_with_tenant_scoping(): void
    {
        [$adminA, $restaurantA] = $this->createAdminWithRestaurant('alpha');
        [$adminB, $restaurantB] = $this->createAdminWithRestaurant('beta');

        $categoryA = ExpenseCategory::query()->create([
            'restaurant_id' => $restaurantA->id,
            'code' => 'utilities',
            'name' => 'Utilities',
            'is_active' => true,
        ]);
        $categoryB = ExpenseCategory::query()->create([
            'restaurant_id' => $restaurantB->id,
            'code' => 'marketing',
            'name' => 'Marketing',
            'is_active' => true,
        ]);

        Sanctum::actingAs($adminA);

        $listResponse = $this->getJson('/api/admin/finance/expense-categories');

        $listResponse->assertOk()
            ->assertJsonCount(1, 'categories')
            ->assertJsonPath('categories.0.id', $categoryA->id)
            ->assertJsonPath('categories.0.code', 'utilities');

        $createResponse = $this->postJson('/api/admin/finance/expense-categories', [
            'code' => ' Office Rent ',
            'name' => 'Office Rent',
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('category.code', 'office_rent')
            ->assertJsonPath('category.name', 'Office Rent')
            ->assertJsonPath('category.is_active', true);

        $createdId = (int) $createResponse->json('category.id');
        $this->assertDatabaseHas('expense_categories', [
            'id' => $createdId,
            'restaurant_id' => $restaurantA->id,
            'code' => 'office_rent',
        ]);

        $updateResponse = $this->patchJson("/api/admin/finance/expense-categories/{$categoryA->id}", [
            'name' => 'Utility Bills',
            'is_active' => false,
        ]);

        $updateResponse->assertOk()
            ->assertJsonPath('category.name', 'Utility Bills')
            ->assertJsonPath('category.is_active', false);

        $crossTenantResponse = $this->patchJson("/api/admin/finance/expense-categories/{$categoryB->id}", [
            'name' => 'Should Fail',
        ]);

        $crossTenantResponse->assertNotFound();
    }

    public function test_admin_can_manage_vendors_with_tenant_scoping(): void
    {
        [$adminA, $restaurantA] = $this->createAdminWithRestaurant('gamma');
        [$adminB, $restaurantB] = $this->createAdminWithRestaurant('delta');

        $vendorA = Vendor::query()->create([
            'restaurant_id' => $restaurantA->id,
            'name' => 'Alpha Supplies',
            'is_active' => true,
        ]);
        $vendorB = Vendor::query()->create([
            'restaurant_id' => $restaurantB->id,
            'name' => 'Other Tenant Vendor',
            'is_active' => true,
        ]);

        Sanctum::actingAs($adminA);

        $listResponse = $this->getJson('/api/admin/finance/vendors');

        $listResponse->assertOk()
            ->assertJsonCount(1, 'vendors')
            ->assertJsonPath('vendors.0.id', $vendorA->id)
            ->assertJsonPath('vendors.0.name', 'Alpha Supplies');

        $createResponse = $this->postJson('/api/admin/finance/vendors', [
            'name' => ' Fresh Farm Co. ',
            'contact_name' => '  Maya  ',
            'email' => 'maya@fresh.example',
            'phone' => ' +96170000000 ',
            'tax_number' => ' VAT-22 ',
            'notes' => ' weekly deliveries ',
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('vendor.name', 'Fresh Farm Co.')
            ->assertJsonPath('vendor.contact_name', 'Maya')
            ->assertJsonPath('vendor.phone', '+96170000000')
            ->assertJsonPath('vendor.tax_number', 'VAT-22');

        $updateResponse = $this->patchJson("/api/admin/finance/vendors/{$vendorA->id}", [
            'is_active' => false,
            'notes' => 'Paused this month',
        ]);

        $updateResponse->assertOk()
            ->assertJsonPath('vendor.id', $vendorA->id)
            ->assertJsonPath('vendor.is_active', false)
            ->assertJsonPath('vendor.notes', 'Paused this month');

        $crossTenantResponse = $this->patchJson("/api/admin/finance/vendors/{$vendorB->id}", [
            'name' => 'Should Fail',
        ]);

        $crossTenantResponse->assertNotFound();
    }

    public function test_admin_can_create_filter_and_update_expenses_with_status_rules(): void
    {
        [$adminA, $restaurantA] = $this->createAdminWithRestaurant('epsilon');
        [$adminB, $restaurantB] = $this->createAdminWithRestaurant('zeta');

        $categoryA = ExpenseCategory::query()->create([
            'restaurant_id' => $restaurantA->id,
            'code' => 'utilities',
            'name' => 'Utilities',
            'is_active' => true,
        ]);
        $vendorA = Vendor::query()->create([
            'restaurant_id' => $restaurantA->id,
            'name' => 'City Electric',
            'is_active' => true,
        ]);

        $categoryB = ExpenseCategory::query()->create([
            'restaurant_id' => $restaurantB->id,
            'code' => 'other',
            'name' => 'Other',
            'is_active' => true,
        ]);

        Expense::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurantA->id,
            'expense_category_id' => $categoryA->id,
            'vendor_id' => $vendorA->id,
            'expense_date' => '2026-05-01',
            'amount_cents' => 10000,
            'tax_amount_cents' => 1000,
            'currency' => 'USD',
            'status' => Expense::STATUS_DRAFT,
            'created_by' => $adminA->id,
        ]);

        Expense::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurantB->id,
            'expense_category_id' => $categoryB->id,
            'expense_date' => '2026-05-03',
            'amount_cents' => 22200,
            'tax_amount_cents' => 0,
            'currency' => 'USD',
            'status' => Expense::STATUS_APPROVED,
            'created_by' => $adminB->id,
        ]);

        Sanctum::actingAs($adminA);

        $createResponse = $this->postJson('/api/admin/finance/expenses', [
            'expense_category_id' => $categoryA->id,
            'vendor_id' => $vendorA->id,
            'expense_date' => '2026-05-04',
            'amount_cents' => 5000,
            'tax_amount_cents' => 500,
            'currency' => 'usd',
            'status' => Expense::STATUS_DRAFT,
            'payment_method' => 'cash',
            'reference_no' => ' INV-42 ',
            'description' => ' gas refill ',
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('expense.currency', 'USD')
            ->assertJsonPath('expense.total_cents', 5500)
            ->assertJsonPath('expense.status', Expense::STATUS_DRAFT)
            ->assertJsonPath('expense.paid_at', null)
            ->assertJsonPath('expense.approved_by', null)
            ->assertJsonPath('expense.reference_no', 'INV-42')
            ->assertJsonPath('expense.category.id', $categoryA->id)
            ->assertJsonPath('expense.vendor.id', $vendorA->id);

        $expenseId = (int) $createResponse->json('expense.id');

        $filterResponse = $this->getJson('/api/admin/finance/expenses?status=draft&date_from=2026-05-04&date_to=2026-05-04');

        $filterResponse->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'expenses')
            ->assertJsonPath('expenses.0.id', $expenseId);

        $approveResponse = $this->patchJson("/api/admin/finance/expenses/{$expenseId}", [
            'status' => Expense::STATUS_APPROVED,
        ]);

        $approveResponse->assertOk()
            ->assertJsonPath('expense.status', Expense::STATUS_APPROVED)
            ->assertJsonPath('expense.approved_by', $adminA->id)
            ->assertJsonPath('expense.paid_at', null);

        $paidResponse = $this->patchJson("/api/admin/finance/expenses/{$expenseId}", [
            'status' => Expense::STATUS_PAID,
        ]);

        $paidResponse->assertOk()
            ->assertJsonPath('expense.status', Expense::STATUS_PAID)
            ->assertJsonPath('expense.paid_at', fn ($value): bool => is_string($value) && $value !== '');

        $voidResponse = $this->patchJson("/api/admin/finance/expenses/{$expenseId}", [
            'status' => Expense::STATUS_VOID,
        ]);

        $voidResponse->assertOk()
            ->assertJsonPath('expense.status', Expense::STATUS_VOID)
            ->assertJsonPath('expense.paid_at', null)
            ->assertJsonPath('expense.approved_by', null);

        $crossTenantCreate = $this->postJson('/api/admin/finance/expenses', [
            'expense_category_id' => $categoryB->id,
            'expense_date' => '2026-05-04',
            'amount_cents' => 100,
            'currency' => 'USD',
        ]);

        $crossTenantCreate->assertStatus(422);
    }

    public function test_non_admin_user_cannot_access_finance_expense_endpoints(): void
    {
        $staff = User::factory()->staff()->create();
        $restaurant = Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory()->admin()->create()->id,
            'name' => 'Staff Finance Access',
            'slug' => 'staff-finance-access-'.Str::lower(Str::random(5)),
            'description' => 'Forbidden check',
            'address' => 'Beirut',
        ]);
        $restaurant->staffUsers()->attach($staff->id);

        Sanctum::actingAs($staff);

        $response = $this->getJson('/api/admin/finance/expenses');
        $response->assertForbidden();
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
            'name' => 'Finance Test '.Str::upper($suffix),
            'slug' => 'finance-test-'.Str::lower($suffix).'-'.Str::lower(Str::random(5)),
            'description' => 'Finance API test restaurant',
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
}
