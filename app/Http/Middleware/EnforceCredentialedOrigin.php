<?php

namespace App\Http\Middleware;

use App\Support\AuthCredentialCookie;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceCredentialedOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $fetchSite = strtolower(trim((string) $request->header('Sec-Fetch-Site', '')));
        $origin = rtrim(trim((string) $request->header('Origin', '')), '/');
        if ($fetchSite === 'cross-site') {
            return response()->json(['message' => 'Credentialed cross-site request rejected.'], 419);
        }

        if (! $this->hasCredentialCookie($request)) {
            return $next($request);
        }

        if ($fetchSite !== '' && $origin === '') {
            return response()->json(['message' => 'Credentialed cross-site request rejected.'], 419);
        }

        $requestOrigin = rtrim($request->getSchemeAndHttpHost(), '/');
        $sameOrigin = $origin !== '' && hash_equals(strtolower($requestOrigin), strtolower($origin));
        if ($origin !== '' && ! $sameOrigin && ! in_array($origin, config('cors.allowed_origins', []), true)) {
            return response()->json(['message' => 'Credentialed origin is not allowed.'], 419);
        }

        return $next($request);
    }

    private function hasCredentialCookie(Request $request): bool
    {
        if ($request->hasCookie(AuthCredentialCookie::RESTAURANT) || $request->hasCookie(AuthCredentialCookie::OWNER)) {
            return true;
        }

        return collect(array_keys($request->cookies->all()))
            ->contains(fn (string $name): bool => str_starts_with($name, 'guest_table_access_'));
    }
}
