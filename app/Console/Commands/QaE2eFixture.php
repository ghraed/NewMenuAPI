<?php

namespace App\Console\Commands;

use App\Models\Dish;
use App\Models\DishIngredient;
use App\Models\Feature;
use App\Models\Ingredient;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItemIngredientUsage;
use App\Models\Restaurant;
use App\Models\RestaurantFeature;
use App\Models\StockMovement;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class QaE2eFixture extends Command
{
    protected $signature = 'qa:e2e-fixture
        {action : guard, setup, verify, or cleanup}
        {--run-id= : Required QA_RUN_ identifier}';

    protected $description = 'Guard, create, verify, or remove the isolated release-hardening browser fixture';

    private const FEATURE_CATEGORY = 'QA_E2E_FIXTURE';

    private const ENABLED_FEATURES = [
        'custom_domain',
        'qr_menu',
        'table_ordering',
        'waiter_call',
        'request_bill',
        'realtime_staff_orders',
        'finance_dashboard',
        'dish_profitability',
        'vat_invoices',
        'expense_management',
        'multi_language',
        'ingredient_stock_deduction',
    ];

    private const DISABLED_FEATURES = ['invoice_splitting', 'push_notifications'];

    public function handle(): int
    {
        $this->assertSafeEnvironment();

        $runId = strtoupper(trim((string) $this->option('run-id')));
        if (! preg_match('/^QA_RUN_[A-Z0-9][A-Z0-9_-]{2,48}$/', $runId)) {
            $this->error('A unique --run-id beginning with QA_RUN_ is required.');

            return self::FAILURE;
        }

        return match (strtolower((string) $this->argument('action'))) {
            'guard' => $this->guard($runId),
            'setup' => $this->setup($runId),
            'verify' => $this->verify($runId),
            'cleanup' => $this->cleanup($runId),
            default => $this->failUnknownAction(),
        };
    }

    private function assertSafeEnvironment(): void
    {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        $appUrl = (string) config('app.url');
        $host = strtolower((string) parse_url($appUrl, PHP_URL_HOST));

        if (! app()->environment('testing')) {
            throw new RuntimeException('Refusing QA fixture operation outside APP_ENV=testing.');
        }

        $qaId = (string) getenv('QA_RUN_ID');
        $expected = (string) getenv('DB_DATABASE');
        $ownedRoot = (string) getenv('QA_RUNTIME_DIR');
        $owner = is_file($ownedRoot.'/owner.json') ? json_decode(file_get_contents($ownedRoot.'/owner.json'), true) : null;
        if ($connection !== 'mysql' || $qaId === '' || $database !== $expected
            || ! in_array($database, ['menu_test_QA_RUN_'.$qaId.'_suite', 'menu_test_QA_RUN_'.$qaId.'_browser'], true)
            || ($owner['run_id'] ?? null) !== $qaId) {
            throw new RuntimeException('Refusing QA fixture operation unless the database is the exact run-owned disposable database.');
        }

        if (! in_array($host, ['127.0.0.1', 'localhost'], true)) {
            throw new RuntimeException('Refusing QA fixture operation for a non-testing application URL.');
        }
    }

    private function guard(string $runId): int
    {
        $this->line(json_encode([
            'run_id' => $runId,
            'environment' => app()->environment(),
            'database' => (string) config('database.connections.'.config('database.default').'.database'),
            'app_url' => (string) config('app.url'),
            'safe' => true,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function setup(string $runId): int
    {
        $cleanup = $this->cleanupRecords($runId);
        if (! $cleanup['clean']) {
            $this->error('The previous fixture could not be removed without residue.');

            return self::FAILURE;
        }

        foreach ([...self::ENABLED_FEATURES, ...self::DISABLED_FEATURES] as $featureKey) {
            Feature::query()->firstOrCreate(
                ['key' => $featureKey],
                [
                    'name' => Str::headline($featureKey),
                    'description' => $this->featureDescription($runId),
                    'category' => self::FEATURE_CATEGORY,
                    'is_active_by_default' => false,
                ]
            );
        }

        $fixture = DB::transaction(function () use ($runId): array {
            $password = 'QA-only-password-42!';
            $owner = $this->createUser($runId, 'owner', User::ROLE_ADMIN, $password);
            $restaurant = Restaurant::query()->create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $owner->id,
                'name' => $runId.' Release Restaurant',
                'slug' => strtolower(str_replace('_', '-', $runId)).'-release',
                'status' => 'active',
                'description' => 'Disposable release-hardening E2E tenant.',
                'address' => 'Testing only',
                'currency' => 'USD',
                'other_currency' => 'LBP',
                'dollar_rate' => '89500.00',
                'profile' => [
                    'short_description' => 'QA E2E fixture',
                    'menu_categories' => ['Mains'],
                ],
            ]);

            $waiter = $this->createUser($runId, 'waiter', User::ROLE_STAFF, $password);
            $chef = $this->createUser($runId, 'chef', User::ROLE_CHEF, $password);
            $accountant = $this->createUser($runId, 'accountant', User::ROLE_ACCOUNTANT, $password);
            $restaurant->staffUsers()->sync([$waiter->id, $chef->id, $accountant->id]);

            $table = $restaurant->tables()->orderBy('id')->firstOrFail();
            $waiter->assignedTables()->sync([$table->id]);

            $dish = Dish::factory()->for($restaurant)->published()->create([
                'name' => $runId.' Mixed Grill',
                'name_ar' => 'مشاوي اختبار',
                'description' => 'Disposable browser-test dish.',
                'description_ar' => 'طبق مخصص لاختبار المتصفح.',
                'category' => 'Mains',
                'category_ar' => 'الأطباق الرئيسية',
                'price' => '12.50',
                'currency' => 'USD',
                'is_anchor' => true,
                'is_profitable' => true,
            ]);

            $ingredient = Ingredient::factory()->for($restaurant)->create([
                'name' => $runId.' Beef',
                'stock_unit' => Ingredient::UNIT_GRAM,
                'current_stock_quantity' => '1000.000',
                'low_stock_threshold' => '100.000',
                'target_quantity' => '1000.000',
                'is_active' => true,
            ]);
            DishIngredient::factory()->for($dish)->for($ingredient)->create([
                'quantity' => '100.000',
                'unit' => Ingredient::UNIT_GRAM,
                'order_index' => 0,
            ]);

            $featureIds = Feature::query()->whereIn('key', self::ENABLED_FEATURES)->pluck('id');
            foreach ($featureIds as $featureId) {
                RestaurantFeature::query()->updateOrCreate(
                    ['restaurant_id' => $restaurant->id, 'feature_id' => $featureId],
                    ['enabled' => true]
                );
            }

            $tenantOwner = $this->createUser($runId, 'tenant-b-owner', User::ROLE_ADMIN, $password);
            $tenantB = Restaurant::query()->create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $tenantOwner->id,
                'name' => $runId.' Isolated Tenant',
                'slug' => strtolower(str_replace('_', '-', $runId)).'-tenant-b',
                'status' => 'active',
                'currency' => 'USD',
                'dollar_rate' => '1.00',
            ]);

            return [
                'run_id' => $runId,
                'restaurant_id' => $restaurant->id,
                'tenant_b_restaurant_id' => $tenantB->id,
                'table_id' => $table->id,
                'table_number' => 1,
                'dish_id' => $dish->id,
                'ingredient_id' => $ingredient->id,
                'users' => [
                    'owner' => $owner->email,
                    'waiter' => $waiter->email,
                    'chef' => $chef->email,
                    'accountant' => $accountant->email,
                    'tenant_b_owner' => $tenantOwner->email,
                ],
                'enabled_features' => self::ENABLED_FEATURES,
                'disabled_features' => self::DISABLED_FEATURES,
            ];
        });

        $this->line(json_encode($fixture, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function verify(string $runId): int
    {
        $restaurantQuery = Restaurant::query()->where('name', $runId.' Release Restaurant');
        $restaurant = $restaurantQuery->first();
        $tenantCount = Restaurant::query()->whereIn('name', [
            $runId.' Release Restaurant',
            $runId.' Isolated Tenant',
        ])->count();
        if (! $restaurant) {
            $this->line(json_encode(['run_id' => $runId, 'checks' => ['fixture_restaurant_count' => false]], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $orders = Order::query()->where('restaurant_id', $restaurant->id)
            ->whereIn('notes', [$runId.'_LIFECYCLE', $runId.'_CANCEL'])
            ->with('items')->get();
        $completed = $orders->firstWhere('notes', $runId.'_LIFECYCLE');
        $cancelled = $orders->firstWhere('notes', $runId.'_CANCEL');
        $sessions = TableSession::query()->where('restaurant_id', $restaurant->id)->get();
        $closedSession = $sessions->firstWhere('status', TableSession::STATUS_CLOSED);
        $invoices = Invoice::query()->where('restaurant_id', $restaurant->id)
            ->where('payment_reference', $runId.'_PAYMENT')->with('items')->get();
        $invoice = $invoices->first();
        $ingredient = Ingredient::query()->where('restaurant_id', $restaurant->id)
            ->where('name', $runId.' Beef')->first();
        $movements = $ingredient
            ? StockMovement::query()->where('ingredient_id', $ingredient->id)->get()
            : collect();
        $usages = OrderItemIngredientUsage::query()
            ->whereIn('order_id', $orders->pluck('id'))->get();
        $completedItem = $completed?->items->first();
        $invoiceItem = $invoice?->items->first();

        $checks = [
            'fixture_restaurant_count' => $restaurantQuery->count() === 1,
            'fixture_tenant_count' => $tenantCount === 2,
            'order_count' => $orders->count() === 2,
            'lifecycle_order_unique' => $orders->where('notes', $runId.'_LIFECYCLE')->count() === 1,
            'lifecycle_order_state' => $completed?->status === Order::STATUS_ACCOUNTED
                && $completed?->kitchen_status === Order::KITCHEN_STATUS_SERVED,
            'lifecycle_order_totals' => $completed?->subtotal === '25.00'
                && $completed?->total === '25.00' && $completed?->currency === 'USD',
            'lifecycle_item_exact' => $completedItem?->quantity === 2
                && $completedItem?->unit_price === '12.50' && $completedItem?->line_subtotal === '25.00',
            'cancelled_order_unique' => $orders->where('notes', $runId.'_CANCEL')->count() === 1,
            'cancelled_order_state' => $cancelled?->status === Order::STATUS_STAFF_CANCELLED,
            'actor_associations' => $completed?->confirmed_by !== null
                && $completed?->kitchen_updated_by !== null && $completed?->accounted_by !== null
                && $cancelled?->confirmed_by !== null && $cancelled?->cancelled_by !== null,
            'closed_session_unique' => $sessions->count() === 1
                && $closedSession !== null && $closedSession->finalized_by_staff_id !== null,
            'orders_share_closed_session' => $closedSession !== null
                && $orders->count() === 2
                && $orders->every(fn (Order $order): bool => $order->table_session_id === $closedSession->id),
            'paid_invoice_unique' => $invoices->count() === 1 && $invoice?->status === Invoice::STATUS_PAID,
            'invoice_number_unique' => $invoice !== null
                && Invoice::query()->where('restaurant_id', $restaurant->id)
                    ->where('invoice_number', $invoice->invoice_number)->count() === 1,
            'invoice_totals_exact' => $invoice?->subtotal === '25.00'
                && $invoice?->discount_amount === '0.00' && $invoice?->taxable_subtotal === '25.00'
                && $invoice?->service_charge_amount === '0.00' && $invoice?->vat_amount === '0.00'
                && $invoice?->total === '25.00' && $invoice?->currency === 'USD',
            'invoice_payment_exact' => $invoice?->payment_method === 'card'
                && $invoice?->payment_reference === $runId.'_PAYMENT' && $invoice?->paid_at !== null,
            'invoice_item_exact' => $invoice?->items->count() === 1
                && $invoiceItem?->order_item_id === $completedItem?->id
                && $invoiceItem?->quantity === '2.000' && $invoiceItem?->unit_price === '12.50'
                && $invoiceItem?->line_total === '25.00',
            'invoice_matches_lifecycle_order' => $completed !== null && $invoice !== null
                && $completed->invoice_number === $invoice->invoice_number,
            'inventory_final_quantity' => $ingredient?->current_stock_quantity === '800.000',
            'inventory_usage_snapshots' => $usages->count() === 2
                && $orders->every(fn (Order $order): bool => $usages->where('order_id', $order->id)->count() === 1)
                && $usages->every(fn (OrderItemIngredientUsage $usage): bool => $usage->consumed_quantity === '200.000'),
            'inventory_consumption_movements' => $movements->where('movement_type', StockMovement::TYPE_ORDER_CONSUMPTION)->count() === 2
                && $movements->where('movement_type', StockMovement::TYPE_ORDER_CONSUMPTION)
                    ->every(fn (StockMovement $movement): bool => $movement->quantity_delta === '-200.000'),
            'inventory_cancellation_restoration' => $cancelled !== null
                && $movements->where('order_id', $cancelled->id)
                    ->where('movement_type', StockMovement::TYPE_CANCELLATION_RESTORE)->count() === 1
                && $movements->where('order_id', $cancelled->id)
                    ->where('movement_type', StockMovement::TYPE_CANCELLATION_RESTORE)
                    ->every(fn (StockMovement $movement): bool => $movement->quantity_delta === '200.000'),
        ];

        $this->line(json_encode([
            'run_id' => $runId,
            'counts' => [
                'restaurants' => $tenantCount,
                'orders' => $orders->count(),
                'sessions' => $sessions->count(),
                'invoices' => $invoices->count(),
                'invoice_items' => $invoice?->items->count() ?? 0,
                'inventory_usages' => $usages->count(),
                'stock_movements' => $movements->count(),
            ],
            'checks' => $checks,
        ], JSON_THROW_ON_ERROR));

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }

    private function cleanup(string $runId): int
    {
        $cleanup = $this->cleanupRecords($runId);
        $this->line(json_encode(['run_id' => $runId, ...$cleanup], JSON_THROW_ON_ERROR));

        return $cleanup['clean'] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{deleted_users: int, deleted_features: int, residue: array<string, int>, clean: bool} */
    private function cleanupRecords(string $runId): array
    {
        $emails = collect(['owner', 'waiter', 'chef', 'accountant', 'tenant-b-owner'])
            ->map(fn (string $role): string => $this->email($runId, $role));

        $deleted = DB::transaction(function () use ($emails, $runId): array {
            $users = User::query()->whereIn('email', $emails)->get();
            $count = $users->count();

            $users
                ->sortBy(fn (User $user): int => $user->restaurant()->exists() ? 1 : 0)
                ->each(fn (User $user) => $user->delete());

            $featureIds = Feature::query()
                ->where('category', self::FEATURE_CATEGORY)
                ->where('description', $this->featureDescription($runId))
                ->whereDoesntHave('restaurantFeatureOverrides')
                ->pluck('id');
            $deletedFeatures = Feature::query()->whereIn('id', $featureIds)->delete();

            return ['users' => $count, 'features' => $deletedFeatures];
        });

        $residue = [
            'users' => User::query()->whereIn('email', $emails)->count(),
            'restaurants' => Restaurant::query()->whereIn('name', [
                $runId.' Release Restaurant',
                $runId.' Isolated Tenant',
            ])->count(),
            'created_features' => Feature::query()
                ->where('category', self::FEATURE_CATEGORY)
                ->where('description', $this->featureDescription($runId))->count(),
        ];

        return [
            'deleted_users' => $deleted['users'],
            'deleted_features' => $deleted['features'],
            'residue' => $residue,
            'clean' => array_sum($residue) === 0,
        ];
    }

    private function createUser(string $runId, string $label, string $role, string $password): User
    {
        return User::query()->create([
            'name' => $runId.' '.$label,
            'email' => $this->email($runId, $label),
            'role' => $role,
            'is_active' => true,
            'email_verified_at' => now(),
            'password' => Hash::make($password),
        ]);
    }

    private function email(string $runId, string $label): string
    {
        return strtolower($runId.'_'.$label).'@example.test';
    }

    private function featureDescription(string $runId): string
    {
        return 'Created by '.$runId.' isolated E2E fixture.';
    }

    private function failUnknownAction(): int
    {
        $this->error('Action must be guard, setup, verify, or cleanup.');

        return self::FAILURE;
    }
}
