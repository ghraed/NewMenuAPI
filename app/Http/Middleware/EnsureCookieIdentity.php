<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCookieIdentity
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $request->header('X-Rozer-Auth-Mode') === 'cookie-v1') {
            $expectedUser = $request->header('X-Rozer-Expected-User');
            $expectedRestaurant = $request->header('X-Rozer-Expected-Restaurant');
            if (($expectedUser !== null && (string) $user->id !== $expectedUser)
                || ($expectedRestaurant !== null && (string) $user->currentRestaurant()?->id !== $expectedRestaurant)) {
                return response()->json(['message' => 'The authenticated browser session changed. Please sign in again.'], 409);
            }
        }

        return $next($request);
    }
}
