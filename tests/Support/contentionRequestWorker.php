<?php

$app = require dirname(__DIR__, 2).'/scripts/qa/bootstrap.php';
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
Carbon\CarbonImmutable::setTestNow(Carbon\CarbonImmutable::parse($input['now']));
$db = Illuminate\Support\Facades\DB::connection();
$id = (int) $db->selectOne('SELECT CONNECTION_ID() AS id')->id;
$ready = false;
$db->listen(function ($event) use ($input, &$ready): void {
    if ($ready || ! str_contains($event->sql, '`'.$input['barrier_table'].'`')) {
        return;
    }
    $ready = true;
    file_put_contents($input['ready'], 'bound');
    $deadline = microtime(true) + 15;
    while (! is_file($input['gate'])) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Contention barrier timed out.');
        }
        usleep(10000);
    }
});
$db->beforeExecuting(function ($sql) use ($input): void {
    if (str_contains($sql, 'for update')
        && ($input['lock_table'] === null || str_contains($sql, '`'.$input['lock_table'].'`'))) {
        touch($input['ready'].'.locking');
    }
});
$server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
foreach ($input['headers'] as $key => $value) {
    $server['HTTP_'.strtoupper(str_replace('-', '_', $key))] = $value;
}
$request = Illuminate\Http\Request::create(getenv('APP_URL').$input['path'], 'POST', [], [], [], $server, json_encode($input['payload']));
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
echo json_encode(['connection_id' => $id, 'status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
