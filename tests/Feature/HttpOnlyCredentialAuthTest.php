<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AuthCredentialCookie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\BuildsRestaurantOrderFlow;
use Tests\TestCase;

class HttpOnlyCredentialAuthTest extends TestCase
{
    use BuildsRestaurantOrderFlow;
    use RefreshDatabase;

    public function test_staff_login_cookie_survives_refresh_and_logout_revokes_it(): void
    {
        $user = User::factory()->admin()->create([
            'name' => 'QA_RUN_SEC admin',
            'email' => 'qa_run_sec_admin_'.Str::lower(Str::random(8)).'@example.test',
            'password' => Hash::make('QA_RUN_SEC-password'),
        ]);
        $this->createRestaurant($user, attributes: [
            'name' => 'QA_RUN_SEC auth restaurant',
            'slug' => 'qa-run-sec-auth-'.Str::lower(Str::random(8)),
        ]);

        $login = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'QA_RUN_SEC-password',
        ])->assertOk();
        $cookie = collect($login->headers->getCookies())
            ->first(fn ($candidate) => $candidate->getName() === AuthCredentialCookie::RESTAURANT);

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());
        $this->assertSame('/api', $cookie->getPath());

        $this->withCredentials()
            ->withUnencryptedCookie(AuthCredentialCookie::RESTAURANT, (string) $login->json('token'))
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);

        $logout = $this->withCredentials()
            ->withUnencryptedCookie(AuthCredentialCookie::RESTAURANT, (string) $login->json('token'))
            ->postJson('/api/auth/logout')
            ->assertOk();
        $expiredCookie = collect($logout->headers->getCookies())
            ->first(fn ($candidate) => $candidate->getName() === AuthCredentialCookie::RESTAURANT);
        $this->assertNotNull($expiredCookie);
        $this->assertTrue($expiredCookie->isCleared());

        $this->refreshApplication();
        $this->withCredentials()
            ->withUnencryptedCookie(AuthCredentialCookie::RESTAURANT, (string) $login->json('token'))
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_guest_cookie_authorizes_refresh_and_offline_order_replay_without_a_readable_token_header(): void
    {
        $owner = User::factory()->admin()->create([
            'name' => 'QA_RUN_SEC guest owner',
            'email' => 'qa_run_sec_guest_'.Str::lower(Str::random(8)).'@example.test',
        ]);
        $restaurant = $this->createRestaurant($owner, attributes: [
            'name' => 'QA_RUN_SEC guest restaurant',
            'slug' => 'qa-run-sec-guest-'.Str::lower(Str::random(8)),
        ]);
        $dish = $this->createDish($restaurant, 'QA_RUN_SEC guest dish', 8.00);
        $session = $this->openGuestTable($restaurant, 1);

        $verify = $this->postJson('/api/menu/table/1/verify-pin', [
            'pin' => $this->activeSessionPin(),
        ], $this->guestHeaders())->assertOk();
        $cookieName = AuthCredentialCookie::guest($session->id);
        $cookie = collect($verify->headers->getCookies())
            ->first(fn ($candidate) => $candidate->getName() === $cookieName);

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());

        $headers = ['X-Guest-Device-Id' => 'invoice-test-device'];
        $this->withCredentials()
            ->withUnencryptedCookie($cookieName, (string) $verify->json('guest_access.token'))
            ->getJson('/api/menu/table/1?include_dishes=none', $headers)
            ->assertOk()
            ->assertJsonPath('guest_access.verified', true)
            ->assertJsonMissingPath('guest_access.token');

        $this->withCredentials()
            ->withUnencryptedCookie($cookieName, (string) $verify->json('guest_access.token'))
            ->postJson("/api/table-session/{$session->id}/order", [
                'notes' => 'QA_RUN_SEC cookie replay',
                'items' => [['dish_id' => $dish->id, 'quantity' => 1]],
            ], array_merge($headers, ['X-Idempotency-Key' => 'QA_RUN_SEC-cookie-order']))
            ->assertCreated();
    }
}
