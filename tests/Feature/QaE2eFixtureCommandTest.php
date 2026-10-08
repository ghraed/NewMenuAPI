<?php

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class QaE2eFixtureCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixture_setup_is_scoped_idempotent_and_cleanup_removes_only_its_run(): void
    {
        $otherUser = User::factory()->create(['email' => 'unrelated@example.test']);
        $existingFeature = Feature::factory()->create([
            'key' => 'qr_menu',
            'category' => 'existing',
            'description' => 'Pre-existing feature must survive QA cleanup.',
        ]);

        $this->artisan('qa:e2e-fixture setup --run-id=QA_RUN_COMMAND01')->assertSuccessful();
        $this->artisan('qa:e2e-fixture setup --run-id=QA_RUN_COMMAND01')->assertSuccessful();

        $this->assertSame(2, Restaurant::query()->where('name', 'like', 'QA_RUN_COMMAND01%')->count());
        $this->assertSame(5, User::query()->where('email', 'like', 'qa_run_command01_%@example.test')->count());
        $this->assertDatabaseHas('ingredients', [
            'name' => 'QA_RUN_COMMAND01 Beef',
            'current_stock_quantity' => '1000.000',
        ]);

        $this->artisan('qa:e2e-fixture cleanup --run-id=QA_RUN_COMMAND01')
            ->expectsOutputToContain('"clean":true')
            ->assertSuccessful();

        $this->assertSame(0, Restaurant::query()->where('name', 'like', 'QA_RUN_COMMAND01%')->count());
        $this->assertDatabaseHas('users', ['id' => $otherUser->id]);
        $this->assertDatabaseHas('features', ['id' => $existingFeature->id]);
        $this->assertDatabaseMissing('features', [
            'category' => 'QA_E2E_FIXTURE',
            'description' => 'Created by QA_RUN_COMMAND01 isolated E2E fixture.',
        ]);
    }

    public function test_fixture_rejects_identifiers_without_the_qa_prefix(): void
    {
        $this->artisan('qa:e2e-fixture setup --run-id=release')
            ->expectsOutput('A unique --run-id beginning with QA_RUN_ is required.')
            ->assertFailed();
    }

    public function test_guard_accepts_only_the_exact_disposable_environment(): void
    {
        $this->artisan('qa:e2e-fixture guard --run-id=QA_RUN_GUARD01')
            ->expectsOutputToContain('"safe":true')
            ->assertSuccessful();
    }

    public function test_guard_rejects_a_database_name_that_merely_contains_test(): void
    {
        $connection = (string) config('database.default');
        config()->set("database.connections.{$connection}.database", 'restaurantdb_test_copy');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('database is the exact run-owned disposable database');
        $this->artisan('qa:e2e-fixture guard --run-id=QA_RUN_GUARD02')->run();
    }

    public function test_guard_rejects_a_non_loopback_non_testing_url(): void
    {
        config()->set('app.url', 'https://menu.example.com');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non-testing application URL');
        $this->artisan('qa:e2e-fixture guard --run-id=QA_RUN_GUARD03')->run();
    }

    public function test_guard_rejects_production_environment(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outside APP_ENV=testing');
        $this->artisan('qa:e2e-fixture guard --run-id=QA_RUN_GUARD04')->run();
    }
}
