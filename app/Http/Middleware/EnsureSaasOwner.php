<?php

namespace App\Http\Middleware;

use App\Models\SuperAdmin;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSaasOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $isSaasOwner = $user instanceof User && $user->hasRole(User::ROLE_SAAS_OWNER);
        $existsInSaasOwners = $user instanceof User
            ? SuperAdmin::query()->where('email', $user->email)->exists()
            : false;

        if (! $isSaasOwner || ! $existsInSaasOwners) {
            abort(403, 'You do not have permission to access the Super Admin dashboard.');
        }

        return $next($request);
    }
}
