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
        $helpersPath = app_path('Support/helpers.php');
        if (is_file($helpersPath)) {
            require_once $helpersPath;
        }
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

        RateLimiter::for('ai-chat', function (Request $request): array {
            $ip = $request->ip() ?: 'unknown';
            $sessionId = $request->hasSession() ? ($request->session()->getId() ?: 'guest') : 'guest';

            return [
                Limit::perMinute(20)->by("ai-chat:{$ip}:{$sessionId}"),
                Limit::perHour(200)->by("ai-chat-hour:{$ip}"),
            ];
        });

        RateLimiter::for('owner-login', function (Request $request): array {
            $email = strtolower(trim((string) $request->input('email', 'unknown')));
            $ip = $request->ip() ?: 'unknown';

            return [
                Limit::perMinute(5)->by("owner-login:{$email}:{$ip}"),
                Limit::perHour(30)->by("owner-login-hour:{$ip}"),
            ];
        });
    }
}
