<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('chat', function (Request $request): array {
            $ip = $request->ip() ?: 'unknown';
            $sessionId = $request->hasSession() ? ($request->session()->getId() ?: 'guest') : 'guest';

            return [
                Limit::perMinute(20)->by("chat:{$ip}:{$sessionId}"),
                Limit::perHour(300)->by("chat-hour:{$ip}"),
            ];
        });

        RateLimiter::for('chat-orders', function (Request $request): array {
            $ip = $request->ip() ?: 'unknown';
            $sessionId = $request->hasSession() ? ($request->session()->getId() ?: 'guest') : 'guest';

            return [
                Limit::perMinute(6)->by("chat-orders:{$ip}:{$sessionId}"),
                Limit::perHour(40)->by("chat-orders-hour:{$ip}"),
            ];
        });
    }
}
