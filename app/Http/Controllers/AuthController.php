<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\FeatureFlagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(
        private readonly FeatureFlagService $featureFlagService,
    ) {
    }

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
                'message' => __('messages.auth.invalid_credentials'),
            ], 401);
        }

        if ($user->hasRole(User::ROLE_SAAS_OWNER)) {
            return response()->json([
                'message' => 'Use /super-admin/login for Super Admin access.',
            ], 403);
        }

        $user->loadMissing('restaurant', 'staffRestaurants');
        $restaurant = $user->currentRestaurant();

        if (! $restaurant) {
            return response()->json([
                'message' => __('messages.auth.missing_restaurant'),
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
            'message' => __('messages.auth.logged_out'),
        ]);
    }

    private function formatAuthenticatedUser(User $user, mixed $restaurant): array
    {
        $user->loadMissing(['assignedTables' => function ($query) use ($restaurant) {
            if ($restaurant) {
                $query->where('restaurant_id', $restaurant->id);
            }

            $query->orderBy('name');
        }]);

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
                'logo_url' => $restaurant->logo_url,
                'currency' => $restaurant->currency,
                'other_currency' => $restaurant->other_currency,
                'dollar_rate' => $restaurant->dollar_rate,
                'custom_domain' => $restaurant->domains()->where('kind', 'custom')->orderByDesc('is_primary')->value('domain'),
                'menu_categories' => array_values(array_filter(
                    (is_array($restaurant->profile) ? ($restaurant->profile['menu_categories'] ?? []) : []),
                    fn ($value): bool => is_string($value) && trim($value) !== ''
                )),
                'feature_flags' => $this->featureFlagService->flagsForRestaurant($restaurant),
                'profile' => $restaurant->profile,
            ] : null,
            'assigned_tables' => $user->assignedTables->map(fn ($table) => [
                'id' => $table->id,
                'name' => $table->name,
            ])->values(),
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
