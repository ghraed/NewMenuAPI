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
            'middleware' => ['api', 'auth:sanctum', 'active.user', 'cookie.identity'],
        ]
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust only the protocol from explicitly configured ingress addresses.
        $middleware->trustProxies(headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO);
        // Avoid route('login') dependency for unauthenticated requests.
        $middleware->redirectGuestsTo('/');
        $middleware->prependToGroup('api', \App\Http\Middleware\AuthenticateFromHttpOnlyCookie::class);
        $middleware->prependToGroup('api', \App\Http\Middleware\EnforceCredentialedOrigin::class);
        $middleware->appendToGroup('api', \App\Http\Middleware\SetRequestLocale::class);
        $middleware->alias([
            'cookie.identity' => \App\Http\Middleware\EnsureCookieIdentity::class,
            'idempotent.staff' => \App\Http\Middleware\IdempotentStaffMutation::class,
            'guest.table.access' => \App\Http\Middleware\EnsureGuestTableAccess::class,
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
            'saas_owner' => \App\Http\Middleware\EnsureSaasOwner::class,
            'feature' => \App\Http\Middleware\EnsureRestaurantFeatureEnabled::class,
            'restrict_chef_surface' => \App\Http\Middleware\RestrictChefApiSurface::class,
            'active.user' => \App\Http\Middleware\EnsureActiveUser::class,
            'new.commerce' => \App\Http\Middleware\EnsureRestaurantAcceptsNewCommerce::class,
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
