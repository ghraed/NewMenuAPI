<?php

namespace App\Http\Middleware;

use App\Support\AuthCredentialCookie;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateFromHttpOnlyCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->bearerToken() || in_array($request->bearerToken(), ['null', 'undefined'], true)) {
            $cookieName = $request->is('api/owner/*') || $request->is('api/super-admin/*')
                ? AuthCredentialCookie::OWNER
                : AuthCredentialCookie::RESTAURANT;
            $token = trim((string) $request->cookie($cookieName, ''));

            if ($token !== '') {
                $request->headers->set('Authorization', 'Bearer '.$token);
            }
        }

        return $next($request);
    }
}
