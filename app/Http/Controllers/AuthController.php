<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->merge([
            'email' => $this->normalizeOptionalString($request->input('email')),
            'phone' => $this->normalizeOptionalString($request->input('phone')),
        ]);

        $validated = $request->validate([
            'email' => 'nullable|string|max:255|required_without:phone',
            'phone' => 'nullable|string|max:40|required_without:email',
            'password' => 'required|string',
        ]);

        $identifier = $validated['email'] ?? $validated['phone'];

        $user = User::query()
            ->where(function ($query) use ($identifier) {
                $query->where('email', $identifier)
                    ->orWhere('phone', $identifier);
            })
            ->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid email, phone number, or password',
            ], 401);
        }

        $user->loadMissing('restaurant', 'staffRestaurants');
        $restaurant = $user->currentRestaurant();

        if (! $restaurant) {
            return response()->json([
                'message' => 'No restaurant is linked to this account',
            ], 403);
        }

        $token = $user->createToken(($user->role ?? 'admin').'-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->formatAuthenticatedUser($user, $restaurant),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('restaurant', 'staffRestaurants');
        $restaurant = $user->currentRestaurant();

        return response()->json([
            'user' => $this->formatAuthenticatedUser($user, $restaurant),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        if ($token) {
            $token->delete();
        }

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    private function formatAuthenticatedUser(User $user, mixed $restaurant): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'restaurant' => $restaurant ? [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'slug' => $restaurant->slug,
            ] : null,
        ];
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }
}
