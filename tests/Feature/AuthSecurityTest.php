<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_login_returns_token_and_authenticated_user_payload(): void
    {
        $user = $this->createUserWithRestaurant('valid-login@example.com', 'secret-pass');

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'secret-pass',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.restaurant.id', $user->restaurant->id)
            ->assertJsonPath('user.role', User::ROLE_ADMIN);

        $this->assertIsString($response->json('token'));
        $this->assertNotSame('', $response->json('token'));
    }

    public function test_invalid_login_is_rejected(): void
    {
        $user = $this->createUserWithRestaurant('invalid-login@example.com', 'secret-pass');

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-pass',
        ])->assertUnauthorized();
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = $this->createUserWithRestaurant('inactive-login@example.com', 'secret-pass');
        $user->update(['is_active' => false]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'secret-pass',
        ])->assertForbidden();
    }

    public function test_inactive_user_token_is_revoked_and_cannot_access_protected_routes(): void
    {
        $user = $this->createUserWithRestaurant('inactive-token@example.com', 'secret-pass');
        $token = $user->createToken('inactive-token')->plainTextToken;
        $user->update(['is_active' => false]);

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertForbidden();

        $this->assertNull(PersonalAccessToken::findToken($token));
    }

    public function test_expired_token_cannot_access_protected_routes(): void
    {
        config()->set('sanctum.expiration', 1);
        $user = $this->createUserWithRestaurant('expired-token@example.com', 'secret-pass');
        $token = $user->createToken('expired-token')->plainTextToken;

        $this->travel(2)->minutes();

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_logout_revokes_current_token(): void
    {
        $user = $this->createUserWithRestaurant('logout@example.com', 'secret-pass');
        $token = $user->createToken('logout-test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/auth/logout')
            ->assertOk();

        // Reset the app between requests so Sanctum re-resolves authentication
        // from the revoked bearer token instead of any in-memory guard state.
        $this->refreshApplication();

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_revoked_token_cannot_access_protected_routes(): void
    {
        $user = $this->createUserWithRestaurant('revoked@example.com', 'secret-pass');
        $token = $user->createToken('revoked-token')->plainTextToken;

        $accessToken = PersonalAccessToken::findToken($token);
        $this->assertNotNull($accessToken);
        $accessToken->delete();

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_malformed_token_is_rejected(): void
    {
        $this->withToken('not-a-valid-token')
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_unauthenticated_access_is_rejected(): void
    {
        $this->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_repeated_failed_login_attempts_are_rate_limited(): void
    {
        $user = $this->createUserWithRestaurant('rate-limit@example.com', 'secret-pass');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-pass',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-pass',
        ])->assertStatus(429);
    }

    private function createUserWithRestaurant(string $email, string $password): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        $user->restaurant()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Auth Test Restaurant '.Str::upper(Str::random(4)),
            'slug' => 'auth-test-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'description' => 'Auth security test restaurant',
            'address' => 'Beirut',
        ]);

        $user->load('restaurant');

        return $user;
    }
}
