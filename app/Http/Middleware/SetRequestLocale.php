<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetRequestLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolveLocale($request);

        app()->setLocale($locale);
        $request->setLocale($locale);

        return $next($request);
    }

    private function resolveLocale(Request $request): string
    {
        $locale = $request->header('X-Locale')
            ?: $request->getPreferredLanguage(['en', 'ar'])
            ?: config('app.locale', 'en');

        $normalizedLocale = strtolower((string) $locale);

        return str_starts_with($normalizedLocale, 'ar') ? 'ar' : 'en';
    }
}
