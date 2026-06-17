<?php

namespace Tests\Feature;

use App\Jobs\ProvisionRestaurantDomainJob;
use App\Models\Restaurant;
use App\Models\RestaurantDomain;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Services\DomainProvisioner;
use App\Services\FeatureFlagService;
use App\Services\GlobalIngredientProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class SuperAdminCustomDomainProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_restaurant_creation_normalizes_custom_domain_and_dispatches_job(): void
    {
        Queue::fake();
        $this->mockRestaurantSetupDependencies();

        Sanctum::actingAs($this->createSaasOwner());

        $response = $this->postJson('/api/super-admin/restaurants', $this->restaurantPayload([
            'custom_domain' => '  HTTPS://WWW.Example.com/path?source=admin  ',
        ]));

        $response->assertCreated()
            ->assertJsonPath('restaurant.custom_domain', 'example.com')
            ->assertJsonPath('restaurant.custom_domain_status', 'pending_dns');

        $restaurant = Restaurant::query()->firstOrFail();

        $this->assertSame('example.com', $restaurant->custom_domain);
        $this->assertSame('pending_dns', $restaurant->custom_domain_status);
        $this->assertDatabaseHas('restaurant_domains', [
            'restaurant_id' => $restaurant->id,
            'domain' => 'example.com',
            'kind' => 'custom',
            'is_primary' => 1,
        ]);

        Queue::assertPushed(ProvisionRestaurantDomainJob::class, function (ProvisionRestaurantDomainJob $job) use ($restaurant): bool {
            return $job->restaurantId === $restaurant->id;
        });
    }

    public function test_super_admin_restaurant_creation_rejects_invalid_custom_domain(): void
    {
        Queue::fake();
        $this->mockRestaurantSetupDependencies();

        Sanctum::actingAs($this->createSaasOwner());

        $response = $this->postJson('/api/super-admin/restaurants', $this->restaurantPayload([
            'custom_domain' => 'example.com:8080',
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['custom_domain']);

        Queue::assertNothingPushed();
    }

    public function test_super_admin_restaurant_creation_rejects_duplicate_custom_domain(): void
    {
        Queue::fake();
        $this->mockRestaurantSetupDependencies();

        Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory()->admin()->create()->id,
            'name' => 'Existing Domain',
            'slug' => 'existing-domain',
            'status' => 'active',
            'currency' => 'USD',
            'custom_domain' => 'taken.example.com',
            'custom_domain_status' => 'active',
            'ssl_issued_at' => now(),
            'profile' => ['menu_categories' => ['Main Courses']],
        ]);

        Sanctum::actingAs($this->createSaasOwner());

        $response = $this->postJson('/api/super-admin/restaurants', $this->restaurantPayload([
            'slug' => 'new-domain',
            'custom_domain' => 'taken.example.com',
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['custom_domain']);
    }

    public function test_super_admin_restaurant_update_requeues_when_custom_domain_changes(): void
    {
        Queue::fake();
        $this->mockRestaurantSetupDependencies();

        $restaurant = Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory()->admin()->create()->id,
            'name' => 'Update Domain',
            'slug' => 'update-domain',
            'status' => 'active',
            'currency' => 'USD',
            'profile' => ['menu_categories' => ['Main Courses']],
        ]);

        Sanctum::actingAs($this->createSaasOwner());

        $response = $this->patchJson("/api/super-admin/restaurants/{$restaurant->id}", [
            'name' => 'Update Domain',
            'slug' => 'update-domain',
            'status' => 'active',
            'currency' => 'USD',
            'custom_domain' => 'new.example.com',
            'menu_categories' => ['Main Courses'],
        ]);

        $response->assertOk()
            ->assertJsonPath('restaurant.custom_domain', 'new.example.com')
            ->assertJsonPath('restaurant.custom_domain_status', 'pending_dns');

        $restaurant->refresh();

        $this->assertSame('new.example.com', $restaurant->custom_domain);
        Queue::assertPushed(ProvisionRestaurantDomainJob::class, 1);
    }

    public function test_provisioning_job_success_marks_domain_active(): void
    {
        $restaurant = Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory()->admin()->create()->id,
            'name' => 'Provision Success',
            'slug' => 'provision-success',
            'status' => 'active',
            'currency' => 'USD',
            'custom_domain' => 'success.example.com',
            'custom_domain_status' => 'pending_dns',
            'profile' => ['menu_categories' => ['Main Courses']],
        ]);

        app()->bind(DomainProvisioner::class, fn () => new class extends DomainProvisioner
        {
            public function provision(string $domain): string
            {
                return "Provisioned {$domain}";
            }
        });

        (new ProvisionRestaurantDomainJob($restaurant->id))->handle(
            app(DomainProvisioner::class),
            app(\App\Services\RestaurantCustomDomainService::class),
        );

        $restaurant->refresh();

        $this->assertSame('active', $restaurant->custom_domain_status);
        $this->assertNull($restaurant->custom_domain_error);
        $this->assertNotNull($restaurant->ssl_issued_at);
        $this->assertDatabaseHas('restaurant_domains', [
            'restaurant_id' => $restaurant->id,
            'domain' => 'success.example.com',
            'kind' => 'custom',
        ]);
    }

    public function test_provisioning_job_failure_stores_error_output(): void
    {
        $restaurant = Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory()->admin()->create()->id,
            'name' => 'Provision Failure',
            'slug' => 'provision-failure',
            'status' => 'active',
            'currency' => 'USD',
            'custom_domain' => 'failure.example.com',
            'custom_domain_status' => 'pending_dns',
            'profile' => ['menu_categories' => ['Main Courses']],
        ]);

        app()->bind(DomainProvisioner::class, fn () => new class extends DomainProvisioner
        {
            public function provision(string $domain): string
            {
                throw new \App\Exceptions\DomainProvisioningException('Provisioning failed.', "stderr for {$domain}");
            }
        });

        (new ProvisionRestaurantDomainJob($restaurant->id))->handle(
            app(DomainProvisioner::class),
            app(\App\Services\RestaurantCustomDomainService::class),
        );

        $restaurant->refresh();

        $this->assertSame('failed', $restaurant->custom_domain_status);
        $this->assertSame('stderr for failure.example.com', $restaurant->custom_domain_error);
        $this->assertNull($restaurant->ssl_issued_at);
    }

    private function createSaasOwner(): User
    {
        $user = User::factory()->create([
            'name' => 'SaaS Owner',
            'email' => 'owner@example.com',
            'role' => User::ROLE_SAAS_OWNER,
        ]);

        SuperAdmin::query()->create([
            'name' => $user->name,
            'email' => $user->email,
            'password' => $user->password,
        ]);

        return $user;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function restaurantPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'name' => 'Domain Ready Restaurant',
            'slug' => 'domain-ready-restaurant',
            'status' => 'active',
            'currency' => 'USD',
            'custom_domain' => 'restaurant.example.com',
            'menu_categories' => ['Main Courses'],
            'admin_user' => [
                'name' => 'Owner Admin',
                'email' => 'restaurant-owner@example.com',
                'password' => 'password123',
                'phone' => '+96170000000',
            ],
        ], $overrides);
    }

    private function mockRestaurantSetupDependencies(): void
    {
        $featureFlagService = Mockery::mock(FeatureFlagService::class);
        $featureFlagService->shouldReceive('enable')->andReturnNull();
        app()->instance(FeatureFlagService::class, $featureFlagService);

        $ingredientProvisioningService = Mockery::mock(GlobalIngredientProvisioningService::class);
        $ingredientProvisioningService->shouldReceive('provisionForRestaurant')->andReturn([
            'created_count' => 0,
            'linked_count' => 0,
            'skipped_count' => 0,
            'created_ids' => [],
            'linked_ids' => [],
            'skipped_global_ingredient_ids' => [],
        ]);
        app()->instance(GlobalIngredientProvisioningService::class, $ingredientProvisioningService);
    }
}
