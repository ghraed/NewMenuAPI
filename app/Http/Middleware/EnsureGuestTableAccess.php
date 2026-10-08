<?php

namespace App\Http\Middleware;

use App\Models\TableSession;
use App\Services\TableSessionAccessService;
use App\Support\AuthCredentialCookie;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGuestTableAccess
{
    public function __construct(
        private readonly TableSessionAccessService $tableSessionAccessService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tableSession = $request->route('tableSession');

        if (! $tableSession instanceof TableSession) {
            abort(404);
        }

        try {
            $access = $this->tableSessionAccessService->authorizeRequestForSession($request, $tableSession);
        } catch (HttpResponseException $exception) {
            $response = $exception->getResponse();
            // A stale tab's explicit credential/scope must not clear a newer
            // valid cookie installed by another tab. Invalid cookie-only requests
            // still expire the credential normally.
            if (! $request->hasHeader('X-Guest-Access-Token') && ! $request->hasHeader('X-Guest-Cache-Key')) {
                $response->headers->setCookie(AuthCredentialCookie::forget($request, AuthCredentialCookie::guest($tableSession->id)));
            }

            return $response;
        }
        $request->attributes->set('guest_table_access', $access);

        return $next($request);
    }
}
