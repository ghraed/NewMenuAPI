<?php

namespace App\Http\Middleware;

use App\Models\TableSession;
use App\Services\TableSessionAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGuestTableAccess
{
    public function __construct(
        private readonly TableSessionAccessService $tableSessionAccessService
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $tableSession = $request->route('tableSession');

        if (! $tableSession instanceof TableSession) {
            abort(404);
        }

        $access = $this->tableSessionAccessService->authorizeRequestForSession($request, $tableSession);
        $request->attributes->set('guest_table_access', $access);

        return $next($request);
    }
}
