<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = User::ROLE_ADMIN): User
    {
        $prefix = 'QA_RUN_20261006_'.bin2hex(random_bytes(4));
        $user = User::factory()->create([
            'name' => $prefix,
            'email' => $prefix.'@example.invalid',
            'role' => $role,
            'password' => 'QA_RUN_old_password',
        ]);
        Restaurant::factory()->for($user)->create(['name' => $prefix, 'slug' => strtolower($prefix)]);

        return $user;
    }

    public static function roles(): array
    {
        return array_map(fn (string $role): array => [$role], [
            User::ROLE_ADMIN, User::ROLE_STAFF, User::ROLE_CHEF,
            User::ROLE_STOCK_MANAGER, User::ROLE_ACCOUNTANT,
        ]);
    }

    #[DataProvider('roles')]
    public function test_user_can_save_and_read_their_own_profile(string $role): void
    {
        $user = $this->user($role);
        Sanctum::actingAs($user);

        $this->patchJson('/api/auth/me', ['name' => '  QA_RUN_20261006_Updated  ', 'phone' => '  +15550001001  '])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.name', 'QA_RUN_20261006_Updated')
            ->assertJsonPath('user.phone', '+15550001001')
            ->assertJsonPath('user.role', $role)
            ->assertJsonPath('user.restaurant.id', $user->restaurant->id)
            ->assertJsonMissingPath('user.password');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'QA_RUN_20261006_Updated', 'phone' => '+15550001001']);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.name', 'QA_RUN_20261006_Updated');
        $this->assertTrue(Hash::check('QA_RUN_old_password', $user->fresh()->password));
    }

    public function test_phone_can_be_kept_or_cleared(): void
    {
        $user = $this->user();
        $user->update(['phone' => '+15550001002']);
        Sanctum::actingAs($user);

        $this->patchJson('/api/auth/me', ['phone' => '+15550001002'])->assertOk()->assertJsonPath('user.phone', '+15550001002');
        $this->patchJson('/api/auth/me', ['phone' => ''])->assertOk()->assertJsonPath('user.phone', null);
        $this->assertNull($user->fresh()->phone);
    }

    public function test_duplicate_phone_rejects_the_entire_profile_update(): void
    {
        $user = $this->user();
        $other = $this->user();
        $other->update(['phone' => '+15550001003']);
        $oldName = $user->name;
        $oldPassword = $user->password;
        Sanctum::actingAs($user);

        $this->patchJson('/api/auth/me', [
            'name' => 'QA_RUN_20261006_ShouldNotSave', 'phone' => $other->phone,
            'password' => 'QA_RUN_new_password', 'password_confirmation' => 'QA_RUN_new_password',
        ])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertSame($oldName, $user->fresh()->name);
        $this->assertSame($oldPassword, $user->fresh()->password);
        $this->assertSame('+15550001003', $other->fresh()->phone);
    }

    public static function invalidProfiles(): array
    {
        return [
            'empty name' => [['name' => '   '], 'name'],
            'long name' => [['name' => str_repeat('QA_RUN_', 40)], 'name'],
            'long phone' => [['phone' => str_repeat('1', 41)], 'phone'],
            'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
            'long password' => [['password' => str_repeat('x', 256), 'password_confirmation' => str_repeat('x', 256)], 'password'],
            'mismatched password' => [['password' => 'QA_RUN_new_password', 'password_confirmation' => 'QA_RUN_different'], 'password'],
        ];
    }

    #[DataProvider('invalidProfiles')]
    public function test_invalid_profile_does_not_change_user(array $payload, string $field): void
    {
        $user = $this->user();
        $original = $user->fresh()->getAttributes();
        Sanctum::actingAs($user);

        $this->patchJson('/api/auth/me', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($original, $user->fresh()->getAttributes());
    }

    public function test_password_is_hashed_without_trimming_and_can_be_used_to_log_in(): void
    {
        $user = $this->user();
        $password = ' QA_RUN_new_password ';
        Sanctum::actingAs($user);

        $this->patchJson('/api/auth/me', ['password' => $password, 'password_confirmation' => $password])
            ->assertOk()->assertJsonMissingPath('user.password');
        $this->assertTrue(Hash::check($password, $user->fresh()->password));
        $this->assertFalse(Hash::check('QA_RUN_old_password', $user->fresh()->password));
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => $password])
            ->assertOk()->assertJsonPath('user.id', $user->id);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'QA_RUN_old_password'])->assertUnauthorized();
    }

    public function test_update_cannot_target_another_user_or_change_privileged_fields(): void
    {
        $user = $this->user();
        $other = $this->user();
        $otherAttributes = $other->fresh()->getAttributes();
        Sanctum::actingAs($user);

        $this->patchJson('/api/auth/me', [
            'name' => 'QA_RUN_20261006_SelfOnly', 'id' => $other->id,
            'email' => 'QA_RUN_changed@example.invalid', 'role' => User::ROLE_SAAS_OWNER,
            'is_active' => false, 'restaurant_id' => $other->restaurant->id,
        ])->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonPath('user.role', User::ROLE_ADMIN);
        $this->assertSame($user->email, $user->fresh()->email);
        $this->assertTrue($user->fresh()->is_active);
        $this->assertSame($otherAttributes, $other->fresh()->getAttributes());
    }

    public function test_unauthenticated_user_cannot_update_profile(): void
    {
        $this->patchJson('/api/auth/me', ['name' => 'QA_RUN_20261006_Denied'])->assertUnauthorized();
    }

    public function test_inactive_user_cannot_update_profile(): void
    {
        $user = $this->user();
        $oldName = $user->name;
        $user->update(['is_active' => false]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/auth/me', ['name' => 'QA_RUN_20261006_Denied'])->assertForbidden();
        $this->assertSame($oldName, $user->fresh()->name);
    }
}
