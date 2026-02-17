<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid email or password',
            ], 401);
        }

        $user->loadMissing('restaurant');

        if (!$user->restaurant) {
            return response()->json([
                'message' => 'No restaurant is linked to this account',
            ], 403);
        }

        $token = $user->createToken('admin-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'restaurant' => [
                    'id' => $user->restaurant->id,
                    'name' => $user->restaurant->name,
                    'slug' => $user->restaurant->slug,
                ],
            ],
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user();
        $user->loadMissing('restaurant');

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'restaurant' => $user->restaurant ? [
                    'id' => $user->restaurant->id,
                    'name' => $user->restaurant->name,
                    'slug' => $user->restaurant->slug,
                ] : null,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $token = $request->user()?->currentAccessToken();
        if ($token) {
            $token->delete();
        }

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }
}
