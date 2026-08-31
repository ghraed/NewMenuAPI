<?php

namespace App\Console\Commands;

use App\Models\Dish;
use App\Models\Feature;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RestaurantFeature;
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
        {action : setup, verify, or cleanup}
        {--run-id= : Required QA_RUN_ identifier}';

    protected $description = 'Create, verify, or remove the isolated release-hardening browser fixture';

    private const ENABLED_FEATURES = [
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

        if (! str_contains(strtolower($database), 'test')) {
            throw new RuntimeException('Refusing QA fixture operation because the database name does not contain test.');
        }

        if (! in_array($host, ['127.0.0.1', 'localhost', 'testing.local'], true) && ! str_ends_with($host, '.test')) {
            throw new RuntimeException('Refusing QA fixture operation for a non-testing application URL.');
        }
    }

    private function setup(string $runId): int
    {
        $this->cleanupRecords($runId);

        foreach ([...self::ENABLED_FEATURES, ...self::DISABLED_FEATURES] as $featureKey) {
            Feature::query()->firstOrCreate(
                ['key' => $featureKey],
                [
                    'name' => Str::headline($featureKey),
                    'description' => 'Required by the isolated QA browser fixture.',
                    'category' => 'QA',
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
                'users' => [
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
        $restaurant = Restaurant::query()->where('name', $runId.' Release Restaurant')->first();
        if (! $restaurant) {
            $this->error('Fixture restaurant is missing.');

            return self::FAILURE;
        }

        $completed = Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('notes', $runId.'_LIFECYCLE')
            ->where('status', Order::STATUS_ACCOUNTED)
            ->where('kitchen_status', Order::KITCHEN_STATUS_SERVED)
            ->first();
        $cancelled = Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('notes', $runId.'_CANCEL')
            ->where('status', Order::STATUS_STAFF_CANCELLED)
            ->first();
        $closedSession = TableSession::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('status', TableSession::STATUS_CLOSED)
            ->first();
        $invoice = Invoice::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('status', Invoice::STATUS_PAID)
            ->where('payment_reference', $runId.'_PAYMENT')
            ->first();

        $checks = [
            'accounted_served_order' => (bool) $completed,
            'cancelled_order' => (bool) $cancelled,
            'closed_session' => (bool) $closedSession,
            'paid_invoice' => (bool) $invoice,
            'invoice_matches_order' => (bool) ($completed && $invoice && $completed->invoice_number === $invoice->invoice_number),
        ];

        $this->line(json_encode(['run_id' => $runId, 'checks' => $checks], JSON_THROW_ON_ERROR));

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }

    private function cleanup(string $runId): int
    {
        $deleted = $this->cleanupRecords($runId);
        $this->line(json_encode(['run_id' => $runId, 'deleted_users' => $deleted], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function cleanupRecords(string $runId): int
    {
        $emails = collect(['owner', 'waiter', 'chef', 'accountant', 'tenant-b-owner'])
            ->map(fn (string $role): string => $this->email($runId, $role));

        return DB::transaction(function () use ($emails): int {
            $users = User::query()->whereIn('email', $emails)->get();
            $count = $users->count();

            $users
                ->sortBy(fn (User $user): int => $user->restaurant()->exists() ? 1 : 0)
                ->each(fn (User $user) => $user->delete());

            return $count;
        });
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

    private function failUnknownAction(): int
    {
        $this->error('Action must be setup, verify, or cleanup.');

        return self::FAILURE;
    }
}
