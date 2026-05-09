<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictChefApiSurface
{
    /**
     * Restricted roles may only use their dedicated workflow + auth me/logout.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if ($request->is('api/auth/me') || $request->is('api/auth/logout')) {
            return $next($request);
        }

        if ($user->hasRole(User::ROLE_CHEF) && $request->is('api/kitchen/*')) {
            return $next($request);
        }

        if (
            $user->hasRole(User::ROLE_STOCK_MANAGER)
            && (
                $request->is('api/inventory/*')
                || $request->is('api/global-ingredients')
                || $request->is('api/admin/finance/expense-categories')
                || $request->is('api/admin/finance/vendors')
            )
        ) {
            return $next($request);
        }

        if (
            $user->hasRole(User::ROLE_ACCOUNTANT)
            && (
                $request->is('api/orders/accounting')
                || $request->is('api/orders/*/account')
                || $request->is('api/admin/finance/*')
            )
        ) {
            return $next($request);
        }

        if (! $user->hasRole(User::ROLE_CHEF, User::ROLE_STOCK_MANAGER, User::ROLE_ACCOUNTANT)) {
            return $next($request);
        }

        abort(403, 'You do not have permission to perform this action.');
    }
}
