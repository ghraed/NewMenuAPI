<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSaasOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $configuredOwnerEmail = strtolower(trim((string) config('saas.owner_email')));

        $emailMatches = $user && strtolower(trim((string) $user->email)) === $configuredOwnerEmail;
        $isSaasOwner = $user && $user->hasRole(User::ROLE_SAAS_OWNER);

        if (! $emailMatches || ! $isSaasOwner) {
            abort(403, 'You do not have permission to access the owner dashboard.');
        }

        return $next($request);
    }
}
