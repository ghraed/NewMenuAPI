<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_staff_member_with_email(): void
    {
        $admin = User::factory()->admin()->create();
        $restaurant = $this->createRestaurant($admin);
        $assignedTableIds = $restaurant->tables()->whereIn('name', ['T01', 'T02'])->pluck('id')->all();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/restaurant/staff', [
            'name' => 'Maya Hassan',
            'email' => 'maya@example.com',
            'table_ids' => $assignedTableIds,
        ]);

        $response->assertCreated()
            ->assertJsonPath('staff.name', 'Maya Hassan')
            ->assertJsonPath('staff.email', 'maya@example.com')
            ->assertJsonPath('staff.phone', null)
            ->assertJsonPath('staff.role', User::ROLE_STAFF)
            ->assertJsonCount(2, 'staff.assigned_tables')
            ->assertJsonPath('staff.assigned_tables.0.name', 'T01')
            ->assertJsonStructure([
                'message',
                'temporary_password',
                'staff' => ['id', 'name', 'email', 'phone', 'role', 'created_at', 'assigned_tables'],
            ]);

        $staffId = $response->json('staff.id');

        $this->assertDatabaseHas('users', [
            'id' => $staffId,
            'name' => 'Maya Hassan',
            'email' => 'maya@example.com',
            'role' => User::ROLE_STAFF,
        ]);
        $this->assertDatabaseHas('restaurant_user', [
            'restaurant_id' => $restaurant->id,
            'user_id' => $staffId,
        ]);
        $this->assertDatabaseHas('restaurant_table_user', [
            'restaurant_table_id' => $assignedTableIds[0],
            'user_id' => $staffId,
        ]);
    }

    public function test_admin_can_create_a_staff_member_with_phone_and_login_with_it(): void
    {
        $admin = User::factory()->admin()->create();
        $restaurant = $this->createRestaurant($admin);

        Sanctum::actingAs($admin);

        $createResponse = $this->postJson('/api/restaurant/staff', [
            'name' => 'Khaled Nader',
            'phone' => '+96170000000',
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('staff.name', 'Khaled Nader')
            ->assertJsonPath('staff.email', null)
            ->assertJsonPath('staff.phone', '+96170000000')
            ->assertJsonPath('staff.role', User::ROLE_STAFF);

        $temporaryPassword = $createResponse->json('temporary_password');

        $this->assertDatabaseHas('users', [
            'name' => 'Khaled Nader',
            'phone' => '+96170000000',
            'role' => User::ROLE_STAFF,
        ]);

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => '+96170000000',
            'password' => $temporaryPassword,
        ]);

        $loginResponse->assertOk()
            ->assertJsonPath('user.name', 'Khaled Nader')
            ->assertJsonPath('user.phone', '+96170000000')
            ->assertJsonPath('user.role', User::ROLE_STAFF)
            ->assertJsonPath('user.restaurant.id', $restaurant->id);
    }

    public function test_non_admin_users_cannot_create_staff_members(): void
    {
        $admin = User::factory()->admin()->create();
        $restaurant = $this->createRestaurant($admin);
        $staff = User::factory()->staff()->create();
        $restaurant->staffUsers()->attach($staff->id);

        Sanctum::actingAs($staff);

        $response = $this->postJson('/api/restaurant/staff', [
            'name' => 'Blocked User',
            'email' => 'blocked@example.com',
        ]);

        $response->assertForbidden();
    }

    public function test_admin_can_list_staff_members_and_update_their_table_assignments(): void
    {
        $admin = User::factory()->admin()->create();
        $restaurant = $this->createRestaurant($admin);
        $staff = User::factory()->staff()->create([
            'name' => 'Assigned Staff',
        ]);
        $restaurant->staffUsers()->attach($staff->id);

        $initialTableIds = $restaurant->tables()->whereIn('name', ['T03', 'T04'])->pluck('id')->all();
        $staff->assignedTables()->sync($initialTableIds);

        Sanctum::actingAs($admin);

        $listResponse = $this->getJson('/api/restaurant/staff');

        $listResponse->assertOk()
            ->assertJsonCount(1, 'staff')
            ->assertJsonPath('staff.0.name', 'Assigned Staff')
            ->assertJsonCount(2, 'staff.0.assigned_tables');

        $nextTableIds = $restaurant->tables()->whereIn('name', ['T05', 'T06', 'T07'])->pluck('id')->all();

        $updateResponse = $this->patchJson("/api/restaurant/staff/{$staff->id}/tables", [
            'table_ids' => $nextTableIds,
        ]);

        $updateResponse->assertOk()
            ->assertJsonPath('staff.id', $staff->id)
            ->assertJsonCount(3, 'staff.assigned_tables')
            ->assertJsonPath('staff.assigned_tables.0.name', 'T05');

        foreach ($nextTableIds as $tableId) {
            $this->assertDatabaseHas('restaurant_table_user', [
                'restaurant_table_id' => $tableId,
                'user_id' => $staff->id,
            ]);
        }
    }

    private function createRestaurant(User $owner): Restaurant
    {
        return Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'name' => 'Staff Management Restaurant '.Str::upper(Str::random(3)),
            'slug' => 'staff-management-'.Str::lower(Str::random(8)),
            'description' => 'Restaurant for staff management tests',
            'address' => 'Beirut',
        ]);
    }
}
