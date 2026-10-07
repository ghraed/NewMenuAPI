<?php

$app = $app ?? require __DIR__.'/bootstrap.php';
echo json_encode([
    'run_id' => getenv('QA_RUN_ID'),
    'environment' => $app->environment(),
    'url' => config('app.url'),
    'database' => config('database.connections.mysql.database'),
    'host' => config('database.connections.mysql.host'),
    'port' => (int) config('database.connections.mysql.port'),
    'storage' => storage_path(),
    'mail' => config('mail.default'),
    'broadcast' => config('broadcasting.default'),
    'queue' => config('queue.default'),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
