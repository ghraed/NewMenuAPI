<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictChefApiSurface
{
    /**
     * Chef accounts may only use auth me/logout and kitchen workflow endpoints.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasRole(User::ROLE_CHEF)) {
            return $next($request);
        }

        if (
            $request->is('api/auth/me')
            || $request->is('api/auth/logout')
            || $request->is('api/kitchen/*')
        ) {
            return $next($request);
        }

        abort(403, 'You do not have permission to perform this action.');
    }
}

