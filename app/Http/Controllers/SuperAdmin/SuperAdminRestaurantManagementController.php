<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantDomain;
use App\Models\User;
use App\Services\FeatureFlagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SuperAdminRestaurantManagementController extends Controller
{
    public function __construct(
        private readonly FeatureFlagService $featureFlagService,
    ) {
    }

    public function options(): JsonResponse
    {
        $users = User::query()
            ->whereIn('role', [User::ROLE_ADMIN, User::ROLE_RESTAURANT_ADMIN])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone', 'role'])
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
                'has_restaurant' => $user->restaurant()->exists(),
            ])
            ->values();

        return response()->json([
            'users' => $users,
            'restaurant_statuses' => ['active', 'inactive'],
            'currencies' => ['USD', 'LBP', 'SYP', 'SAR', 'AED', 'EUR', 'QAR'],
            'menu_categories' => collect(config('menu_categories.definitions', []))
                ->map(fn (array $definition): array => [
                    'value' => (string) $definition['value'],
                    'arabic' => (string) ($definition['arabic'] ?? ''),
                ])
                ->values(),
        ]);
    }

    public function storeUser(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40', 'unique:users,phone'],
        ]);

        $user = User::query()->create([
            'name' => trim((string) $validated['name']),
            'email' => strtolower(trim((string) $validated['email'])),
            'password' => (string) $validated['password'],
            'phone' => $this->normalizeOptionalString($validated['phone'] ?? null),
            'role' => User::ROLE_ADMIN,
        ]);

        return response()->json([
            'message' => 'Admin user created successfully.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
                'has_restaurant' => false,
            ],
        ], 201);
    }

    public function storeRestaurant(Request $request): JsonResponse
    {
        $categoryValues = collect(config('menu_categories.values', []))
            ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->values()
            ->all();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:restaurants,slug'],
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->whereIn('role', [User::ROLE_ADMIN, User::ROLE_RESTAURANT_ADMIN])),
                Rule::unique('restaurants', 'user_id'),
            ],
            'status' => ['required', 'string', Rule::in(['active', 'inactive'])],
            'currency' => ['required', Rule::in(['USD', 'LBP', 'SYP', 'SAR', 'AED', 'EUR', 'QAR'])],
            'custom_domain' => ['required', 'string', 'max:255', 'unique:restaurant_domains,domain'],
            'menu_categories' => ['required', 'array', 'min:1'],
            'menu_categories.*' => ['required', 'string', Rule::in($categoryValues)],
        ]);

        $restaurant = DB::transaction(function () use ($validated): Restaurant {
            $restaurant = Restaurant::query()->create([
                'uuid' => (string) Str::uuid(),
                'user_id' => (int) $validated['user_id'],
                'name' => trim((string) $validated['name']),
                'slug' => strtolower(trim((string) $validated['slug'])),
                'status' => trim((string) $validated['status']),
                'currency' => trim((string) $validated['currency']),
                'dollar_rate' => 1,
                'profile' => [
                    'menu_categories' => array_values(array_unique(array_map(
                        fn (string $value): string => trim($value),
                        $validated['menu_categories']
                    ))),
                ],
            ]);

            RestaurantDomain::query()->create([
                'restaurant_id' => $restaurant->id,
                'domain' => $validated['custom_domain'],
                'kind' => 'custom',
                'is_primary' => true,
                'verified_at' => null,
            ]);

            $this->featureFlagService->enable($restaurant, 'custom_domain');

            return $restaurant->fresh(['domains']);
        });

        return response()->json([
            'message' => 'Restaurant created successfully.',
            'restaurant' => $this->formatRestaurant($restaurant),
        ], 201);
    }

    private function formatRestaurant(Restaurant $restaurant): array
    {
        $restaurant->loadMissing('domains');
        $profile = is_array($restaurant->profile) ? $restaurant->profile : [];
        $customDomain = $restaurant->domains
            ->firstWhere('kind', 'custom');

        return [
            'id' => $restaurant->id,
            'name' => $restaurant->name,
            'slug' => $restaurant->slug,
            'status' => $restaurant->status,
            'currency' => $restaurant->currency,
            'custom_domain' => $customDomain?->domain,
            'menu_categories' => array_values(array_filter(
                $profile['menu_categories'] ?? [],
                fn ($value): bool => is_string($value) && trim($value) !== ''
            )),
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
