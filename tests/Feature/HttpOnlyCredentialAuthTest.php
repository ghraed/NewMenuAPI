<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceCredentialedOrigin;
use App\Models\User;
use App\Support\AuthCredentialCookie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\BuildsRestaurantOrderFlow;
use Tests\TestCase;

class HttpOnlyCredentialAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('X-Rozer-Auth-Mode', 'cookie-v1');
    }

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
        $login->assertJsonMissingPath('token');
        $this->assertSame('/api', $cookie->getPath());

        $this->withCredentials()
            ->withUnencryptedCookie(AuthCredentialCookie::RESTAURANT, (string) $cookie->getValue())
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);

        $logout = $this->withCredentials()
            ->withUnencryptedCookie(AuthCredentialCookie::RESTAURANT, (string) $cookie->getValue())
            ->postJson('/api/auth/logout')
            ->assertOk();
        $expiredCookie = collect($logout->headers->getCookies())
            ->first(fn ($candidate) => $candidate->getName() === AuthCredentialCookie::RESTAURANT);
        $this->assertNotNull($expiredCookie);
        $this->assertTrue($expiredCookie->isCleared());

        $this->refreshApplication();
        $this->withCredentials()
            ->withUnencryptedCookie(AuthCredentialCookie::RESTAURANT, (string) $cookie->getValue())
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
        $verify->assertJsonMissingPath('guest_access.token');

        $headers = ['X-Guest-Device-Id' => 'invoice-test-device'];
        $this->withCredentials()
            ->withUnencryptedCookie($cookieName, (string) $cookie->getValue())
            ->getJson('/api/menu/table/1?include_dishes=none', $headers)
            ->assertOk()
            ->assertJsonPath('guest_access.verified', true)
            ->assertJsonMissingPath('guest_access.token');

        $this->withCredentials()
            ->withUnencryptedCookie($cookieName, (string) $cookie->getValue())
            ->postJson("/api/table-session/{$session->id}/order", [
                'notes' => 'QA_RUN_SEC cookie replay',
                'items' => [['dish_id' => $dish->id, 'quantity' => 1]],
            ], array_merge($headers, ['X-Idempotency-Key' => 'QA_RUN_SEC-cookie-order']))
            ->assertCreated();
    }

    public function test_cookie_mutation_rejects_cross_site_origin(): void
    {
        $user = User::factory()->admin()->create([
            'name' => 'QA_RUN_SEC csrf admin',
            'email' => 'qa_run_sec_csrf_'.Str::lower(Str::random(8)).'@example.test',
            'password' => Hash::make('QA_RUN_SEC-password'),
        ]);
        $this->createRestaurant($user, attributes: [
            'name' => 'QA_RUN_SEC csrf restaurant',
            'slug' => 'qa-run-sec-csrf-'.Str::lower(Str::random(8)),
        ]);
        $login = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'QA_RUN_SEC-password',
        ])->assertOk();
        $cookie = collect($login->headers->getCookies())
            ->first(fn ($candidate) => $candidate->getName() === AuthCredentialCookie::RESTAURANT);

        $this->withHeaders(['Origin' => 'https://attacker.example', 'Sec-Fetch-Site' => 'cross-site'])
            ->withUnencryptedCookie(AuthCredentialCookie::RESTAURANT, (string) $cookie->getValue())
            ->postJson('/api/auth/logout')
            ->assertStatus(419);
    }

    public function test_finalized_guest_access_is_revoked_and_the_stale_cookie_is_cleared(): void
    {
        $owner = User::factory()->admin()->create([
            'name' => 'QA_RUN_SEC finalize owner',
            'email' => 'qa_run_sec_finalize_'.Str::lower(Str::random(8)).'@example.test',
        ]);
        $restaurant = $this->createRestaurant($owner, attributes: [
            'name' => 'QA_RUN_SEC finalize restaurant',
            'slug' => 'qa-run-sec-finalize-'.Str::lower(Str::random(8)),
        ]);
        $session = $this->openGuestTable($restaurant, 1);
        $verify = $this->postJson('/api/menu/table/1/verify-pin', [
            'pin' => $this->activeSessionPin(),
        ], $this->guestHeaders())->assertOk();
        $cookieName = AuthCredentialCookie::guest($session->id);
        $cookie = collect($verify->headers->getCookies())->first(fn ($candidate) => $candidate->getName() === $cookieName);

        Sanctum::actingAs($owner);
        $this->postJson("/api/table-sessions/{$session->id}/finalize")->assertOk();

        $response = $this->withUnencryptedCookie($cookieName, (string) $cookie->getValue())
            ->withHeader('X-Guest-Device-Id', 'invoice-test-device')
            ->postJson("/api/table-session/{$session->id}/heartbeat")
            ->assertForbidden();
        $cleared = collect($response->headers->getCookies())->first(fn ($candidate) => $candidate->getName() === $cookieName);
        $this->assertNotNull($cleared);
        $this->assertTrue($cleared->isCleared());
    }

    public function test_custom_restaurant_domain_accepts_a_true_same_origin_cookie_post(): void
    {
        $request = Request::create(
            'https://orders.qa-run-sec.example/api/orders/1/confirm',
            'POST',
            cookies: [AuthCredentialCookie::RESTAURANT => 'QA_RUN_SEC-cookie'],
            server: [
                'HTTP_ORIGIN' => 'https://orders.qa-run-sec.example',
                'HTTP_SEC_FETCH_SITE' => 'same-origin',
            ]
        );
        $response = app(EnforceCredentialedOrigin::class)->handle(
            $request,
            fn () => response()->noContent()
        );

        $this->assertSame(204, $response->getStatusCode());
    }
}
