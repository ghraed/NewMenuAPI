<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantDomain;
use App\Models\User;
use App\Services\FeatureFlagService;
use App\Services\GlobalIngredientProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SuperAdminRestaurantManagementController extends Controller
{
    /**
     * @return array<int, array{value:string, arabic:string}>
     */
    private function menuCategoryDefinitions(): array
    {
        return [
            ['value' => 'Appetizers', 'arabic' => 'مقبلات'],
            ['value' => 'Salads', 'arabic' => 'سلطات'],
            ['value' => 'Soups', 'arabic' => 'شوربات'],
            ['value' => 'Main Courses', 'arabic' => 'الأطباق الرئيسية'],
            ['value' => 'Sides', 'arabic' => 'أطباق جانبية'],
            ['value' => 'Desserts', 'arabic' => 'حلويات'],
            ['value' => 'Drinks', 'arabic' => 'مشروبات'],
            ['value' => 'Pizza', 'arabic' => 'بيتزا'],
            ['value' => 'Specialty Pizza', 'arabic' => 'بيتزا خاصة'],
            ['value' => 'Burgers', 'arabic' => 'برغر'],
            ['value' => 'Sandwiches', 'arabic' => 'ساندويتشات'],
            ['value' => 'Wraps', 'arabic' => 'راب'],
            ['value' => 'Hot Dogs', 'arabic' => 'هوت دوغ'],
            ['value' => 'Pasta', 'arabic' => 'باستا'],
            ['value' => 'Rice Dishes', 'arabic' => 'أطباق الأرز'],
            ['value' => 'Noodles', 'arabic' => 'نودلز'],
            ['value' => 'Chicken', 'arabic' => 'دجاج'],
            ['value' => 'Beef', 'arabic' => 'لحم بقري'],
            ['value' => 'Lamb', 'arabic' => 'لحم غنم'],
            ['value' => 'Seafood', 'arabic' => 'مأكولات بحرية'],
            ['value' => 'Vegetarian', 'arabic' => 'نباتي'],
            ['value' => 'Vegan', 'arabic' => 'نباتي صرف'],
            ['value' => 'Cold Mezze', 'arabic' => 'مقبلات باردة'],
            ['value' => 'Hot Mezze', 'arabic' => 'مقبلات ساخنة'],
            ['value' => 'Grills', 'arabic' => 'مشاوي'],
            ['value' => 'Shawarma', 'arabic' => 'شاورما'],
            ['value' => 'Manakish', 'arabic' => 'مناقيش'],
            ['value' => 'Traditional Dishes', 'arabic' => 'أكلات تقليدية'],
            ['value' => 'Italian', 'arabic' => 'إيطالي'],
            ['value' => 'American', 'arabic' => 'أمريكي'],
            ['value' => 'Mexican', 'arabic' => 'مكسيكي'],
            ['value' => 'Indian', 'arabic' => 'هندي'],
            ['value' => 'Chinese', 'arabic' => 'صيني'],
            ['value' => 'Japanese', 'arabic' => 'ياباني'],
            ['value' => 'Thai', 'arabic' => 'تايلندي'],
            ['value' => 'Korean', 'arabic' => 'كوري'],
            ['value' => 'Mediterranean', 'arabic' => 'متوسطي'],
            ['value' => 'Turkish', 'arabic' => 'تركي'],
            ['value' => 'Breakfast', 'arabic' => 'فطور'],
            ['value' => 'Brunch', 'arabic' => 'فطور متأخر'],
            ['value' => 'Lunch', 'arabic' => 'غداء'],
            ['value' => 'Dinner', 'arabic' => 'عشاء'],
            ['value' => 'Hot Drinks', 'arabic' => 'مشروبات ساخنة'],
            ['value' => 'Cold Drinks', 'arabic' => 'مشروبات باردة'],
            ['value' => 'Coffee', 'arabic' => 'قهوة'],
            ['value' => 'Tea', 'arabic' => 'شاي'],
            ['value' => 'Fresh Juices', 'arabic' => 'عصائر طازجة'],
            ['value' => 'Smoothies', 'arabic' => 'سموذي'],
            ['value' => 'Milkshakes', 'arabic' => 'ميلك شيك'],
            ['value' => 'Soft Drinks', 'arabic' => 'مشروبات غازية'],
            ['value' => 'Mocktails', 'arabic' => 'موكتيل'],
            ['value' => 'Cakes', 'arabic' => 'كيك'],
            ['value' => 'Pastries', 'arabic' => 'معجنات'],
            ['value' => 'Ice Cream', 'arabic' => 'آيس كريم'],
            ['value' => 'Arabic Sweets', 'arabic' => 'حلويات عربية'],
            ['value' => 'Bakery', 'arabic' => 'مخبوزات'],
            ['value' => 'Chef Specials', 'arabic' => 'أطباق الشيف'],
            ['value' => 'Popular Items', 'arabic' => 'الأكثر طلبًا'],
            ['value' => 'New Items', 'arabic' => 'جديد'],
            ['value' => 'Signature Dishes', 'arabic' => 'أطباق مميزة'],
            ['value' => 'Combo Meals', 'arabic' => 'وجبات كومبو'],
            ['value' => 'Kids Menu', 'arabic' => 'قائمة الأطفال'],
            ['value' => 'Healthy Options', 'arabic' => 'خيارات صحية'],
            ['value' => 'Gluten Free', 'arabic' => 'خالٍ من الغلوتين'],
            ['value' => 'Low Carb', 'arabic' => 'منخفض الكربوهيدرات'],
            ['value' => 'Spicy', 'arabic' => 'حار'],
            ['value' => 'Buffet', 'arabic' => 'بوفيه'],
            ['value' => 'Live Cooking', 'arabic' => 'طبخ مباشر'],
            ['value' => 'Salad Bar', 'arabic' => 'بار سلطات'],
            ['value' => 'Dessert Station', 'arabic' => 'ركن الحلويات'],
        ];
    }

    public function __construct(
        private readonly FeatureFlagService $featureFlagService,
        private readonly GlobalIngredientProvisioningService $globalIngredientProvisioningService,
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
            'menu_categories' => collect($this->menuCategoryDefinitions())
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
        $categoryValues = collect($this->menuCategoryDefinitions())
            ->pluck('value')
            ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->values()
            ->all();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:restaurants,slug'],
            'user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->whereIn('role', [User::ROLE_ADMIN, User::ROLE_RESTAURANT_ADMIN])),
                Rule::unique('restaurants', 'user_id'),
            ],
            'admin_user.name' => ['required_without:user_id', 'nullable', 'string', 'max:255'],
            'admin_user.email' => ['required_without:user_id', 'nullable', 'email:rfc', 'max:255', 'unique:users,email'],
            'admin_user.password' => ['required_without:user_id', 'nullable', 'string', 'min:8', 'max:255'],
            'admin_user.phone' => ['nullable', 'string', 'max:40', 'unique:users,phone'],
            'status' => ['required', 'string', Rule::in(['active', 'inactive'])],
            'currency' => ['required', Rule::in(['USD', 'LBP', 'SYP', 'SAR', 'AED', 'EUR', 'QAR'])],
            'custom_domain' => ['required', 'string', 'max:255', 'unique:restaurant_domains,domain'],
            'menu_categories' => ['required', 'array', 'min:1'],
            'menu_categories.*' => ['required', 'string', Rule::in($categoryValues)],
        ]);

        $restaurant = DB::transaction(function () use ($validated): Restaurant {
            $userId = isset($validated['user_id']) ? (int) $validated['user_id'] : null;

            if ($userId === null) {
                $adminInput = $validated['admin_user'] ?? [];
                $createdUser = User::query()->create([
                    'name' => trim((string) ($adminInput['name'] ?? '')),
                    'email' => strtolower(trim((string) ($adminInput['email'] ?? ''))),
                    'password' => (string) ($adminInput['password'] ?? ''),
                    'phone' => $this->normalizeOptionalString($adminInput['phone'] ?? null),
                    'role' => User::ROLE_ADMIN,
                ]);
                $userId = (int) $createdUser->id;
            }

            $restaurant = Restaurant::query()->create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $userId,
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
            $this->globalIngredientProvisioningService->provisionForRestaurant($restaurant);

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
