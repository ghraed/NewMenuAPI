<?php

namespace Database\Seeders;

use App\Models\Dish;
use App\Models\GlobalIngredient;
use App\Models\Ingredient;
use App\Models\Restaurant;
use App\Models\RestaurantDomain;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class RealWorldTenantScenarioSeeder extends Seeder
{
    private const DISHES_PER_RESTAURANT = 190;
    private const ALPHA_FOOD_COUNT = 170;
    private const ALPHA_DRINK_COUNT = 20;
    private const SIGMA_FOOD_COUNT = 170;
    private const SIGMA_DRINK_COUNT = 20;

    public function run(): void
    {
        $this->cleanupLegacyAlphaSlug();
        $this->cleanupLegacyTenantUsers();

        $alphaAdmin = $this->upsertUser(
            name: 'Alpha Admin',
            email: 'admin@alpha.com',
            password: 'admin12345',
            role: User::ROLE_ADMIN
        );

        $sigmaAdmin = $this->upsertUser(
            name: 'Sigma Admin',
            email: 'admin@sigma.com',
            password: 'admin12345',
            role: User::ROLE_ADMIN
        );

        $alphaStaff = $this->upsertUser(
            name: 'Alpha Staff',
            email: 'staff@alpha.com',
            password: 'staff12345',
            role: User::ROLE_STAFF
        );

        $alphaChef = $this->upsertUser(
            name: 'Alpha Chef',
            email: 'chef@alpha.com',
            password: 'chef12345',
            role: User::ROLE_CHEF
        );

        $alphaStockManager = $this->upsertUser(
            name: 'Alpha Stock Manager',
            email: 'stock@alph.com',
            password: 'stock12345',
            role: User::ROLE_STOCK_MANAGER
        );

        $sigmaStaff = $this->upsertUser(
            name: 'Sigma Staff',
            email: 'staff@sigma.com',
            password: 'staff12345',
            role: User::ROLE_STAFF
        );

        $alphaRestaurant = $this->upsertRestaurant(
            owner: $alphaAdmin,
            slug: 'alpha',
            name: 'Alpha',
            description: 'Modern Mediterranean kitchen with fresh grills, bowls, and handcrafted cold drinks.',
            address: 'Hamra District, Beirut'
        );

        $sigmaRestaurant = $this->upsertRestaurant(
            owner: $sigmaAdmin,
            slug: 'sigma',
            name: 'Sigma',
            description: 'Asian fusion kitchen focused on wok dishes, noodle bowls, and tea drinks.',
            address: 'Mar Mikhael, Beirut'
        );

        $alphaRestaurant->staffUsers()->syncWithoutDetaching([$alphaStaff->id, $alphaChef->id, $alphaStockManager->id]);
        $sigmaRestaurant->staffUsers()->syncWithoutDetaching([$sigmaStaff->id]);

        $this->upsertDomain($alphaRestaurant, 'alpha.rozer.fun');
        $this->upsertDomain($alphaRestaurant, 'rozer.fun', 'custom');
        $this->upsertDomain($sigmaRestaurant, 'sigma.rozer.fun');

        $alphaIngredientMap = $this->seedRestaurantIngredients($alphaRestaurant, $this->alphaIngredientDefinitions());
        $sigmaIngredientMap = $this->seedRestaurantIngredients($sigmaRestaurant, $this->sigmaIngredientDefinitions());

        $alphaDishes = array_merge(
            $this->generateAlphaFoodDishes(self::ALPHA_FOOD_COUNT),
            $this->generateAlphaDrinkDishes(self::ALPHA_DRINK_COUNT)
        );

        $sigmaDishes = array_merge(
            $this->generateSigmaFoodDishes(self::SIGMA_FOOD_COUNT),
            $this->generateSigmaDrinkDishes(self::SIGMA_DRINK_COUNT)
        );

        $this->seedRestaurantDishes($alphaRestaurant, $alphaIngredientMap, $alphaDishes);
        $this->seedRestaurantDishes($sigmaRestaurant, $sigmaIngredientMap, $sigmaDishes);

        $alphaCount = Dish::query()->where('restaurant_id', $alphaRestaurant->id)->count();
        $sigmaCount = Dish::query()->where('restaurant_id', $sigmaRestaurant->id)->count();

        if ($alphaCount !== self::DISHES_PER_RESTAURANT || $sigmaCount !== self::DISHES_PER_RESTAURANT) {
            throw new \RuntimeException(sprintf(
                'Unexpected dish count. alpha=%d sigma=%d expected=%d',
                $alphaCount,
                $sigmaCount,
                self::DISHES_PER_RESTAURANT
            ));
        }

        $this->command?->info('RealWorldTenantScenarioSeeder completed: alpha=190 dishes, sigma=190 dishes.');
    }

    private function cleanupLegacyAlphaSlug(): void
    {
        $legacy = Restaurant::query()->where('slug', 'alph')->first();

        if (! $legacy) {
            return;
        }

        $legacy->dishes()->withTrashed()->get()->each(function (Dish $dish): void {
            $dish->assets()->delete();
            $dish->forceDelete();
        });

        $legacy->ingredients()->delete();

        if (Schema::hasTable('restaurant_domains')) {
            RestaurantDomain::query()->where('restaurant_id', $legacy->id)->delete();
        }

        $legacy->delete();
    }

    private function cleanupLegacyTenantUsers(): void
    {
        User::query()
            ->whereIn('email', ['alpha.owner@rozer.fun', 'sigma.owner@rozer.fun'])
            ->delete();
    }

    private function upsertUser(string $name, string $email, string $password, string $role): User
    {
        $user = User::query()->firstOrNew(['email' => strtolower($email)]);

        if (! Hash::check($password, (string) $user->password)) {
            $user->password = Hash::make($password);
        }

        $user->name = $name;
        $user->role = $role;
        $user->save();

        return $user;
    }

    private function upsertRestaurant(User $owner, string $slug, string $name, string $description, string $address): Restaurant
    {
        $restaurant = Restaurant::query()->firstOrNew(['slug' => $slug]);

        if (! $restaurant->exists || ! $restaurant->uuid) {
            $restaurant->uuid = (string) Str::uuid();
        }

        $restaurant->user_id = $owner->id;
        $restaurant->name = $name;
        $restaurant->description = $description;
        $restaurant->address = $address;
        $restaurant->save();

        // Clean test dataset only for this tenant.
        $restaurant->dishes()->withTrashed()->get()->each(function (Dish $dish): void {
            $dish->assets()->delete();
            $dish->forceDelete();
        });
        $restaurant->ingredients()->delete();

        return $restaurant;
    }

    private function upsertDomain(Restaurant $restaurant, string $domain, string $kind = 'subdomain'): void
    {
        if (! class_exists(RestaurantDomain::class) || ! Schema::hasTable('restaurant_domains')) {
            return;
        }

        RestaurantDomain::query()->updateOrCreate(
            ['domain' => strtolower(trim($domain))],
            [
                'restaurant_id' => $restaurant->id,
                'kind' => $kind,
                'is_primary' => true,
                'verified_at' => now(),
            ]
        );
    }

    /**
     * @return array<int, array{name:string,name_ar:string,unit:string,stock:int|float,low:int|float}>
     */
    private function alphaIngredientDefinitions(): array
    {
        return [
            ['name' => 'Chicken Breast', 'name_ar' => 'صدر دجاج', 'unit' => 'g', 'stock' => 26000, 'low' => 5000],
            ['name' => 'Beef Sirloin', 'name_ar' => 'لحم بقري سيرلوين', 'unit' => 'g', 'stock' => 22000, 'low' => 4200],
            ['name' => 'Salmon Fillet', 'name_ar' => 'فيليه سلمون', 'unit' => 'g', 'stock' => 18000, 'low' => 3600],
            ['name' => 'Shrimp', 'name_ar' => 'روبيان', 'unit' => 'g', 'stock' => 16000, 'low' => 3200],
            ['name' => 'Halloumi Cheese', 'name_ar' => 'جبنة حلوم', 'unit' => 'g', 'stock' => 12000, 'low' => 2200],
            ['name' => 'Feta Cheese', 'name_ar' => 'جبنة فيتا', 'unit' => 'g', 'stock' => 10000, 'low' => 1900],
            ['name' => 'Greek Yogurt', 'name_ar' => 'لبن يوناني', 'unit' => 'g', 'stock' => 16000, 'low' => 2800],
            ['name' => 'Chickpea', 'name_ar' => 'حمص حب', 'unit' => 'g', 'stock' => 20000, 'low' => 3800],
            ['name' => 'Basmati Rice', 'name_ar' => 'أرز بسمتي', 'unit' => 'g', 'stock' => 32000, 'low' => 6500],
            ['name' => 'Pita Bread', 'name_ar' => 'خبز بيتا', 'unit' => 'piece', 'stock' => 400, 'low' => 80],
            ['name' => 'Lettuce', 'name_ar' => 'خس', 'unit' => 'g', 'stock' => 20000, 'low' => 3500],
            ['name' => 'Tomato', 'name_ar' => 'بندورة', 'unit' => 'g', 'stock' => 24000, 'low' => 4200],
            ['name' => 'Cucumber', 'name_ar' => 'خيار', 'unit' => 'g', 'stock' => 22000, 'low' => 3800],
            ['name' => 'Red Onion', 'name_ar' => 'بصل أحمر', 'unit' => 'g', 'stock' => 12000, 'low' => 2000],
            ['name' => 'Parsley', 'name_ar' => 'بقدونس', 'unit' => 'g', 'stock' => 8000, 'low' => 1200],
            ['name' => 'Mint', 'name_ar' => 'نعناع', 'unit' => 'g', 'stock' => 7000, 'low' => 1100],
            ['name' => 'Lemon Juice', 'name_ar' => 'عصير ليمون', 'unit' => 'ml', 'stock' => 18000, 'low' => 3200],
            ['name' => 'Olive Oil', 'name_ar' => 'زيت زيتون', 'unit' => 'ml', 'stock' => 24000, 'low' => 3600],
            ['name' => 'Garlic', 'name_ar' => 'ثوم', 'unit' => 'g', 'stock' => 5000, 'low' => 900],
            ['name' => 'Tahini', 'name_ar' => 'طحينة', 'unit' => 'g', 'stock' => 9000, 'low' => 1400],
            ['name' => 'Pomegranate Molasses', 'name_ar' => 'دبس رمان', 'unit' => 'ml', 'stock' => 7000, 'low' => 1200],
            ['name' => 'Orange Juice', 'name_ar' => 'عصير برتقال', 'unit' => 'ml', 'stock' => 12000, 'low' => 2100],
            ['name' => 'Sugar Syrup', 'name_ar' => 'شراب سكر', 'unit' => 'ml', 'stock' => 9000, 'low' => 1500],
            ['name' => 'Sparkling Water', 'name_ar' => 'مياه غازية', 'unit' => 'ml', 'stock' => 22000, 'low' => 3400],
            ['name' => 'Ice Cube', 'name_ar' => 'مكعبات ثلج', 'unit' => 'piece', 'stock' => 10000, 'low' => 1800],
        ];
    }

    /**
     * @return array<int, array{name:string,name_ar:string,unit:string,stock:int|float,low:int|float}>
     */
    private function sigmaIngredientDefinitions(): array
    {
        return [
            ['name' => 'Chicken Thigh', 'name_ar' => 'فخذ دجاج', 'unit' => 'g', 'stock' => 26000, 'low' => 5000],
            ['name' => 'Beef Tenderloin', 'name_ar' => 'لحم بقري فيليه', 'unit' => 'g', 'stock' => 22000, 'low' => 4200],
            ['name' => 'Salmon Fillet', 'name_ar' => 'فيليه سلمون', 'unit' => 'g', 'stock' => 17000, 'low' => 3200],
            ['name' => 'Shrimp', 'name_ar' => 'روبيان', 'unit' => 'g', 'stock' => 16000, 'low' => 3000],
            ['name' => 'Tofu', 'name_ar' => 'توفو', 'unit' => 'g', 'stock' => 14000, 'low' => 2200],
            ['name' => 'Mushroom', 'name_ar' => 'فطر', 'unit' => 'g', 'stock' => 18000, 'low' => 3200],
            ['name' => 'Egg Noodle', 'name_ar' => 'نودلز البيض', 'unit' => 'g', 'stock' => 30000, 'low' => 6500],
            ['name' => 'Ramen Noodle', 'name_ar' => 'نودلز رامن', 'unit' => 'g', 'stock' => 30000, 'low' => 6500],
            ['name' => 'Jasmine Rice', 'name_ar' => 'أرز ياسمين', 'unit' => 'g', 'stock' => 34000, 'low' => 7000],
            ['name' => 'Soy Sauce', 'name_ar' => 'صلصة الصويا', 'unit' => 'ml', 'stock' => 22000, 'low' => 3500],
            ['name' => 'Sesame Oil', 'name_ar' => 'زيت السمسم', 'unit' => 'ml', 'stock' => 11000, 'low' => 1600],
            ['name' => 'Miso Paste', 'name_ar' => 'معجون الميسو', 'unit' => 'g', 'stock' => 11000, 'low' => 1700],
            ['name' => 'Coconut Milk', 'name_ar' => 'حليب جوز الهند', 'unit' => 'ml', 'stock' => 16000, 'low' => 2600],
            ['name' => 'Bell Pepper', 'name_ar' => 'فليفلة', 'unit' => 'g', 'stock' => 18000, 'low' => 3000],
            ['name' => 'Carrot', 'name_ar' => 'جزر', 'unit' => 'g', 'stock' => 17000, 'low' => 2800],
            ['name' => 'Spring Onion', 'name_ar' => 'بصل أخضر', 'unit' => 'g', 'stock' => 9000, 'low' => 1300],
            ['name' => 'Ginger', 'name_ar' => 'زنجبيل', 'unit' => 'g', 'stock' => 6000, 'low' => 900],
            ['name' => 'Garlic', 'name_ar' => 'ثوم', 'unit' => 'g', 'stock' => 6000, 'low' => 900],
            ['name' => 'Lime Juice', 'name_ar' => 'عصير لايم', 'unit' => 'ml', 'stock' => 13000, 'low' => 2200],
            ['name' => 'Chili Sauce', 'name_ar' => 'صلصة فلفل حار', 'unit' => 'ml', 'stock' => 10000, 'low' => 1600],
            ['name' => 'Honey', 'name_ar' => 'عسل', 'unit' => 'g', 'stock' => 8000, 'low' => 1200],
            ['name' => 'Green Tea', 'name_ar' => 'شاي أخضر', 'unit' => 'g', 'stock' => 5000, 'low' => 700],
            ['name' => 'Black Tea', 'name_ar' => 'شاي أسود', 'unit' => 'g', 'stock' => 5000, 'low' => 700],
            ['name' => 'Sugar Syrup', 'name_ar' => 'شراب سكر', 'unit' => 'ml', 'stock' => 11000, 'low' => 1700],
            ['name' => 'Sparkling Water', 'name_ar' => 'مياه غازية', 'unit' => 'ml', 'stock' => 22000, 'low' => 3500],
            ['name' => 'Ice Cube', 'name_ar' => 'مكعبات ثلج', 'unit' => 'piece', 'stock' => 10000, 'low' => 1800],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function generateAlphaFoodDishes(int $count): array
    {
        $styles = [
            ['en' => 'Charred', 'ar' => 'مشوي'],
            ['en' => 'Citrus', 'ar' => 'حمضي'],
            ['en' => 'Herb-Roasted', 'ar' => 'أعشاب مشوية'],
            ['en' => 'Smoked', 'ar' => 'مدخن'],
            ['en' => 'Fire-Grilled', 'ar' => 'مشوي على النار'],
        ];

        $proteins = [
            ['label' => 'Chicken', 'label_ar' => 'دجاج', 'ingredient' => 'Chicken Breast', 'extra_price' => 1.5, 'cal' => 180],
            ['label' => 'Beef', 'label_ar' => 'لحم بقري', 'ingredient' => 'Beef Sirloin', 'extra_price' => 3.2, 'cal' => 230],
            ['label' => 'Salmon', 'label_ar' => 'سلمون', 'ingredient' => 'Salmon Fillet', 'extra_price' => 4.0, 'cal' => 210],
            ['label' => 'Shrimp', 'label_ar' => 'روبيان', 'ingredient' => 'Shrimp', 'extra_price' => 3.4, 'cal' => 190],
            ['label' => 'Halloumi', 'label_ar' => 'حلوم', 'ingredient' => 'Halloumi Cheese', 'extra_price' => 2.2, 'cal' => 220],
            ['label' => 'Chickpea', 'label_ar' => 'حمص', 'ingredient' => 'Chickpea', 'extra_price' => 0.8, 'cal' => 150],
            ['label' => 'Feta', 'label_ar' => 'فيتا', 'ingredient' => 'Feta Cheese', 'extra_price' => 1.8, 'cal' => 170],
        ];

        $bases = [
            ['label' => 'Rice Bowl', 'label_ar' => 'باول أرز', 'category' => 'Bowls', 'category_ar' => 'أطباق بول', 'base_price' => 9.8, 'base_cal' => 360],
            ['label' => 'Pita Wrap', 'label_ar' => 'لفافة بيتا', 'category' => 'Wraps', 'category_ar' => 'لفائف', 'base_price' => 9.2, 'base_cal' => 320],
            ['label' => 'Garden Salad', 'label_ar' => 'سلطة الحديقة', 'category' => 'Salads', 'category_ar' => 'سلطات', 'base_price' => 8.9, 'base_cal' => 240],
            ['label' => 'Mediterranean Plate', 'label_ar' => 'طبق متوسطي', 'category' => 'Mains', 'category_ar' => 'أطباق رئيسية', 'base_price' => 10.4, 'base_cal' => 350],
            ['label' => 'Lemon Rice Plate', 'label_ar' => 'طبق أرز بالليمون', 'category' => 'Mains', 'category_ar' => 'أطباق رئيسية', 'base_price' => 10.2, 'base_cal' => 370],
            ['label' => 'Warm Mezze Bowl', 'label_ar' => 'باول مقبلات دافئ', 'category' => 'Mezze', 'category_ar' => 'مقبلات', 'base_price' => 9.4, 'base_cal' => 280],
        ];

        $dishes = [];
        foreach ($styles as $style) {
            foreach ($proteins as $protein) {
                foreach ($bases as $base) {
                    if (count($dishes) >= $count) {
                        break 3;
                    }

                    $name = sprintf('%s %s %s', $style['en'], $protein['label'], $base['label']);
                    $nameAr = sprintf('%s %s %s', $base['label_ar'], $protein['label_ar'], $style['ar']);

                    $price = round($base['base_price'] + $protein['extra_price'] + ($style['en'] === 'Fire-Grilled' ? 0.5 : 0.0), 2);
                    $calories = (int) round($base['base_cal'] + $protein['cal']);

                    $dishes[] = [
                        'name' => $name,
                        'name_ar' => $nameAr,
                        'description' => sprintf(
                            '%s %s prepared fresh daily with seasonal vegetables, citrus dressing, and house olive oil finish.',
                            $style['en'],
                            strtolower($protein['label'])
                        ),
                        'description_ar' => 'طبق طازج يومي مع خضار موسمية وتتبيـلة حمضيات ولمسة زيت زيتون خاصة.',
                        'category' => $base['category'],
                        'category_ar' => $base['category_ar'],
                        'price' => $price,
                        'calories' => $calories,
                        'image_url' => $this->dishImageUrl($name),
                        'recipe' => $this->alphaRecipe(
                            proteinIngredient: $protein['ingredient'],
                            baseLabel: $base['label'],
                            styleLabel: $style['en']
                        ),
                    ];
                }
            }
        }

        return $dishes;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function generateAlphaDrinkDishes(int $count): array
    {
        $drinkNames = [
            ['name' => 'Citrus Mint Sparkler', 'name_ar' => 'مشروب حمضيات ونعناع فوار', 'core' => 'citrus'],
            ['name' => 'Orange Basil Cooler', 'name_ar' => 'كولر برتقال وريحان', 'core' => 'orange'],
            ['name' => 'Pomegranate Lemon Fizz', 'name_ar' => 'فوار رمان وليمون', 'core' => 'pomegranate'],
            ['name' => 'Classic House Lemonade', 'name_ar' => 'ليمونادة المنزل الكلاسيكية', 'core' => 'lemonade'],
            ['name' => 'Cucumber Mint Refresher', 'name_ar' => 'مشروب خيار ونعناع منعش', 'core' => 'cucumber'],
            ['name' => 'Sunset Orange Spritz', 'name_ar' => 'سبريتز البرتقال عند الغروب', 'core' => 'orange'],
            ['name' => 'Lemon Garden Cooler', 'name_ar' => 'كولر الليمون الأخضر', 'core' => 'lemonade'],
            ['name' => 'Sparkling Citrus Punch', 'name_ar' => 'بانش حمضيات فوار', 'core' => 'citrus'],
            ['name' => 'Minty Orange Burst', 'name_ar' => 'انفجار البرتقال بالنعناع', 'core' => 'orange'],
            ['name' => 'Pomegranate Mint Soda', 'name_ar' => 'صودا رمان بالنعناع', 'core' => 'pomegranate'],
            ['name' => 'Lemon Ice Splash', 'name_ar' => 'دفقة ليمون مثلجة', 'core' => 'lemonade'],
            ['name' => 'Citrus Club Soda', 'name_ar' => 'صودا حمضيات كلوب', 'core' => 'citrus'],
            ['name' => 'Fresh Mint Lemon Lift', 'name_ar' => 'ليمون ونعناع منعش', 'core' => 'lemonade'],
            ['name' => 'Orange Cooler No.1', 'name_ar' => 'كولر برتقال رقم 1', 'core' => 'orange'],
            ['name' => 'Orange Cooler No.2', 'name_ar' => 'كولر برتقال رقم 2', 'core' => 'orange'],
            ['name' => 'Orange Cooler No.3', 'name_ar' => 'كولر برتقال رقم 3', 'core' => 'orange'],
            ['name' => 'Pomegranate Spark No.1', 'name_ar' => 'فوار رمان رقم 1', 'core' => 'pomegranate'],
            ['name' => 'Pomegranate Spark No.2', 'name_ar' => 'فوار رمان رقم 2', 'core' => 'pomegranate'],
            ['name' => 'Lemonade Signature No.1', 'name_ar' => 'ليمونادة مميزة رقم 1', 'core' => 'lemonade'],
            ['name' => 'Lemonade Signature No.2', 'name_ar' => 'ليمونادة مميزة رقم 2', 'core' => 'lemonade'],
        ];

        $selected = array_slice($drinkNames, 0, $count);

        return array_map(function (array $drink): array {
            return [
                'name' => $drink['name'],
                'name_ar' => $drink['name_ar'],
                'description' => 'Handcrafted cold drink with fresh juice, light syrup, sparkling finish, and served over ice.',
                'description_ar' => 'مشروب بارد محضر يدويًا بعصير طازج وشراب خفيف ولمسة فوارة يقدم مع الثلج.',
                'category' => 'Drinks',
                'category_ar' => 'مشروبات',
                'price' => 4.20,
                'calories' => 130,
                'image_url' => $this->dishImageUrl($drink['name']),
                'recipe' => $this->alphaDrinkRecipe($drink['core']),
            ];
        }, $selected);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function generateSigmaFoodDishes(int $count): array
    {
        $styles = [
            ['en' => 'Wok-Fired', 'ar' => 'ووك محمّر'],
            ['en' => 'Umami', 'ar' => 'أومامي'],
            ['en' => 'Sesame', 'ar' => 'سمسم'],
            ['en' => 'Spicy', 'ar' => 'حار'],
            ['en' => 'Teriyaki', 'ar' => 'ترياكي'],
        ];

        $proteins = [
            ['label' => 'Chicken', 'label_ar' => 'دجاج', 'ingredient' => 'Chicken Thigh', 'extra_price' => 1.4, 'cal' => 210],
            ['label' => 'Beef', 'label_ar' => 'لحم بقري', 'ingredient' => 'Beef Tenderloin', 'extra_price' => 3.0, 'cal' => 240],
            ['label' => 'Shrimp', 'label_ar' => 'روبيان', 'ingredient' => 'Shrimp', 'extra_price' => 3.4, 'cal' => 200],
            ['label' => 'Salmon', 'label_ar' => 'سلمون', 'ingredient' => 'Salmon Fillet', 'extra_price' => 4.1, 'cal' => 220],
            ['label' => 'Tofu', 'label_ar' => 'توفو', 'ingredient' => 'Tofu', 'extra_price' => 1.1, 'cal' => 170],
            ['label' => 'Mushroom', 'label_ar' => 'فطر', 'ingredient' => 'Mushroom', 'extra_price' => 0.9, 'cal' => 130],
            ['label' => 'Mixed Protein', 'label_ar' => 'بروتين مشكّل', 'ingredient' => 'Chicken Thigh', 'extra_price' => 3.8, 'cal' => 250],
        ];

        $bases = [
            ['label' => 'Ramen Bowl', 'label_ar' => 'باول رامن', 'category' => 'Bowls', 'category_ar' => 'أطباق بول', 'base_price' => 10.8, 'base_cal' => 420],
            ['label' => 'Egg Noodle Bowl', 'label_ar' => 'باول نودلز البيض', 'category' => 'Noodles', 'category_ar' => 'نودلز', 'base_price' => 10.5, 'base_cal' => 400],
            ['label' => 'Jasmine Rice Bowl', 'label_ar' => 'باول أرز ياسمين', 'category' => 'Bowls', 'category_ar' => 'أطباق بول', 'base_price' => 10.2, 'base_cal' => 380],
            ['label' => 'Stir Fry Plate', 'label_ar' => 'طبق ستير فراي', 'category' => 'Mains', 'category_ar' => 'أطباق رئيسية', 'base_price' => 10.7, 'base_cal' => 360],
            ['label' => 'Curry Bowl', 'label_ar' => 'باول كاري', 'category' => 'Curries', 'category_ar' => 'كاري', 'base_price' => 11.4, 'base_cal' => 430],
            ['label' => 'Street Bowl', 'label_ar' => 'باول ستريت', 'category' => 'Mains', 'category_ar' => 'أطباق رئيسية', 'base_price' => 10.3, 'base_cal' => 370],
        ];

        $dishes = [];
        foreach ($styles as $style) {
            foreach ($proteins as $protein) {
                foreach ($bases as $base) {
                    if (count($dishes) >= $count) {
                        break 3;
                    }

                    $name = sprintf('%s %s %s', $style['en'], $protein['label'], $base['label']);
                    $nameAr = sprintf('%s %s %s', $base['label_ar'], $protein['label_ar'], $style['ar']);

                    $price = round($base['base_price'] + $protein['extra_price'] + ($style['en'] === 'Spicy' ? 0.4 : 0.0), 2);
                    $calories = (int) round($base['base_cal'] + $protein['cal']);

                    $dishes[] = [
                        'name' => $name,
                        'name_ar' => $nameAr,
                        'description' => sprintf(
                            '%s %s dish cooked to order with house sauces, wok vegetables, and balanced seasoning.',
                            $style['en'],
                            strtolower($protein['label'])
                        ),
                        'description_ar' => 'طبق يجهز حسب الطلب مع صلصات المنزل وخضار الووك وتوازن نكهات دقيق.',
                        'category' => $base['category'],
                        'category_ar' => $base['category_ar'],
                        'price' => $price,
                        'calories' => $calories,
                        'image_url' => $this->dishImageUrl($name),
                        'recipe' => $this->sigmaRecipe(
                            proteinIngredient: $protein['ingredient'],
                            baseLabel: $base['label'],
                            styleLabel: $style['en']
                        ),
                    ];
                }
            }
        }

        return $dishes;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function generateSigmaDrinkDishes(int $count): array
    {
        $drinkNames = [
            ['name' => 'Yuzu Green Tea Cooler', 'name_ar' => 'كولر شاي أخضر ويوزو', 'core' => 'green'],
            ['name' => 'Iced Thai Milk Tea', 'name_ar' => 'شاي تايلندي بالحليب مثلج', 'core' => 'black'],
            ['name' => 'Lime Green Tea Fizz', 'name_ar' => 'فوار شاي أخضر ولايم', 'core' => 'green'],
            ['name' => 'Sparkling Black Tea Lemon', 'name_ar' => 'شاي أسود فوار بالليمون', 'core' => 'black'],
            ['name' => 'Cold Brew Tea No.1', 'name_ar' => 'شاي بارد رقم 1', 'core' => 'green'],
            ['name' => 'Cold Brew Tea No.2', 'name_ar' => 'شاي بارد رقم 2', 'core' => 'black'],
            ['name' => 'Tea Spritz Signature 1', 'name_ar' => 'تي سبريتز مميز 1', 'core' => 'green'],
            ['name' => 'Tea Spritz Signature 2', 'name_ar' => 'تي سبريتز مميز 2', 'core' => 'black'],
            ['name' => 'Tea Spritz Signature 3', 'name_ar' => 'تي سبريتز مميز 3', 'core' => 'green'],
            ['name' => 'Tea Spritz Signature 4', 'name_ar' => 'تي سبريتز مميز 4', 'core' => 'black'],
            ['name' => 'Lime Tea Cooler 1', 'name_ar' => 'كولر شاي ولايم 1', 'core' => 'green'],
            ['name' => 'Lime Tea Cooler 2', 'name_ar' => 'كولر شاي ولايم 2', 'core' => 'black'],
            ['name' => 'Lime Tea Cooler 3', 'name_ar' => 'كولر شاي ولايم 3', 'core' => 'green'],
            ['name' => 'Lime Tea Cooler 4', 'name_ar' => 'كولر شاي ولايم 4', 'core' => 'black'],
            ['name' => 'Tea Fizz Reserve 1', 'name_ar' => 'تي فيز ريزرف 1', 'core' => 'green'],
            ['name' => 'Tea Fizz Reserve 2', 'name_ar' => 'تي فيز ريزرف 2', 'core' => 'black'],
            ['name' => 'Tea Fizz Reserve 3', 'name_ar' => 'تي فيز ريزرف 3', 'core' => 'green'],
            ['name' => 'Tea Fizz Reserve 4', 'name_ar' => 'تي فيز ريزرف 4', 'core' => 'black'],
            ['name' => 'House Green Tea Soda', 'name_ar' => 'صودا شاي أخضر', 'core' => 'green'],
            ['name' => 'House Black Tea Soda', 'name_ar' => 'صودا شاي أسود', 'core' => 'black'],
        ];

        $selected = array_slice($drinkNames, 0, $count);

        return array_map(function (array $drink): array {
            return [
                'name' => $drink['name'],
                'name_ar' => $drink['name_ar'],
                'description' => 'Iced tea beverage finished with lime, syrup balance, and sparkling water over ice.',
                'description_ar' => 'مشروب شاي مثلج مع لايم وتوازن شراب سكر ولمسة مياه غازية فوق الثلج.',
                'category' => 'Drinks',
                'category_ar' => 'مشروبات',
                'price' => 4.30,
                'calories' => 120,
                'image_url' => $this->dishImageUrl($drink['name']),
                'recipe' => $this->sigmaDrinkRecipe($drink['core']),
            ];
        }, $selected);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function alphaRecipe(string $proteinIngredient, string $baseLabel, string $styleLabel): array
    {
        $recipe = [
            ['ingredient' => $proteinIngredient, 'qty' => $proteinIngredient === 'Chickpea' ? 150 : 180, 'unit' => 'g'],
            ['ingredient' => 'Tomato', 'qty' => 45, 'unit' => 'g'],
            ['ingredient' => 'Cucumber', 'qty' => 40, 'unit' => 'g'],
            ['ingredient' => 'Red Onion', 'qty' => 14, 'unit' => 'g'],
            ['ingredient' => 'Olive Oil', 'qty' => 16, 'unit' => 'ml'],
            ['ingredient' => 'Lemon Juice', 'qty' => 18, 'unit' => 'ml'],
            ['ingredient' => 'Garlic', 'qty' => 5, 'unit' => 'g'],
        ];

        if (str_contains($baseLabel, 'Rice')) {
            $recipe[] = ['ingredient' => 'Basmati Rice', 'qty' => 170, 'unit' => 'g'];
        }

        if (str_contains($baseLabel, 'Pita')) {
            $recipe[] = ['ingredient' => 'Pita Bread', 'qty' => 1, 'unit' => 'piece'];
        }

        if (str_contains($baseLabel, 'Salad') || str_contains($baseLabel, 'Bowl')) {
            $recipe[] = ['ingredient' => 'Lettuce', 'qty' => 45, 'unit' => 'g'];
            $recipe[] = ['ingredient' => 'Parsley', 'qty' => 8, 'unit' => 'g'];
        }

        if (str_contains($styleLabel, 'Smoked')) {
            $recipe[] = ['ingredient' => 'Pomegranate Molasses', 'qty' => 14, 'unit' => 'ml'];
        }

        if (str_contains($styleLabel, 'Herb')) {
            $recipe[] = ['ingredient' => 'Mint', 'qty' => 4, 'unit' => 'g'];
            $recipe[] = ['ingredient' => 'Greek Yogurt', 'qty' => 35, 'unit' => 'g'];
        }

        if (str_contains($styleLabel, 'Citrus')) {
            $recipe[] = ['ingredient' => 'Lemon Juice', 'qty' => 10, 'unit' => 'ml'];
        }

        if (in_array($proteinIngredient, ['Halloumi Cheese', 'Feta Cheese'], true)) {
            $recipe[] = ['ingredient' => 'Tahini', 'qty' => 20, 'unit' => 'g'];
        }

        return $recipe;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function alphaDrinkRecipe(string $core): array
    {
        $recipe = [
            ['ingredient' => 'Sugar Syrup', 'qty' => 16, 'unit' => 'ml'],
            ['ingredient' => 'Sparkling Water', 'qty' => 170, 'unit' => 'ml'],
            ['ingredient' => 'Ice Cube', 'qty' => 8, 'unit' => 'piece'],
            ['ingredient' => 'Mint', 'qty' => 3, 'unit' => 'g'],
        ];

        if ($core === 'orange') {
            $recipe[] = ['ingredient' => 'Orange Juice', 'qty' => 90, 'unit' => 'ml'];
            $recipe[] = ['ingredient' => 'Lemon Juice', 'qty' => 14, 'unit' => 'ml'];
        } elseif ($core === 'pomegranate') {
            $recipe[] = ['ingredient' => 'Orange Juice', 'qty' => 50, 'unit' => 'ml'];
            $recipe[] = ['ingredient' => 'Pomegranate Molasses', 'qty' => 12, 'unit' => 'ml'];
            $recipe[] = ['ingredient' => 'Lemon Juice', 'qty' => 12, 'unit' => 'ml'];
        } elseif ($core === 'cucumber') {
            $recipe[] = ['ingredient' => 'Cucumber', 'qty' => 45, 'unit' => 'g'];
            $recipe[] = ['ingredient' => 'Lemon Juice', 'qty' => 18, 'unit' => 'ml'];
        } else {
            $recipe[] = ['ingredient' => 'Lemon Juice', 'qty' => 30, 'unit' => 'ml'];
            $recipe[] = ['ingredient' => 'Orange Juice', 'qty' => 55, 'unit' => 'ml'];
        }

        return $recipe;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sigmaRecipe(string $proteinIngredient, string $baseLabel, string $styleLabel): array
    {
        $recipe = [
            ['ingredient' => $proteinIngredient, 'qty' => $proteinIngredient === 'Mushroom' ? 160 : 180, 'unit' => 'g'],
            ['ingredient' => 'Soy Sauce', 'qty' => 24, 'unit' => 'ml'],
            ['ingredient' => 'Garlic', 'qty' => 6, 'unit' => 'g'],
            ['ingredient' => 'Ginger', 'qty' => 6, 'unit' => 'g'],
            ['ingredient' => 'Spring Onion', 'qty' => 12, 'unit' => 'g'],
            ['ingredient' => 'Bell Pepper', 'qty' => 45, 'unit' => 'g'],
            ['ingredient' => 'Carrot', 'qty' => 35, 'unit' => 'g'],
        ];

        if (str_contains($baseLabel, 'Ramen')) {
            $recipe[] = ['ingredient' => 'Ramen Noodle', 'qty' => 170, 'unit' => 'g'];
            $recipe[] = ['ingredient' => 'Miso Paste', 'qty' => 24, 'unit' => 'g'];
        }

        if (str_contains($baseLabel, 'Egg Noodle')) {
            $recipe[] = ['ingredient' => 'Egg Noodle', 'qty' => 175, 'unit' => 'g'];
        }

        if (str_contains($baseLabel, 'Rice') || str_contains($baseLabel, 'Street')) {
            $recipe[] = ['ingredient' => 'Jasmine Rice', 'qty' => 170, 'unit' => 'g'];
        }

        if (str_contains($baseLabel, 'Curry')) {
            $recipe[] = ['ingredient' => 'Coconut Milk', 'qty' => 130, 'unit' => 'ml'];
            $recipe[] = ['ingredient' => 'Lime Juice', 'qty' => 16, 'unit' => 'ml'];
        }

        if (str_contains($styleLabel, 'Sesame')) {
            $recipe[] = ['ingredient' => 'Sesame Oil', 'qty' => 12, 'unit' => 'ml'];
        }

        if (str_contains($styleLabel, 'Spicy')) {
            $recipe[] = ['ingredient' => 'Chili Sauce', 'qty' => 22, 'unit' => 'ml'];
        }

        if (str_contains($styleLabel, 'Teriyaki')) {
            $recipe[] = ['ingredient' => 'Honey', 'qty' => 16, 'unit' => 'g'];
        }

        if ($proteinIngredient === 'Chicken Thigh' && str_contains($baseLabel, 'Street')) {
            $recipe[] = ['ingredient' => 'Egg Noodle', 'qty' => 90, 'unit' => 'g'];
        }

        if ($proteinIngredient === 'Chicken Thigh' && str_contains($baseLabel, 'Street') && str_contains($styleLabel, 'Mixed')) {
            $recipe[] = ['ingredient' => 'Shrimp', 'qty' => 70, 'unit' => 'g'];
        }

        return $recipe;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sigmaDrinkRecipe(string $core): array
    {
        $recipe = [
            ['ingredient' => 'Sugar Syrup', 'qty' => 16, 'unit' => 'ml'],
            ['ingredient' => 'Sparkling Water', 'qty' => 165, 'unit' => 'ml'],
            ['ingredient' => 'Lime Juice', 'qty' => 18, 'unit' => 'ml'],
            ['ingredient' => 'Ice Cube', 'qty' => 8, 'unit' => 'piece'],
        ];

        if ($core === 'black') {
            $recipe[] = ['ingredient' => 'Black Tea', 'qty' => 5, 'unit' => 'g'];
            $recipe[] = ['ingredient' => 'Coconut Milk', 'qty' => 80, 'unit' => 'ml'];
        } else {
            $recipe[] = ['ingredient' => 'Green Tea', 'qty' => 5, 'unit' => 'g'];
        }

        return $recipe;
    }

    private function dishImageUrl(string $name): string
    {
        $keywords = trim((string) preg_replace('/[^a-z0-9 ]+/i', ' ', strtolower($name)));
        $keywords = preg_replace('/\s+/', ',', $keywords) ?: 'food';

        return sprintf(
            'https://source.unsplash.com/1600x900/?food,%s',
            urlencode((string) $keywords)
        );
    }

    /**
     * @param array<int, array{name:string,name_ar:string,unit:string,stock:float|int,low:float|int}> $definitions
     * @return array<string, Ingredient>
     */
    private function seedRestaurantIngredients(Restaurant $restaurant, array $definitions): array
    {
        $map = [];

        foreach ($definitions as $definition) {
            $globalIngredient = $this->resolveGlobalIngredient(
                name: $definition['name'],
                nameAr: $definition['name_ar']
            );

            $ingredient = Ingredient::query()->firstOrNew([
                'restaurant_id' => $restaurant->id,
                'name' => $globalIngredient->name,
            ]);

            if (! $ingredient->exists) {
                $ingredient->uuid = (string) Str::uuid();
            }

            $ingredient->global_ingredient_id = $globalIngredient->id;
            $ingredient->name_ar = $globalIngredient->name_ar ?: $definition['name_ar'];
            $ingredient->storage_disk = 'public';
            $ingredient->file_path = null;
            $ingredient->source_file_name = null;
            $ingredient->file_size = null;
            $ingredient->mime_type = null;
            $ingredient->stock_unit = $definition['unit'];
            $ingredient->current_stock_quantity = $definition['stock'];
            $ingredient->low_stock_threshold = $definition['low'];
            $ingredient->is_active = true;
            $ingredient->save();

            $map[$this->normalizeKey($globalIngredient->name)] = $ingredient;
            $map[$this->normalizeKey($definition['name'])] = $ingredient;
        }

        return $map;
    }

    /**
     * @param array<int, array{
     *   name:string,
     *   name_ar:string,
     *   description:string,
     *   description_ar:string,
     *   category:string,
     *   category_ar:string,
     *   price:float,
     *   calories:int,
     *   image_url:string,
     *   recipe:array<int, array{ingredient:string,qty:float|int,unit:string}>
     * }> $dishes
     * @param array<string, Ingredient> $ingredientMap
     */
    private function seedRestaurantDishes(Restaurant $restaurant, array $ingredientMap, array $dishes): void
    {
        foreach ($dishes as $dishDefinition) {
            $dish = Dish::query()->firstOrNew([
                'restaurant_id' => $restaurant->id,
                'name' => $dishDefinition['name'],
            ]);

            if (! $dish->exists) {
                $dish->uuid = (string) Str::uuid();
            }

            $dish->name_ar = $dishDefinition['name_ar'];
            $dish->description = $dishDefinition['description'];
            $dish->description_ar = $dishDefinition['description_ar'];
            $dish->price = $dishDefinition['price'];
            $dish->calories = $dishDefinition['calories'];
            $dish->category = $dishDefinition['category'];
            $dish->category_ar = $dishDefinition['category_ar'];
            $dish->status = 'published';
            $dish->image_url = $dishDefinition['image_url'];
            $dish->save();

            $recipeRows = [];
            foreach ($dishDefinition['recipe'] as $index => $recipe) {
                $ingredientKey = $this->normalizeKey($recipe['ingredient']);
                $ingredient = $ingredientMap[$ingredientKey] ?? null;

                if (! $ingredient) {
                    throw new \RuntimeException(sprintf(
                        'Missing ingredient "%s" for dish "%s".',
                        $recipe['ingredient'],
                        $dishDefinition['name']
                    ));
                }

                $recipeRows[] = [
                    'ingredient_id' => $ingredient->id,
                    'quantity' => $recipe['qty'],
                    'unit' => $recipe['unit'],
                    'order_index' => $index,
                    'show_in_animation' => true,
                ];
            }

            $recipeRows = $this->mergeDuplicateRecipeRows($recipeRows, $dishDefinition['name']);

            $dish->dishIngredients()->delete();
            $dish->dishIngredients()->createMany($recipeRows);
        }
    }

    /**
     * Ensure one row per ingredient_id to satisfy unique(dish_id, ingredient_id).
     *
     * @param array<int, array{ingredient_id:int,quantity:float|int,unit:string,order_index:int,show_in_animation:bool}> $recipeRows
     * @return array<int, array{ingredient_id:int,quantity:float,unit:string,order_index:int,show_in_animation:bool}>
     */
    private function mergeDuplicateRecipeRows(array $recipeRows, string $dishName): array
    {
        $merged = [];

        foreach ($recipeRows as $row) {
            $key = (int) $row['ingredient_id'];

            if (! isset($merged[$key])) {
                $merged[$key] = [
                    'ingredient_id' => $key,
                    'quantity' => (float) $row['quantity'],
                    'unit' => (string) $row['unit'],
                    'order_index' => (int) $row['order_index'],
                    'show_in_animation' => (bool) $row['show_in_animation'],
                ];
                continue;
            }

            if ($merged[$key]['unit'] !== (string) $row['unit']) {
                throw new \RuntimeException(sprintf(
                    'Unit conflict for duplicate ingredient %d in dish "%s": %s vs %s',
                    $key,
                    $dishName,
                    $merged[$key]['unit'],
                    (string) $row['unit']
                ));
            }

            $merged[$key]['quantity'] += (float) $row['quantity'];
            $merged[$key]['order_index'] = min($merged[$key]['order_index'], (int) $row['order_index']);
            $merged[$key]['show_in_animation'] = $merged[$key]['show_in_animation'] || (bool) $row['show_in_animation'];
        }

        usort($merged, static fn (array $left, array $right): int => $left['order_index'] <=> $right['order_index']);

        return array_values($merged);
    }

    private function resolveGlobalIngredient(string $name, ?string $nameAr = null): GlobalIngredient
    {
        $normalizedName = $this->normalizeKey($name);

        $globalIngredient = GlobalIngredient::query()
            ->where('normalized_name', $normalizedName)
            ->first();

        if ($globalIngredient) {
            if (! $globalIngredient->name_ar && $nameAr) {
                $globalIngredient->name_ar = $nameAr;
                $globalIngredient->save();
            }

            return $globalIngredient;
        }

        return GlobalIngredient::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => trim($name),
            'name_ar' => $nameAr,
            'normalized_name' => $normalizedName,
        ]);
    }

    private function normalizeKey(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = str_replace('&', 'and', $normalized);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    }
}
