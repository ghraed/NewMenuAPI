<?php

namespace Tests\Feature;

use App\Support\AuthCredentialCookie;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedHttpsProxyTest extends TestCase
{
    private array $previous = [];

    protected function setUp(): void
    {
        $this->previous = ['env' => $_ENV['TRUSTED_PROXIES'] ?? null, 'server' => $_SERVER['TRUSTED_PROXIES'] ?? null, 'process' => getenv('TRUSTED_PROXIES')];
        $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = '172.18.0.1';
        putenv('TRUSTED_PROXIES=172.18.0.1');
        parent::setUp();
        Route::middleware('api')->post('/api/__qa/proxy-origin', fn (Request $request) => response()->json(['host' => $request->getHost(), 'scheme' => $request->getScheme()]));
        $this->withCredentials()->withUnencryptedCookie(AuthCredentialCookie::RESTAURANT, 'QA_RUN_'.getenv('QA_RUN_ID').'_proxy_cookie');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['env', 'server'] as $key) {
            if ($this->previous[$key] === null) {
                if ($key === 'env') {
                    unset($_ENV['TRUSTED_PROXIES']);
                } else {
                    unset($_SERVER['TRUSTED_PROXIES']);
                }
            } elseif ($key === 'env') {
                $_ENV['TRUSTED_PROXIES'] = $this->previous[$key];
            } else {
                $_SERVER['TRUSTED_PROXIES'] = $this->previous[$key];
            }
        }
        putenv($this->previous['process'] === false ? 'TRUSTED_PROXIES' : 'TRUSTED_PROXIES='.$this->previous['process']);
    }

    private function requestThrough(string $peer, array $extra = [])
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $peer, 'SERVER_PORT' => 80, 'HTTPS' => 'off'])->postJson('http://alpha.release.localhost/api/__qa/proxy-origin', [], array_merge(['Host' => 'alpha.release.localhost', 'Origin' => 'https://alpha.release.localhost', 'X-Forwarded-Proto' => 'https', 'Sec-Fetch-Site' => 'same-origin'], $extra));
    }

    public function test_known_ingress_preserves_real_https_origin_for_custom_tenant_cookie_writes(): void
    {
        $this->requestThrough('172.18.0.1')->assertOk()->assertJsonPath('host', 'alpha.release.localhost')->assertJsonPath('scheme', 'https');
    }

    public function test_unknown_peer_cannot_forge_forwarded_protocol(): void
    {
        $this->requestThrough('203.0.113.9')->assertStatus(419);
    }

    public function test_forwarded_host_is_ignored_even_from_configured_ingress(): void
    {
        $this->requestThrough('172.18.0.1', ['X-Forwarded-Host' => 'attacker.example'])->assertOk()->assertJsonPath('host', 'alpha.release.localhost');
    }
}
