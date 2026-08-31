<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QaE2eFixtureCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixture_setup_is_scoped_idempotent_and_cleanup_removes_only_its_run(): void
    {
        $otherUser = User::factory()->create(['email' => 'unrelated@example.test']);

        $this->artisan('qa:e2e-fixture setup --run-id=QA_RUN_COMMAND01')->assertSuccessful();
        $this->artisan('qa:e2e-fixture setup --run-id=QA_RUN_COMMAND01')->assertSuccessful();

        $this->assertSame(2, Restaurant::query()->where('name', 'like', 'QA_RUN_COMMAND01%')->count());
        $this->assertSame(5, User::query()->where('email', 'like', 'qa_run_command01_%@example.test')->count());

        $this->artisan('qa:e2e-fixture cleanup --run-id=QA_RUN_COMMAND01')->assertSuccessful();

        $this->assertSame(0, Restaurant::query()->where('name', 'like', 'QA_RUN_COMMAND01%')->count());
        $this->assertDatabaseHas('users', ['id' => $otherUser->id]);
    }

    public function test_fixture_rejects_identifiers_without_the_qa_prefix(): void
    {
        $this->artisan('qa:e2e-fixture setup --run-id=release')
            ->expectsOutput('A unique --run-id beginning with QA_RUN_ is required.')
            ->assertFailed();
    }
}
