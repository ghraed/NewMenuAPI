<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\BuildsRestaurantOrderFlow;
use Tests\TestCase;

class GuestOrderIdempotencyConcurrencyTest extends TestCase
{
    use BuildsRestaurantOrderFlow;
    use DatabaseMigrations;

    public function test_two_concurrent_http_requests_with_one_key_create_one_order(): void
    {
        $owner = User::factory()->admin()->create([
            'name' => 'QA_RUN_REL concurrent owner',
            'email' => 'qa_run_rel_concurrent_'.Str::lower(Str::random(8)).'@example.test',
        ]);
        $restaurant = $this->createRestaurant($owner, attributes: [
            'name' => 'QA_RUN_REL concurrent restaurant',
            'slug' => 'qa-run-rel-concurrent-'.Str::lower(Str::random(8)),
        ]);
        $dish = $this->createDish($restaurant, 'QA_RUN_REL concurrent dish', 12.00);
        ['session' => $session, 'token' => $token] = $this->openGuestAccess($restaurant, 1);
        $path = "/api/table-session/{$session->id}/order";
        $payload = json_encode([
            'notes' => 'QA_RUN_REL simultaneous submit',
            'items' => [['dish_id' => $dish->id, 'quantity' => 2]],
        ], JSON_THROW_ON_ERROR);
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_GUEST_DEVICE_ID' => 'invoice-test-device',
            'HTTP_X_GUEST_ACCESS_TOKEN' => $token,
            'HTTP_X_IDEMPOTENCY_KEY' => 'QA_RUN_REL-concurrent-key',
        ];

        $runId = 'QA_RUN_REL_'.Str::uuid();
        $gatePath = sys_get_temp_dir()."/{$runId}.gate";
        $resultPaths = [
            sys_get_temp_dir()."/{$runId}.one.json",
            sys_get_temp_dir()."/{$runId}.two.json",
        ];
        $children = [];

        try {
            foreach ($resultPaths as $resultPath) {
                $processId = pcntl_fork();
                $this->assertNotSame(-1, $processId, 'Unable to fork concurrent HTTP test process.');

                if ($processId === 0) {
                    while (! is_file($gatePath)) {
                        usleep(1_000);
                    }

                    DB::disconnect();
                    DB::purge();
                    $kernel = app(Kernel::class);
                    $request = Request::create($path, 'POST', [], [], [], $server, $payload);
                    $response = $kernel->handle($request);
                    $decoded = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
                    $kernel->terminate($request, $response);
                    file_put_contents($resultPath, json_encode([
                        'status' => $response->getStatusCode(),
                        'order_id' => $decoded['order']['id'] ?? null,
                    ], JSON_THROW_ON_ERROR));
                    exit(0);
                }

                $children[] = $processId;
            }

            touch($gatePath);
            foreach ($children as $processId) {
                pcntl_waitpid($processId, $status);
                $this->assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0);
            }

            $responses = array_map(
                static fn (string $resultPath): array => json_decode(
                    (string) file_get_contents($resultPath),
                    true,
                    flags: JSON_THROW_ON_ERROR
                ),
                $resultPaths
            );
        } finally {
            foreach ([$gatePath, ...$resultPaths] as $temporaryPath) {
                if (is_file($temporaryPath)) {
                    unlink($temporaryPath);
                }
            }
        }

        DB::purge();

        $this->assertEqualsCanonicalizing([200, 201], array_column($responses, 'status'));
        $this->assertCount(1, array_unique(array_column($responses, 'order_id')));
        $this->assertSame(1, Order::query()->where('table_session_id', $session->id)->count());
        $this->assertSame(1, DB::table('order_items')->whereIn(
            'order_id',
            Order::query()->where('table_session_id', $session->id)->select('id')
        )->count());
        $this->assertSame(1, DB::table('guest_order_idempotencies')->where('table_session_id', $session->id)->count());
        $this->assertSame(0, DB::table('stock_movements')->whereIn(
            'order_id',
            Order::query()->where('table_session_id', $session->id)->select('id')
        )->count());
        $this->assertSame(0, DB::table('invoices')->where('restaurant_id', $restaurant->id)->count());
    }
}
