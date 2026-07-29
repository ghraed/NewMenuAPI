<?php

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\FeatureFlagAuditLog;
use App\Models\Restaurant;
use App\Models\RestaurantFeature;
use App\Models\RoomPlan;
use App\Models\SuperAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuperAdminFeatureFlagApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_list_feature_catalog_and_restaurant_feature_sources(): void
    {
        $saasOwner = $this->createSaasOwnerUser();
        $restaurantOwner = User::factory()->admin()->create();
        $restaurant = $this->createRestaurant($restaurantOwner, 'feature-catalog');

        $defaultOn = $this->createFeature('default_on_feature', true, 'Catalog');
        $defaultOff = $this->createFeature('default_off_feature', false, 'Catalog');

        RestaurantFeature::query()->create([
            'restaurant_id' => $restaurant->id,
            'feature_id' => $defaultOff->id,
            'enabled' => true,
        ]);

        Sanctum::actingAs($saasOwner);

        $catalogResponse = $this->getJson('/api/super-admin/features');
        $catalogResponse->assertOk()
            ->assertJsonFragment([
                'key' => 'default_on_feature',
                'is_active_by_default' => true,
            ])
            ->assertJsonFragment([
                'key' => 'default_off_feature',
                'is_active_by_default' => false,
            ]);

        $restaurantResponse = $this->getJson("/api/super-admin/restaurants/{$restaurant->id}/features");
        $restaurantResponse->assertOk()
            ->assertJsonPath('restaurant.id', $restaurant->id)
            ->assertJsonFragment([
                'key' => 'default_on_feature',
                'enabled' => true,
                'source' => 'default',
            ])
            ->assertJsonFragment([
                'key' => 'default_off_feature',
                'enabled' => true,
                'source' => 'override',
            ]);
    }

    public function test_super_admin_feature_updates_create_audit_logs_and_next_admin_request_is_rechecked(): void
    {
        $saasOwner = $this->createSaasOwnerUser();
        $restaurantOwner = User::factory()->admin()->create();
        $restaurant = $this->createRestaurant($restaurantOwner, 'feature-enforcement');
        $roomPlanFeature = $this->createFeature('room_plan_editor', false, 'Operations');

        RestaurantFeature::query()->create([
            'restaurant_id' => $restaurant->id,
            'feature_id' => $roomPlanFeature->id,
            'enabled' => true,
        ]);

        RoomPlan::query()->create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Main Floor',
            'width' => 1200,
            'height' => 900,
        ]);

        Sanctum::actingAs($restaurantOwner);
        $this->getJson('/api/room-plans')
            ->assertOk()
            ->assertJsonCount(1, 'room_plans');

        Sanctum::actingAs($saasOwner);
        $this->patchJson("/api/super-admin/restaurants/{$restaurant->id}/features/{$roomPlanFeature->id}", [
            'enabled' => false,
        ])->assertOk()
            ->assertJsonPath('feature.key', 'room_plan_editor')
            ->assertJsonPath('feature.enabled', false);

        $this->assertDatabaseHas('restaurant_features', [
            'restaurant_id' => $restaurant->id,
            'feature_id' => $roomPlanFeature->id,
            'enabled' => false,
        ]);

        $auditLog = FeatureFlagAuditLog::query()->where([
            'restaurant_id' => $restaurant->id,
            'feature_id' => $roomPlanFeature->id,
        ])->latest('created_at')->first();

        $this->assertNotNull($auditLog);
        $this->assertSame($saasOwner->id, $auditLog->changed_by_user_id);
        $this->assertTrue($auditLog->old_value);
        $this->assertFalse($auditLog->new_value);

        Sanctum::actingAs($restaurantOwner);
        $this->getJson('/api/room-plans')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Feature [room_plan_editor] is disabled for this restaurant.');

        Sanctum::actingAs($saasOwner);
        $this->patchJson("/api/super-admin/restaurants/{$restaurant->id}/features/bulk", [
            'features' => [
                ['key' => 'room_plan_editor', 'enabled' => true],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('restaurant_features', [
            'restaurant_id' => $restaurant->id,
            'feature_id' => $roomPlanFeature->id,
            'enabled' => true,
        ]);

        $this->assertSame(
            2,
            FeatureFlagAuditLog::query()
                ->where('restaurant_id', $restaurant->id)
                ->where('feature_id', $roomPlanFeature->id)
                ->count()
        );

        Sanctum::actingAs($restaurantOwner);
        $this->getJson('/api/room-plans')
            ->assertOk()
            ->assertJsonCount(1, 'room_plans');
    }

    private function createSaasOwnerUser(): User
    {
        $seededOwner = SuperAdmin::query()->firstOrFail();

        return User::factory()->saasOwner()->create([
            'name' => $seededOwner->name,
            'email' => $seededOwner->email,
        ]);
    }

    private function createRestaurant(User $owner, string $slugPrefix): Restaurant
    {
        return Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'name' => 'Feature Flags '.Str::upper(Str::random(4)),
            'slug' => $slugPrefix.'-'.Str::lower(Str::random(6)),
            'description' => 'Feature flag test restaurant',
            'address' => 'Beirut',
        ]);
    }

    private function createFeature(string $key, bool $isActiveByDefault, string $category): Feature
    {
        return Feature::query()->updateOrCreate(
            ['key' => $key],
            [
                'name' => Str::title(str_replace('_', ' ', $key)),
                'description' => 'Feature used by automated tests.',
                'category' => $category,
                'is_active_by_default' => $isActiveByDefault,
            ]
        );
    }
}
