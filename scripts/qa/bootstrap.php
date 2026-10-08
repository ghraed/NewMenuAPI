<?php

// QA entrypoints only. Never expose this router from the production web server.
$root = dirname(__DIR__, 2);
$runtime = getenv('QA_RUNTIME_DIR');
$run = getenv('QA_RUN_ID');
$database = getenv('DB_DATABASE');
if (! is_string($run) || ! preg_match('/^[A-Za-z0-9_]{1,40}$/', $run)
    || getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'mysql'
    || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_URL')
    || ! in_array($database, ["menu_test_QA_RUN_{$run}_suite", "menu_test_QA_RUN_{$run}_browser"], true)
    || ! is_string($runtime) || ! is_file($runtime.'/owner.json')) {
    throw new RuntimeException('Refusing an unverified QA runtime/database.');
}
$owner = json_decode(file_get_contents($runtime.'/owner.json'), true, flags: JSON_THROW_ON_ERROR);
if (($owner['run_id'] ?? null) !== $run || ($owner['db_port'] ?? null) !== (int) getenv('DB_PORT')) {
    throw new RuntimeException('QA runtime ownership does not match.');
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->useEnvironmentPath($runtime);
$app->useStoragePath($runtime.'/storage');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Http::preventStrayRequests();

// Check effective Laravel configuration before migrations, fixture reset or serving.
if ($app->environment() !== 'testing' || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== $database
    || config('database.connections.mysql.host') !== '127.0.0.1'
    || (int) config('database.connections.mysql.port') !== $owner['db_port']
    || config('app.url') !== getenv('APP_URL')
    || ! str_starts_with(config('filesystems.disks.public.root'), $runtime.'/storage/')) {
    throw new RuntimeException('Effective application configuration is not the isolated QA target.');
}

// POS retries must be tested across HTTP requests; array cache exists for one request only.
// This owned QA entrypoint uses a persistent cache entirely inside the verified runtime.
$app['config']->set([
    'cache.default' => 'file',
    'cache.stores.file.path' => $runtime.'/storage/framework/cache/data',
    'cache.stores.file.lock_path' => $runtime.'/storage/framework/cache/data',
]);

// Opt in ONLY after all normal safety and run-ownership checks have succeeded.
// Real transports are confined to this runner's disposable schema and loopback port.
if (getenv('QA_OPERATIONAL') === '1') {
    require __DIR__.'/operational-config.php';
}

return $app;
