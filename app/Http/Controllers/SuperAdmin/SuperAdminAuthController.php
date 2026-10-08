<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Support\AuthCredentialCookie;
use App\Support\AuthTokenResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class SuperAdminAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email:rfc|max:255',
            'password' => 'required|string',
        ]);

        $email = strtolower(trim((string) $validated['email']));

        $saasOwner = SuperAdmin::query()
            ->where('email', $email)
            ->first();

        if (
            ! $saasOwner
            || ! Hash::check($validated['password'], $saasOwner->password)
        ) {
            return response()->json([
                'message' => 'Invalid Super Admin credentials.',
            ], 401)->withCookie(AuthCredentialCookie::forget($request, AuthCredentialCookie::OWNER));
        }

        $user = User::query()->updateOrCreate(
            ['email' => $saasOwner->email],
            [
                'name' => $saasOwner->name,
                'role' => User::ROLE_SAAS_OWNER,
                // Keep auth hash in sync with saas_owners.
                'password' => $saasOwner->password,
            ]
        );

        $token = $user->createToken('saas-owner-token')->plainTextToken;

        return response()->json(array_filter([
            'token' => AuthTokenResponse::requestedByNonBrowserClient($request) ? $token : null,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ], static fn ($value): bool => $value !== null))->withCookie(AuthCredentialCookie::make($request, AuthCredentialCookie::OWNER, $token));
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        $response = response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);
        if ($request->header('X-Rozer-Auth-Mode') === 'cookie-v1' && $request->bearerToken()) {
            $response->withCookie(AuthCredentialCookie::make($request, AuthCredentialCookie::OWNER, $request->bearerToken()));
        }
        return $response;
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token) {
            $token->delete();
        }

        return response()->json([
            'message' => 'Super Admin logged out successfully.',
        ])->withCookie(AuthCredentialCookie::forget($request, AuthCredentialCookie::OWNER));
    }
}
