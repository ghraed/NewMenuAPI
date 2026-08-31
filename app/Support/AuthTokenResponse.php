<?php

namespace App\Support;

use Illuminate\Http\Request;

final class AuthTokenResponse
{
    public static function requestedByNonBrowserClient(Request $request): bool
    {
        return hash_equals('bearer-v1', trim((string) $request->header('X-Rozer-Auth-Mode', '')))
            && trim((string) $request->header('Origin', '')) === ''
            && trim((string) $request->header('Sec-Fetch-Site', '')) === '';
    }
}
