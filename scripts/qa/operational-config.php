<?php

if ($database !== "menu_test_QA_RUN_{$run}_browser"
    || ! isset($owner['reverb_port']) || (string) $owner['reverb_port'] !== getenv('QA_REVERB_PORT')
    || $owner['reverb_port'] < 1024 || $owner['reverb_port'] > 65535
    || getenv('DOMAIN_PROVISIONING_BRIDGE_DIR') !== $runtime.'/bridge') {
    throw new RuntimeException('Operational transports require owned browser database, port and bridge.');
}
$transport = [
    'driver' => 'reverb', 'key' => 'qa-'.$run, 'secret' => 'QA_RUN_'.$run.'_local_only', 'app_id' => 'qa-'.$run,
    'options' => ['host' => '127.0.0.1', 'port' => $owner['reverb_port'], 'scheme' => 'http', 'useTLS' => false],
    'client_options' => [],
];
$reverbApp = config('reverb.apps.apps.0');
$reverbApp = array_replace($reverbApp, array_intersect_key($transport, array_flip(['key', 'secret', 'app_id', 'options'])));
$reverbApp['allowed_origins'] = ['127.0.0.1'];
$app['config']->set([
    'queue.default' => 'database',
    'queue.connections.database.connection' => 'mysql',
    'queue.connections.database.table' => 'jobs',
    'queue.connections.database.queue' => 'default',
    'broadcasting.default' => 'reverb',
    'broadcasting.connections.reverb' => $transport,
    'reverb.apps.apps' => [$reverbApp],
    'reverb.servers.reverb.host' => '127.0.0.1',
    'reverb.servers.reverb.port' => $owner['reverb_port'],
    'reverb.servers.reverb.scaling.enabled' => false,
    'services.webpush.public_key' => null,
    'services.webpush.private_key' => null,
    'services.fcm.server_key' => null,
]);
// Safety boot registered channel policies on the null driver. Register the SAME
// application policies on the local Reverb driver after switching transports.
require base_path('routes/channels.php');
