<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

trait RunsContentionRequests
{
    private function raceRequests(array $requests, string $barrierTable, ?array $lock = null): array
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $dir = getenv('QA_RUNTIME_DIR').'/tmp/contention-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $workers = [];
        try {
            if ($lock) {
                DB::beginTransaction();
                DB::table($lock[0])->where('id', $lock[1])->lockForUpdate()->first();
            }
            foreach ($requests as $i => $request) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/contentionRequestWorker.php')], base_path(), getenv(), timeout: 30);
                $worker->setInput(json_encode([...$request, 'now' => now()->toIso8601String(), 'barrier_table' => $barrierTable, 'lock_table' => $lock[0] ?? null, 'ready' => "$dir/ready$i", 'gate' => "$dir/gate"]));
                $worker->start();
                $workers[] = $worker;
            }
            $wait = function (string $suffix) use ($workers, $dir): void {
                $deadline = microtime(true) + 12;
                while (! is_file("$dir/ready0$suffix") || ! is_file("$dir/ready1$suffix")) {
                    foreach ($workers as $worker) {
                        if (! $worker->isRunning()) {
                            $this->fail('Worker exited before barrier: '.$worker->getErrorOutput().$worker->getOutput());
                        }
                    }
                    if (microtime(true) >= $deadline) {
                        $this->fail('Both independent workers must reach the barrier.');
                    }
                    usleep(10000);
                }
            };
            $wait('');
            touch("$dir/gate");
            if ($lock) {
                $wait('.locking');
                DB::commit();
            }
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $results[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertCount(2, array_unique(array_column($results, 'connection_id')));
            file_put_contents(getenv('QA_EVIDENCE_DIR').'/task9-contention.jsonl', json_encode(['scenario' => $this->name(), 'requests' => array_column($requests, 'path'), 'both_ready' => true, 'both_locking' => $lock !== null, 'held_table' => $lock[0] ?? null, 'results' => $results])."\n", FILE_APPEND);

            return $results;
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                $worker->stop();
            }
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
