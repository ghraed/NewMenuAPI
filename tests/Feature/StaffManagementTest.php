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

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/restaurant/staff', [
            'name' => 'Maya Hassan',
            'email' => 'maya@example.com',
        ]);

        $response->assertCreated()
            ->assertJsonPath('staff.name', 'Maya Hassan')
            ->assertJsonPath('staff.email', 'maya@example.com')
            ->assertJsonPath('staff.phone', null)
            ->assertJsonPath('staff.role', User::ROLE_STAFF)
            ->assertJsonStructure([
                'message',
                'temporary_password',
                'staff' => ['id', 'name', 'email', 'phone', 'role', 'created_at'],
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
