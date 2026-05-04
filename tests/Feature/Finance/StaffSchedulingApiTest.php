<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\Feature;
use App\Models\Restaurant;
use App\Models\RestaurantFeature;
use App\Models\StaffShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class StaffSchedulingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_list_and_update_staff_shifts_with_tenant_scoping(): void
    {
        [$adminA, $restaurantA] = $this->createAdminWithRestaurant('schedule-alpha');
        [, $restaurantB] = $this->createAdminWithRestaurant('schedule-beta');

        $staffA = User::factory()->staff()->create(['name' => 'Staff A']);
        $chefA = User::factory()->chef()->create(['name' => 'Chef A']);
        $staffB = User::factory()->staff()->create(['name' => 'Staff B']);

        $restaurantA->staffUsers()->attach([$staffA->id, $chefA->id]);
        $restaurantB->staffUsers()->attach([$staffB->id]);

        $otherTenantShift = StaffShift::query()->create([
            'restaurant_id' => $restaurantB->id,
            'user_id' => $staffB->id,
            'shift_date' => '2026-05-07',
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
            'status' => 'scheduled',
        ]);

        Sanctum::actingAs($adminA);

        $createResponse = $this->postJson('/api/admin/staff/schedules', [
            'user_id' => $staffA->id,
            'shift_date' => '2026-05-05',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'position' => ' Floor ',
            'notes' => ' Lunch shift ',
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('shift.user_id', $staffA->id)
            ->assertJsonPath('shift.position', 'Floor')
            ->assertJsonPath('shift.notes', 'Lunch shift')
            ->assertJsonPath('shift.status', 'scheduled')
            ->assertJsonPath('shift.employee.role', User::ROLE_STAFF);

        $shiftId = (int) $createResponse->json('shift.id');

        $this->assertDatabaseHas('staff_shifts', [
            'id' => $shiftId,
            'restaurant_id' => $restaurantA->id,
            'user_id' => $staffA->id,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
        ]);

        $listResponse = $this->getJson('/api/admin/staff/schedules?date_from=2026-05-01&date_to=2026-05-31');

        $listResponse->assertOk()
            ->assertJsonCount(1, 'shifts')
            ->assertJsonPath('shifts.0.id', $shiftId);

        $invalidUpdate = $this->patchJson("/api/admin/staff/schedules/{$shiftId}", [
            'end_time' => '08:30',
        ]);

        $invalidUpdate->assertStatus(422)
            ->assertJsonValidationErrors(['end_time']);

        $validUpdate = $this->patchJson("/api/admin/staff/schedules/{$shiftId}", [
            'user_id' => $chefA->id,
            'start_time' => '10:00',
            'end_time' => '18:00',
            'status' => 'completed',
            'notes' => 'Closed shift',
        ]);

        $validUpdate->assertOk()
            ->assertJsonPath('shift.user_id', $chefA->id)
            ->assertJsonPath('shift.status', 'completed')
            ->assertJsonPath('shift.notes', 'Closed shift')
            ->assertJsonPath('shift.employee.role', User::ROLE_CHEF);

        $crossTenantUpdate = $this->patchJson("/api/admin/staff/schedules/{$otherTenantShift->id}", [
            'status' => 'cancelled',
        ]);

        $crossTenantUpdate->assertNotFound();

        $nonEmployeeCreate = $this->postJson('/api/admin/staff/schedules', [
            'user_id' => $adminA->id,
            'shift_date' => '2026-05-09',
            'start_time' => '09:00',
            'end_time' => '13:00',
        ]);

        $nonEmployeeCreate->assertStatus(422)
            ->assertJsonValidationErrors(['user_id']);
    }

    public function test_non_admin_user_cannot_access_staff_scheduling_endpoints(): void
    {
        [$admin, $restaurant] = $this->createAdminWithRestaurant('schedule-forbidden');

        $staff = User::factory()->staff()->create();
        $restaurant->staffUsers()->attach($staff->id);

        Sanctum::actingAs($staff);

        $response = $this->getJson('/api/admin/staff/schedules');

        $response->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Restaurant}
     */
    private function createAdminWithRestaurant(string $suffix): array
    {
        $admin = User::factory()->admin()->create([
            'email' => "admin-{$suffix}@example.test",
        ]);

        $restaurant = Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'name' => 'Schedule Test '.Str::upper($suffix),
            'slug' => 'schedule-test-'.Str::lower($suffix).'-'.Str::lower(Str::random(5)),
            'description' => 'Staff schedule test restaurant',
            'address' => 'Beirut',
        ]);

        $this->enableFeatureForRestaurant($restaurant, 'staff_scheduling', 'Operations');

        return [$admin, $restaurant];
    }

    private function enableFeatureForRestaurant(Restaurant $restaurant, string $featureKey, string $category): void
    {
        $feature = Feature::query()->firstOrCreate(
            ['key' => $featureKey],
            [
                'name' => ucwords(str_replace('_', ' ', $featureKey)),
                'description' => 'Test feature flag',
                'category' => $category,
                'is_active_by_default' => false,
            ]
        );

        RestaurantFeature::query()->updateOrCreate(
            [
                'restaurant_id' => $restaurant->id,
                'feature_id' => $feature->id,
            ],
            ['enabled' => true]
        );
    }
}
