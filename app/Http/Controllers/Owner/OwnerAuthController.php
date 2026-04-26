<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class OwnerAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email:rfc|max:255',
            'password' => 'required|string',
        ]);

        $configuredOwnerEmail = strtolower(trim((string) config('saas.owner_email')));
        $email = strtolower(trim((string) $validated['email']));

        $user = User::query()
            ->where('email', $email)
            ->first();

        if (
            $email !== $configuredOwnerEmail
            || ! $user
            || ! $user->hasRole(User::ROLE_SAAS_OWNER)
            || ! Hash::check($validated['password'], $user->password)
        ) {
            return response()->json([
                'message' => 'Invalid owner credentials.',
            ], 401);
        }

        $token = $user->createToken('saas-owner-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token) {
            $token->delete();
        }

        return response()->json([
            'message' => 'Owner logged out successfully.',
        ]);
    }
}
