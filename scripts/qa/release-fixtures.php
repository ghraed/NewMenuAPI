<?php

// CLI-only, synthetic fixtures for the owned release-image smoke runner.
use App\Models\Feature;
use App\Models\Restaurant;
use App\Models\RestaurantFeature;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$run = getenv('QA_RUN_ID');
if (! is_string($run) || ! preg_match('/^[A-Za-z0-9_]{1,40}$/', $run)
    || $app->environment() !== 'testing'
    || config('database.connections.mysql.database') !== 'menu_test_QA_RUN_'.$run.'_release'
    || config('database.connections.mysql.host') !== 'qa-db'
    || config('app.key') !== getenv('APP_KEY')
    || config('app.url') !== getenv('APP_URL')
    || ! $app->configurationIsCached() || is_file(base_path('.env'))) {
    throw new RuntimeException('Refusing an unverified release-image test environment.');
}

$environment = [
    'environment' => $app->environment(), 'url' => config('app.url'),
    'database' => config('database.connections.mysql.database'),
    'host' => config('database.connections.mysql.host'), 'port' => 3306,
    'config_cached' => true, 'runtime_key_preserved' => true,
    'storage' => storage_path(), 'php' => PHP_VERSION,
    'mail' => config('mail.default'), 'queue' => config('queue.default'),
    'broadcast' => config('broadcasting.default'),
];
if (in_array('--environment', $argv, true)) {
    echo json_encode($environment, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
    exit(0);
}

$tokens = [];
$metadata = [];
foreach (['a', 'b'] as $tenant) {
    $prefix = 'QA_RUN_'.$run.'_'.$tenant;
    $user = User::query()->create([
        'name' => $prefix.'_admin', 'email' => $prefix.'@example.invalid',
        'role' => User::ROLE_ADMIN, 'is_active' => true,
        'password' => Str::random(40),
    ]);
    $restaurant = Restaurant::query()->create([
        'uuid' => (string) Str::uuid(), 'user_id' => $user->id,
        'name' => $prefix.' مطعم', 'slug' => strtolower(str_replace('_', '-', $prefix)),
        'currency' => 'USD', 'other_currency' => 'LBP', 'dollar_rate' => '89500.75',
        'status' => 'active',
    ]);
    foreach (['finance_dashboard', 'vat_invoices', 'expense_management'] as $key) {
        $feature = Feature::query()->firstOrCreate(['key' => $key], [
            'name' => $key, 'category' => 'Testing', 'is_active_by_default' => false,
        ]);
        RestaurantFeature::query()->create([
            'restaurant_id' => $restaurant->id, 'feature_id' => $feature->id, 'enabled' => true,
        ]);
    }
    $tokens[$tenant] = $user->createToken($prefix)->plainTextToken;
    $metadata[$tenant] = [
        'restaurant_id' => $restaurant->id, 'status' => $restaurant->status,
        'role' => $user->role, 'enabled_features' => ['finance_dashboard', 'vat_invoices', 'expense_management'],
    ];
}
// The runner captures these in memory; never retain this output in evidence.
echo json_encode(['tokens' => $tokens, 'metadata' => $metadata], JSON_THROW_ON_ERROR).PHP_EOL;
