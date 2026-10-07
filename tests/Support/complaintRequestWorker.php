<?php

// Separate application process and MySQL connection, restricted to the owned QA runtime.
$app = require dirname(__DIR__, 2).'/scripts/qa/bootstrap.php';
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
Carbon\CarbonImmutable::setTestNow(Carbon\CarbonImmutable::parse($input['now']));
$connection = Illuminate\Support\Facades\DB::connection();
$connectionId = (int) $connection->selectOne('SELECT CONNECTION_ID() AS id')->id;
$bound = false;
// Freeze both requests immediately after route binding has read the draft. This
// exercises stale route models as well as independent overlapping transactions.
$connection->listen(function ($event) use ($input, &$bound): void {
    if ($bound || ! str_contains($event->sql, '`pos_complaint_adjustments`')
        || str_contains($event->sql, 'for update')) {
        return;
    }
    $bound = true;
    file_put_contents($input['ready'], 'bound');
    $deadline = microtime(true) + 15;
    while (! is_file($input['gate'])) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('QA request barrier timed out.');
        }
        usleep(10000);
    }
});
$connection->beforeExecuting(function ($query) use ($input): void {
    if (str_contains($query, 'for update')) {
        file_put_contents($input['ready'].'.locking', 'attempted');
    }
});
$request = Illuminate\Http\Request::create(getenv('APP_URL').$input['path'], 'POST', [], [], [], [
    'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
    'HTTP_AUTHORIZATION' => 'Bearer '.$input['token'],
], '{}');
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
echo json_encode(['connection_id' => $connectionId, 'status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
