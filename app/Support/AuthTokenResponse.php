<?php

namespace App\Support;

use Illuminate\Http\Request;

final class AuthTokenResponse
{
    public static function requestedByNonBrowserClient(Request $request): bool
    {
        if (trim((string) $request->header('Origin', '')) !== ''
            || trim((string) $request->header('Sec-Fetch-Site', '')) !== ''
            || $request->header('X-Rozer-Auth-Mode') === 'cookie-v1') {
            return false;
        }
        if ($request->header('X-Rozer-Auth-Mode') === 'bearer-v1') {
            return true;
        }
        // Preserve pre-existing non-browser clients without a negotiation header.
        // Cookie-authenticated requests have no need to expose their credential.
        foreach (array_keys($request->cookies->all()) as $name) {
            if (in_array($name, [AuthCredentialCookie::RESTAURANT, AuthCredentialCookie::OWNER], true)
                || str_starts_with($name, 'guest_table_access_')) {
                return false;
            }
        }

        return true;
    }
}
