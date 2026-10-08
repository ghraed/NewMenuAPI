<?php

define('ARTISAN_BINARY', __FILE__);
$app = require __DIR__.'/bootstrap.php';
// Scheduler rehearsal freezes only schedule:run's due-time calculation, not the daemon clock.
if (($argv[1] ?? '') === 'schedule:run' && getenv('QA_SCHEDULE_TIME')) {
    Illuminate\Support\Carbon::setTestNow(getenv('QA_SCHEDULE_TIME'));
}
exit($app->make(Illuminate\Contracts\Console\Kernel::class)->handle(
    new Symfony\Component\Console\Input\ArgvInput
));
