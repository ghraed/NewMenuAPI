<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        [
            'prefix' => 'api',
            'middleware' => ['api', 'auth:sanctum'],
        ]
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Avoid route('login') dependency for unauthenticated requests.
        $middleware->redirectGuestsTo('/');
        $middleware->appendToGroup('api', \App\Http\Middleware\SetRequestLocale::class);
        $middleware->alias([
            'guest.table.access' => \App\Http\Middleware\EnsureGuestTableAccess::class,
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
            'saas_owner' => \App\Http\Middleware\EnsureSaasOwner::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => __('messages.auth.unauthenticated')], 401);
            }

            return null;
        });
    })->create();
