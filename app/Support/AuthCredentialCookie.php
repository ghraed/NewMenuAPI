<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

final class AuthCredentialCookie
{
    public const RESTAURANT = 'restaurant_auth';

    public const OWNER = 'owner_auth';

    public static function guest(int $tableSessionId): string
    {
        return 'guest_table_access_'.$tableSessionId;
    }

    public static function make(Request $request, string $name, string $token, ?int $minutes = null): Cookie
    {
        return cookie(
            $name,
            $token,
            $minutes ?? (int) config('sanctum.expiration', 480),
            '/api',
            null,
            self::mustBeSecure($request),
            true,
            false,
            Cookie::SAMESITE_STRICT
        );
    }

    public static function forget(Request $request, string $name): Cookie
    {
        return cookie()->forget($name, '/api', null);
    }

    private static function mustBeSecure(Request $request): bool
    {
        return $request->isSecure() || ! app()->environment(['local', 'testing']);
    }
}
