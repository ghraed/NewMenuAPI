<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PosComplaintAdjustment;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\BuildsComplaintRefundFixtures;
use Tests\TestCase;

class PosComplaintRefundConcurrencyTest extends TestCase
{
    use BuildsComplaintRefundFixtures, DatabaseMigrations;

    public function test_true_concurrent_approvals_share_original_sale_balance(): void
    {
        $a = $this->draft();
        $b = $this->draft();
        $results = $this->race(["/api/pos/complaint-adjustments/{$a}/post", "/api/pos/complaint-adjustments/{$b}/post"]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 422], $statuses);
        $this->assertSame(1, PosComplaintAdjustment::where('status', 'posted')->count());
        $this->assertReport(800, 200);
        $this->approve($this->draft('2.00'))->assertOk();
        $this->assertReport(1000, 0);
    }

    public function test_true_concurrent_approvals_share_invoice_balance(): void
    {
        $other = $this->sale();
        $a = $this->draft();
        $b = $this->draft('8.00', [], $other);
        $results = $this->race(["/api/pos/complaint-adjustments/{$a}/post", "/api/pos/complaint-adjustments/{$b}/post"]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 422], $statuses);
        $this->assertSame(1, PosComplaintAdjustment::where('status', 'posted')->count());
        $this->assertReport(800, 200);
    }

    public function test_true_concurrent_same_adjustment_posts_deduct_gift_once(): void
    {
        $id = $this->draft('2.00', ['gifts' => [['dish_id' => $this->dish->id, 'quantity' => 1]]]);
        $results = $this->race(array_fill(0, 2, "/api/pos/complaint-adjustments/{$id}/post"));
        $this->assertSame([200, 200], array_column($results, 'status'));
        $this->assertSame('4.000', $this->ingredient->fresh()->current_stock_quantity);
        $this->assertSame(1, StockMovement::query()->count());
    }

    public function test_true_concurrent_post_and_void_have_one_immutable_outcome(): void
    {
        $id = $this->draft('2.00', ['gifts' => [['dish_id' => $this->dish->id, 'quantity' => 1]]]);
        $results = $this->race(["/api/pos/complaint-adjustments/{$id}/post", "/api/pos/complaint-adjustments/{$id}/void"]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 422], $statuses);
        $adjustment = PosComplaintAdjustment::findOrFail($id);
        $posted = $adjustment->status === 'posted';
        $this->assertContains($adjustment->status, ['posted', 'void']);
        $this->assertSame($posted ? 1 : 0, StockMovement::query()->count());
        $this->assertSame($posted ? '4.000' : '5.000', $this->ingredient->fresh()->current_stock_quantity);
        $this->assertSame($posted, $adjustment->posted_at !== null);
        $this->assertSame(! $posted, $adjustment->voided_at !== null);
    }

    protected function tearDown(): void
    {
        try {
            // The legacy rollback restores an invoice-number unique index. Remove
            // only this test's grouped fixture numbers before that rollback; keep
            // migrations and their seeded baseline intact for subsequent suites.
            DB::table('orders')->where('restaurant_id', $this->restaurant->id)->update(['invoice_number' => null]);
        } finally {
            parent::tearDown();
        }
    }

    private function race(array $paths): array
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $dir = getenv('QA_RUNTIME_DIR').'/tmp/'.$this->prefix;
        mkdir($dir, 0700, true);
        $token = $this->admin->createToken($this->prefix)->plainTextToken;
        $processes = [];
        try {
            DB::beginTransaction();
            // Hold the common settlement until both independent workers are ready.
            Invoice::whereKey($this->invoice->id)->lockForUpdate()->firstOrFail();
            try {
                foreach ($paths as $i => $path) {
                    $process = new Process([PHP_BINARY, base_path('tests/Support/complaintRequestWorker.php')], base_path(), getenv(), timeout: 20);
                    $process->setInput(json_encode(['now' => now()->toIso8601String(), 'path' => $path, 'token' => $token, 'ready' => "{$dir}/ready{$i}", 'gate' => "{$dir}/gate"]));
                    $process->start();
                    $processes[] = $process;
                }
                $deadline = microtime(true) + 10;
                while (! is_file("{$dir}/ready0") || ! is_file("{$dir}/ready1")) {
                    foreach ($processes as $worker) {
                        if (! $worker->isRunning()) {
                            $this->fail('QA worker exited: '.$worker->getErrorOutput().$worker->getOutput());
                        }
                    }
                    if (microtime(true) > $deadline) {
                        $this->fail('Separate QA application workers did not become ready.');
                    }
                    usleep(10000);
                }
                touch("{$dir}/gate");
                // Both bound drafts are stale now. Wait for both transaction attempts
                // while the parent keeps the shared invoice unavailable.
                $deadline = microtime(true) + 5;
                while (! is_file("{$dir}/ready0.locking") || ! is_file("{$dir}/ready1.locking")) {
                    if (microtime(true) > $deadline || ! $processes[0]->isRunning() || ! $processes[1]->isRunning()) {
                        break;
                    }
                    usleep(10000);
                }
            } finally {
                DB::commit();
            }
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertCount(2, array_unique(array_column($results, 'connection_id')));
            if ($evidence = getenv('QA_EVIDENCE_DIR')) {
                file_put_contents($evidence.'/refund-concurrent-api.jsonl', json_encode(['scenario' => $this->name(), 'paths' => $paths, 'both_bound_drafts' => true, 'locking_attempts' => [is_file("{$dir}/ready0.locking"), is_file("{$dir}/ready1.locking")], 'responses' => $results])."\n", FILE_APPEND);
            }

            $this->assertFileExists("{$dir}/ready0.locking");
            $this->assertFileExists("{$dir}/ready1.locking");

            return $results;
        } finally {
            foreach ($processes as $process) {
                $process->stop();
            }
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
