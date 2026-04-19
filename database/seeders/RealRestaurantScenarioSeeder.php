<?php

namespace Database\Seeders;

use App\Models\Dish;
use App\Models\DishIngredient;
use App\Models\GlobalIngredient;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\TableSession;
use App\Models\TableWave;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RealRestaurantScenarioSeeder extends Seeder
{
    private const MARKER = '[real-scenario-seeder]';

    public function run(): void
    {
        $admin = User::query()->updateOrCreate(
            ['email' => 'cedar.admin@demo.local'],
            [
                'name' => 'Cedar Admin',
                'password' => Hash::make('password'),
                'role' => User::ROLE_ADMIN,
                'phone' => '+96170000001',
            ]
        );

        $staffCaptain = User::query()->updateOrCreate(
            ['email' => 'captain@cedarflame.local'],
            [
                'name' => 'Floor Captain',
                'password' => Hash::make('password'),
                'role' => User::ROLE_STAFF,
                'phone' => '+96170000011',
            ]
        );

        $staffServer = User::query()->updateOrCreate(
            ['email' => 'server@cedarflame.local'],
            [
                'name' => 'Senior Server',
                'password' => Hash::make('password'),
                'role' => User::ROLE_STAFF,
                'phone' => '+96170000012',
            ]
        );

        $restaurant = Restaurant::query()->updateOrCreate(
            ['slug' => 'cedar-flame-kitchen'],
            [
                'uuid' => (string) Str::uuid(),
                'user_id' => $admin->id,
                'name' => 'Cedar Flame Kitchen',
                'description' => self::MARKER . ' Levantine-Mediterranean casual dining scenario dataset.',
                'address' => 'Badaro Street, Beirut, Lebanon',
            ]
        );

        if (! $restaurant->uuid) {
            $restaurant->uuid = (string) Str::uuid();
            $restaurant->save();
        }

        $restaurant->staffUsers()->syncWithoutDetaching([$staffCaptain->id, $staffServer->id]);
        $restaurant->ensureDefaultTables();

        $tablesByName = $restaurant->tables()->get()->keyBy('name');

        $staffCaptain->assignedTables()->sync([
            $tablesByName['T01']->id,
            $tablesByName['T02']->id,
            $tablesByName['T03']->id,
            $tablesByName['T04']->id,
        ]);

        $staffServer->assignedTables()->sync([
            $tablesByName['T05']->id,
            $tablesByName['T06']->id,
            $tablesByName['T07']->id,
            $tablesByName['T08']->id,
        ]);

        $ingredientsByCode = $this->seedIngredients($restaurant);
        $dishesByCode = $this->seedDishesAndRecipes($restaurant, $ingredientsByCode);

        $this->seedDishRelations($dishesByCode);

        $sessionsByCode = $this->seedTableSessions($restaurant, $tablesByName, $staffCaptain, $staffServer);
        $this->seedOrders($restaurant, $tablesByName, $sessionsByCode, $dishesByCode, $staffCaptain, $staffServer);
        $this->seedTableWaves($restaurant, $tablesByName, $sessionsByCode, $staffCaptain);

        $this->command?->info('RealRestaurantScenarioSeeder completed for cedar-flame-kitchen.');
    }

    /**
     * @return array<string, Ingredient>
     */
    private function seedIngredients(Restaurant $restaurant): array
    {
        $inventory = [
            ['code' => 'chickpeas', 'name' => 'Chickpeas', 'name_ar' => 'حمص', 'unit' => 'g', 'current' => 12000, 'threshold' => 2500],
            ['code' => 'tahini', 'name' => 'Tahini', 'name_ar' => 'طحينة', 'unit' => 'g', 'current' => 7000, 'threshold' => 1200],
            ['code' => 'olive-oil', 'name' => 'Olive Oil', 'name_ar' => 'زيت زيتون', 'unit' => 'ml', 'current' => 16000, 'threshold' => 3500],
            ['code' => 'lemon-juice', 'name' => 'Lemon Juice', 'name_ar' => 'عصير ليمون', 'unit' => 'ml', 'current' => 9000, 'threshold' => 2000],
            ['code' => 'garlic', 'name' => 'Garlic', 'name_ar' => 'ثوم', 'unit' => 'g', 'current' => 4200, 'threshold' => 900],
            ['code' => 'parsley', 'name' => 'Parsley', 'name_ar' => 'بقدونس', 'unit' => 'g', 'current' => 2500, 'threshold' => 500],
            ['code' => 'mint leaves', 'name' => 'Mint Leaves', 'name_ar' => 'نعناع', 'unit' => 'g', 'current' => 1300, 'threshold' => 250],
            ['code' => 'romaine lettuce', 'name' => 'Romaine Lettuce', 'name_ar' => 'خس روماني', 'unit' => 'g', 'current' => 6000, 'threshold' => 1200],
            ['code' => 'tomato', 'name' => 'Tomato', 'name_ar' => 'بندورة', 'unit' => 'g', 'current' => 9000, 'threshold' => 1800],
            ['code' => 'cucumber', 'name' => 'Cucumber', 'name_ar' => 'خيار', 'unit' => 'g', 'current' => 5500, 'threshold' => 1000],
            ['code' => 'radish', 'name' => 'Radish', 'name_ar' => 'فجل', 'unit' => 'g', 'current' => 2200, 'threshold' => 450],
            ['code' => 'sumac', 'name' => 'Sumac', 'name_ar' => 'سماق', 'unit' => 'g', 'current' => 1500, 'threshold' => 300],
            ['code' => 'pomegranate molasses', 'name' => 'Pomegranate Molasses', 'name_ar' => 'دبس رمان', 'unit' => 'ml', 'current' => 3200, 'threshold' => 650],
            ['code' => 'halloumi cheese', 'name' => 'Halloumi Cheese', 'name_ar' => 'جبنة حلوم', 'unit' => 'g', 'current' => 6500, 'threshold' => 1200],
            ['code' => 'chicken thigh', 'name' => 'Chicken Thigh', 'name_ar' => 'دجاج', 'unit' => 'g', 'current' => 18000, 'threshold' => 4000],
            ['code' => 'kafta', 'name' => 'Kafta', 'name_ar' => 'كفتة', 'unit' => 'g', 'current' => 14000, 'threshold' => 3000],
            ['code' => 'lamb chops', 'name' => 'Lamb Chops', 'name_ar' => 'ريش غنم', 'unit' => 'g', 'current' => 9000, 'threshold' => 2200],
            ['code' => 'basmati rice', 'name' => 'Basmati Rice', 'name_ar' => 'أرز بسمتي', 'unit' => 'g', 'current' => 24000, 'threshold' => 5000],
            ['code' => 'white fish fillet', 'name' => 'White Fish Fillet', 'name_ar' => 'فيليه سمك أبيض', 'unit' => 'g', 'current' => 10500, 'threshold' => 2400],
            ['code' => 'shrimp', 'name' => 'Shrimp', 'name_ar' => 'روبيان', 'unit' => 'g', 'current' => 1200, 'threshold' => 1700],
            ['code' => 'mushroom', 'name' => 'Mushroom', 'name_ar' => 'فطر', 'unit' => 'g', 'current' => 7200, 'threshold' => 1400],
            ['code' => 'parmesan', 'name' => 'Parmesan', 'name_ar' => 'بارميزان', 'unit' => 'g', 'current' => 5000, 'threshold' => 900],
            ['code' => 'pizza dough', 'name' => 'Pizza Dough', 'name_ar' => 'عجينة بيتزا', 'unit' => 'piece', 'current' => 110, 'threshold' => 25],
            ['code' => 'mozzarella', 'name' => 'Mozzarella', 'name_ar' => 'موزاريلا', 'unit' => 'g', 'current' => 14000, 'threshold' => 3000],
            ['code' => 'pizza sauce', 'name' => 'Pizza Sauce', 'name_ar' => 'صلصة بيتزا', 'unit' => 'g', 'current' => 10000, 'threshold' => 1800],
            ['code' => 'pepperoni', 'name' => 'Pepperoni', 'name_ar' => 'بيبروني', 'unit' => 'g', 'current' => 4800, 'threshold' => 900],
            ['code' => 'basil', 'name' => 'Basil', 'name_ar' => 'ريحان', 'unit' => 'g', 'current' => 1200, 'threshold' => 220],
            ['code' => 'kunafa', 'name' => 'Kunafa', 'name_ar' => 'كنافة', 'unit' => 'g', 'current' => 4500, 'threshold' => 900],
            ['code' => 'pistachio', 'name' => 'Pistachio', 'name_ar' => 'فستق', 'unit' => 'g', 'current' => 3200, 'threshold' => 700],
            ['code' => 'orange blossom syrup', 'name' => 'Orange Blossom Syrup', 'name_ar' => 'قطر ماء الزهر', 'unit' => 'ml', 'current' => 2800, 'threshold' => 600],
            ['code' => 'mascarpone', 'name' => 'Mascarpone', 'name_ar' => 'ماسكربوني', 'unit' => 'g', 'current' => 3400, 'threshold' => 700],
            ['code' => 'ladyfinger biscuits', 'name' => 'Ladyfinger Biscuits', 'name_ar' => 'بسكويت ليدي فنغر', 'unit' => 'g', 'current' => 2900, 'threshold' => 550],
            ['code' => 'espresso', 'name' => 'Espresso', 'name_ar' => 'إسبريسو', 'unit' => 'ml', 'current' => 6000, 'threshold' => 1200],
            ['code' => 'sparkling water', 'name' => 'Sparkling Water', 'name_ar' => 'مياه فوارة', 'unit' => 'ml', 'current' => 22000, 'threshold' => 4500],
            ['code' => 'pomegranate juice', 'name' => 'Pomegranate Juice', 'name_ar' => 'عصير رمان', 'unit' => 'ml', 'current' => 7500, 'threshold' => 1500],
            ['code' => 'sugar syrup', 'name' => 'Sugar Syrup', 'name_ar' => 'شراب سكري', 'unit' => 'ml', 'current' => 9000, 'threshold' => 1700],
            ['code' => 'flatbread', 'name' => 'Flatbread', 'name_ar' => 'خبز عربي', 'unit' => 'piece', 'current' => 160, 'threshold' => 35],
        ];

        $result = [];

        $existingIngredients = Ingredient::query()
            ->where('restaurant_id', $restaurant->id)
            ->get();

        if ($existingIngredients->isNotEmpty()) {
            $lookup = [];

            foreach ($existingIngredients as $ingredient) {
                $keys = [
                    $this->normalizeLooseLookup($ingredient->name),
                    $this->normalizeLooseLookup($ingredient->name_ar),
                ];

                foreach ($keys as $key) {
                    if ($key === '' || isset($lookup[$key])) {
                        continue;
                    }

                    $lookup[$key] = $ingredient;
                }
            }

            foreach ($inventory as $row) {
                $candidates = [
                    $this->normalizeLooseLookup($row['name']),
                    $this->normalizeLooseLookup($row['name_ar']),
                    $this->normalizeLooseLookup($row['code']),
                ];

                $matched = null;
                foreach ($candidates as $candidate) {
                    if ($candidate !== '' && isset($lookup[$candidate])) {
                        $matched = $lookup[$candidate];
                        break;
                    }
                }

                if (! $matched) {
                    $this->command?->warn(sprintf(
                        'RealRestaurantScenarioSeeder: ingredient "%s" was not found in existing restaurant inventory and was skipped.',
                        $row['name']
                    ));
                    continue;
                }

                $result[$row['code']] = $matched;
            }

            return $result;
        }

        foreach ($inventory as $row) {
            $ingredient = Ingredient::query()->firstOrNew([
                'restaurant_id' => $restaurant->id,
                'name' => $row['name'],
            ]);

            if (! $ingredient->exists) {
                $ingredient->uuid = (string) Str::uuid();
            }

            $globalIngredientId = GlobalIngredient::query()
                ->where('normalized_name', $this->normalizeIngredientName($row['name']))
                ->value('id');

            $ingredient->fill([
                'name_ar' => $row['name_ar'],
                'global_ingredient_id' => $globalIngredientId,
                'stock_unit' => $row['unit'],
                'current_stock_quantity' => $row['current'],
                'low_stock_threshold' => $row['threshold'],
                'is_active' => true,
            ]);

            $ingredient->save();
            $result[$row['code']] = $ingredient;
        }

        return $result;
    }

    /**
     * @param array<string, Ingredient> $ingredientsByCode
     * @return array<string, Dish>
     */
    private function seedDishesAndRecipes(Restaurant $restaurant, array $ingredientsByCode): array
    {
        $dishes = [
            [
                'code' => 'hummus-trio',
                'name' => 'Hummus Trio',
                'name_ar' => 'حمص تريو',
                'description' => 'Classic hummus, beet hummus, and avocado hummus served with warm flatbread.',
                'description_ar' => 'ثلاث أنواع حمص: كلاسيك، شمندر، وأفوكادو مع خبز عربي ساخن.',
                'category' => 'Appetizers',
                'category_ar' => 'مقبلات',
                'price' => 8.50,
                'calories' => 420,
                'recipe' => [
                    ['ingredient' => 'chickpeas', 'quantity' => 160, 'unit' => 'g'],
                    ['ingredient' => 'tahini', 'quantity' => 35, 'unit' => 'g'],
                    ['ingredient' => 'olive-oil', 'quantity' => 15, 'unit' => 'ml'],
                    ['ingredient' => 'lemon-juice', 'quantity' => 20, 'unit' => 'ml'],
                    ['ingredient' => 'garlic', 'quantity' => 6, 'unit' => 'g'],
                    ['ingredient' => 'flatbread', 'quantity' => 1, 'unit' => 'piece'],
                ],
            ],
            [
                'code' => 'fattoush-signature',
                'name' => 'Signature Fattoush',
                'name_ar' => 'فتوش سيغنتشر',
                'description' => 'Romaine, tomato, cucumber, radish, herbs, and pomegranate-sumac dressing.',
                'description_ar' => 'خس روماني، بندورة، خيار، فجل، أعشاب، وتتبيلة دبس الرمان والسماق.',
                'category' => 'Salads',
                'category_ar' => 'سلطات',
                'price' => 7.50,
                'calories' => 240,
                'recipe' => [
                    ['ingredient' => 'romaine lettuce', 'quantity' => 85, 'unit' => 'g'],
                    ['ingredient' => 'tomato', 'quantity' => 65, 'unit' => 'g'],
                    ['ingredient' => 'cucumber', 'quantity' => 60, 'unit' => 'g'],
                    ['ingredient' => 'radish', 'quantity' => 25, 'unit' => 'g'],
                    ['ingredient' => 'parsley', 'quantity' => 10, 'unit' => 'g'],
                    ['ingredient' => 'sumac', 'quantity' => 2, 'unit' => 'g'],
                    ['ingredient' => 'pomegranate molasses', 'quantity' => 12, 'unit' => 'ml'],
                    ['ingredient' => 'olive-oil', 'quantity' => 10, 'unit' => 'ml'],
                ],
            ],
            [
                'code' => 'grilled-halloumi',
                'name' => 'Grilled Halloumi',
                'name_ar' => 'حلوم مشوي',
                'description' => 'Char-grilled halloumi with lemon-mint oil and tomato relish.',
                'description_ar' => 'حلوم مشوي على الفحم مع زيت الليمون والنعناع ومربى بندورة خفيف.',
                'category' => 'Appetizers',
                'category_ar' => 'مقبلات',
                'price' => 9.00,
                'calories' => 380,
                'recipe' => [
                    ['ingredient' => 'halloumi cheese', 'quantity' => 120, 'unit' => 'g'],
                    ['ingredient' => 'olive-oil', 'quantity' => 9, 'unit' => 'ml'],
                    ['ingredient' => 'lemon-juice', 'quantity' => 8, 'unit' => 'ml'],
                    ['ingredient' => 'mint leaves', 'quantity' => 4, 'unit' => 'g'],
                    ['ingredient' => 'tomato', 'quantity' => 35, 'unit' => 'g'],
                ],
            ],
            [
                'code' => 'chicken-shawarma-plate',
                'name' => 'Chicken Shawarma Plate',
                'name_ar' => 'صحن شاورما دجاج',
                'description' => 'Marinated chicken thigh, garlic sauce, pickles, and basmati rice.',
                'description_ar' => 'دجاج متبّل، صلصة ثوم، مخلل، وأرز بسمتي.',
                'category' => 'Main Course',
                'category_ar' => 'طبق رئيسي',
                'price' => 14.50,
                'calories' => 760,
                'recipe' => [
                    ['ingredient' => 'chicken thigh', 'quantity' => 220, 'unit' => 'g'],
                    ['ingredient' => 'garlic', 'quantity' => 10, 'unit' => 'g'],
                    ['ingredient' => 'lemon-juice', 'quantity' => 12, 'unit' => 'ml'],
                    ['ingredient' => 'olive-oil', 'quantity' => 12, 'unit' => 'ml'],
                    ['ingredient' => 'basmati rice', 'quantity' => 140, 'unit' => 'g'],
                ],
            ],
            [
                'code' => 'beef-kafta-skewers',
                'name' => 'Beef Kafta Skewers',
                'name_ar' => 'أسياخ كفتة',
                'description' => 'Charcoal-grilled kafta skewers served with herb rice and tomato salsa.',
                'description_ar' => 'أسياخ كفتة مشوية على الفحم مع أرز بالأعشاب وسلطة بندورة.',
                'category' => 'Grills',
                'category_ar' => 'مشاوي',
                'price' => 16.00,
                'calories' => 820,
                'recipe' => [
                    ['ingredient' => 'kafta', 'quantity' => 240, 'unit' => 'g'],
                    ['ingredient' => 'parsley', 'quantity' => 8, 'unit' => 'g'],
                    ['ingredient' => 'tomato', 'quantity' => 60, 'unit' => 'g'],
                    ['ingredient' => 'olive-oil', 'quantity' => 10, 'unit' => 'ml'],
                    ['ingredient' => 'basmati rice', 'quantity' => 140, 'unit' => 'g'],
                ],
            ],
            [
                'code' => 'mixed-grill-platter',
                'name' => 'Mixed Grill Platter',
                'name_ar' => 'صحن مشاوي مشكل',
                'description' => 'Kafta, chicken, and lamb chops with grilled vegetables and rice.',
                'description_ar' => 'كفتة ودجاج وريش غنم مع خضار مشوية وأرز.',
                'category' => 'Grills',
                'category_ar' => 'مشاوي',
                'price' => 24.00,
                'calories' => 1080,
                'recipe' => [
                    ['ingredient' => 'kafta', 'quantity' => 120, 'unit' => 'g'],
                    ['ingredient' => 'chicken thigh', 'quantity' => 130, 'unit' => 'g'],
                    ['ingredient' => 'lamb chops', 'quantity' => 120, 'unit' => 'g'],
                    ['ingredient' => 'basmati rice', 'quantity' => 170, 'unit' => 'g'],
                ],
            ],
            [
                'code' => 'sayadieh-seafood',
                'name' => 'Seafood Sayadieh',
                'name_ar' => 'صيادية بحرية',
                'description' => 'Spiced rice with white fish, shrimp, caramelized onions, and tahini-citrus drizzle.',
                'description_ar' => 'أرز متبّل مع سمك أبيض وروبيان وبصل مكرمل ورشة طحينة حمضيات.',
                'category' => 'Rice Dishes',
                'category_ar' => 'أطباق الأرز',
                'price' => 21.00,
                'calories' => 890,
                'recipe' => [
                    ['ingredient' => 'white fish fillet', 'quantity' => 180, 'unit' => 'g'],
                    ['ingredient' => 'shrimp', 'quantity' => 90, 'unit' => 'g'],
                    ['ingredient' => 'basmati rice', 'quantity' => 165, 'unit' => 'g'],
                    ['ingredient' => 'tahini', 'quantity' => 18, 'unit' => 'g'],
                ],
            ],
            [
                'code' => 'truffle-risotto',
                'name' => 'Truffle Mushroom Risotto',
                'name_ar' => 'ريزوتو بالفطر والكمأة',
                'description' => 'Creamy risotto with sauteed mushrooms, parmesan, and truffle aroma.',
                'description_ar' => 'ريزوتو كريمي مع فطر سوتيه وبارميزان ونكهة الكمأة.',
                'category' => 'Pasta',
                'category_ar' => 'باستا',
                'price' => 17.50,
                'calories' => 740,
                'recipe' => [
                    ['ingredient' => 'basmati rice', 'quantity' => 150, 'unit' => 'g'],
                    ['ingredient' => 'mushroom', 'quantity' => 130, 'unit' => 'g'],
                    ['ingredient' => 'parmesan', 'quantity' => 35, 'unit' => 'g'],
                    ['ingredient' => 'olive-oil', 'quantity' => 10, 'unit' => 'ml'],
                ],
            ],
            [
                'code' => 'margherita-pizza',
                'name' => 'Margherita Pizza',
                'name_ar' => 'بيتزا مارغريتا',
                'description' => 'Stone-baked pizza with tomato sauce, mozzarella, and basil.',
                'description_ar' => 'بيتزا مخبوزة بالحجر مع صلصة الطماطم والموزاريلا والريحان.',
                'category' => 'Pizza',
                'category_ar' => 'بيتزا',
                'price' => 12.00,
                'calories' => 830,
                'recipe' => [
                    ['ingredient' => 'pizza dough', 'quantity' => 1, 'unit' => 'piece'],
                    ['ingredient' => 'pizza sauce', 'quantity' => 90, 'unit' => 'g'],
                    ['ingredient' => 'mozzarella', 'quantity' => 130, 'unit' => 'g'],
                    ['ingredient' => 'basil', 'quantity' => 5, 'unit' => 'g'],
                ],
            ],
            [
                'code' => 'pepperoni-pizza',
                'name' => 'Pepperoni Pizza',
                'name_ar' => 'بيتزا بيبروني',
                'description' => 'Stone-baked pizza with mozzarella and pepperoni.',
                'description_ar' => 'بيتزا مخبوزة بالحجر مع موزاريلا وبيبروني.',
                'category' => 'Pizza',
                'category_ar' => 'بيتزا',
                'price' => 13.50,
                'calories' => 980,
                'recipe' => [
                    ['ingredient' => 'pizza dough', 'quantity' => 1, 'unit' => 'piece'],
                    ['ingredient' => 'pizza sauce', 'quantity' => 95, 'unit' => 'g'],
                    ['ingredient' => 'mozzarella', 'quantity' => 130, 'unit' => 'g'],
                    ['ingredient' => 'pepperoni', 'quantity' => 70, 'unit' => 'g'],
                ],
            ],
            [
                'code' => 'pistachio-kunafa',
                'name' => 'Pistachio Kunafa',
                'name_ar' => 'كنافة بالفستق',
                'description' => 'Warm kunafa with pistachio crumble and orange blossom syrup.',
                'description_ar' => 'كنافة ساخنة مع فستق مطحون وقطر ماء الزهر.',
                'category' => 'Desserts',
                'category_ar' => 'حلويات',
                'price' => 8.00,
                'calories' => 510,
                'recipe' => [
                    ['ingredient' => 'kunafa', 'quantity' => 95, 'unit' => 'g'],
                    ['ingredient' => 'pistachio', 'quantity' => 22, 'unit' => 'g'],
                    ['ingredient' => 'mozzarella', 'quantity' => 70, 'unit' => 'g'],
                    ['ingredient' => 'orange blossom syrup', 'quantity' => 28, 'unit' => 'ml'],
                ],
            ],
            [
                'code' => 'mint-lemonade',
                'name' => 'Mint Lemonade',
                'name_ar' => 'ليموناضة بالنعناع',
                'description' => 'Fresh lemon, mint, and light syrup over ice.',
                'description_ar' => 'ليمون طازج ونعناع وشراب خفيف مع الثلج.',
                'category' => 'Mocktails',
                'category_ar' => 'موكتيل',
                'price' => 4.50,
                'calories' => 130,
                'recipe' => [
                    ['ingredient' => 'lemon-juice', 'quantity' => 45, 'unit' => 'ml'],
                    ['ingredient' => 'mint leaves', 'quantity' => 6, 'unit' => 'g'],
                    ['ingredient' => 'sugar syrup', 'quantity' => 18, 'unit' => 'ml'],
                    ['ingredient' => 'sparkling water', 'quantity' => 120, 'unit' => 'ml'],
                ],
            ],
            [
                'code' => 'pomegranate-spritz',
                'name' => 'Pomegranate Spritz',
                'name_ar' => 'سبريتز الرمان',
                'description' => 'Pomegranate juice, sparkling water, mint, and citrus zest.',
                'description_ar' => 'عصير رمان مع مياه فوارة ونعناع وبرش حمضيات.',
                'category' => 'Mocktails',
                'category_ar' => 'موكتيل',
                'price' => 5.00,
                'calories' => 120,
                'recipe' => [
                    ['ingredient' => 'pomegranate juice', 'quantity' => 80, 'unit' => 'ml'],
                    ['ingredient' => 'sparkling water', 'quantity' => 100, 'unit' => 'ml'],
                    ['ingredient' => 'mint leaves', 'quantity' => 5, 'unit' => 'g'],
                    ['ingredient' => 'sugar syrup', 'quantity' => 10, 'unit' => 'ml'],
                ],
            ],
        ];

        $result = [];

        foreach ($dishes as $row) {
            $dish = Dish::query()->firstOrNew([
                'restaurant_id' => $restaurant->id,
                'name' => $row['name'],
            ]);

            if (! $dish->exists) {
                $dish->uuid = (string) Str::uuid();
            }

            $dish->fill([
                'name_ar' => $row['name_ar'],
                'description' => self::MARKER . ' ' . $row['description'],
                'description_ar' => $row['description_ar'],
                'price' => $row['price'],
                'calories' => $row['calories'],
                'category' => $row['category'],
                'category_ar' => $row['category_ar'],
                'status' => 'published',
            ]);

            $dish->save();

            $recipeIngredientIds = [];

            foreach ($row['recipe'] as $recipe) {
                $ingredient = $ingredientsByCode[$recipe['ingredient']] ?? null;
                if (! $ingredient) {
                    continue;
                }

                DishIngredient::query()->updateOrCreate(
                    [
                        'dish_id' => $dish->id,
                        'ingredient_id' => $ingredient->id,
                    ],
                    [
                        'quantity' => $recipe['quantity'],
                        'unit' => $recipe['unit'],
                    ]
                );

                $recipeIngredientIds[] = $ingredient->id;
            }

            if ($recipeIngredientIds !== []) {
                DishIngredient::query()
                    ->where('dish_id', $dish->id)
                    ->whereNotIn('ingredient_id', $recipeIngredientIds)
                    ->delete();
            }

            $result[$row['code']] = $dish;
        }

        return $result;
    }

    /**
     * @param array<string, Dish> $dishesByCode
     */
    private function seedDishRelations(array $dishesByCode): void
    {
        $suggestions = [
            'hummus-trio' => ['mint-lemonade', 'fattoush-signature'],
            'chicken-shawarma-plate' => ['fattoush-signature', 'mint-lemonade'],
            'beef-kafta-skewers' => ['hummus-trio', 'pomegranate-spritz'],
            'mixed-grill-platter' => ['fattoush-signature', 'pomegranate-spritz'],
            'pistachio-kunafa' => ['pomegranate-spritz'],
        ];

        $related = [
            'margherita-pizza' => ['pepperoni-pizza', 'truffle-risotto'],
            'pepperoni-pizza' => ['margherita-pizza', 'mixed-grill-platter'],
            'sayadieh-seafood' => ['truffle-risotto', 'grilled-halloumi'],
            'hummus-trio' => ['grilled-halloumi', 'fattoush-signature'],
        ];

        foreach ($suggestions as $dishCode => $linkedCodes) {
            $dish = $dishesByCode[$dishCode] ?? null;
            if (! $dish) {
                continue;
            }

            $ids = collect($linkedCodes)
                ->map(fn (string $code) => $dishesByCode[$code]->id ?? null)
                ->filter()
                ->values()
                ->all();

            $dish->suggestedDishes()->sync($ids);
        }

        foreach ($related as $dishCode => $linkedCodes) {
            $dish = $dishesByCode[$dishCode] ?? null;
            if (! $dish) {
                continue;
            }

            $ids = collect($linkedCodes)
                ->map(fn (string $code) => $dishesByCode[$code]->id ?? null)
                ->filter()
                ->values()
                ->all();

            $dish->relatedDishes()->sync($ids);
        }
    }

    /**
     * @param \Illuminate\Support\Collection<string, \App\Models\RestaurantTable> $tablesByName
     * @return array<string, TableSession>
     */
    private function seedTableSessions(Restaurant $restaurant, $tablesByName, User $staffCaptain, User $staffServer): array
    {
        $sessionSeeds = [
            [
                'code' => 'lunch-active',
                'uuid' => 'e4f8b8df-0ceb-4f35-af93-e8c40ebba001',
                'table' => 'T01',
                'status' => TableSession::STATUS_ACTIVE,
                'pin' => '1111',
                'opened_at' => '2026-04-19 12:05:00',
                'last_activity_at' => '2026-04-19 12:33:00',
                'expires_at' => '2026-04-19 14:05:00',
                'created_by' => $staffCaptain->id,
            ],
            [
                'code' => 'rush-active',
                'uuid' => 'e4f8b8df-0ceb-4f35-af93-e8c40ebba002',
                'table' => 'T03',
                'status' => TableSession::STATUS_ACTIVE,
                'pin' => '2222',
                'opened_at' => '2026-04-19 19:10:00',
                'last_activity_at' => '2026-04-19 19:58:00',
                'expires_at' => '2026-04-19 21:10:00',
                'created_by' => $staffCaptain->id,
            ],
            [
                'code' => 'closed-billed',
                'uuid' => 'e4f8b8df-0ceb-4f35-af93-e8c40ebba003',
                'table' => 'T05',
                'status' => TableSession::STATUS_CLOSED,
                'pin' => '3333',
                'opened_at' => '2026-04-19 20:05:00',
                'last_activity_at' => '2026-04-19 21:12:00',
                'expires_at' => '2026-04-19 22:05:00',
                'closed_at' => '2026-04-19 21:15:00',
                'close_reason' => 'bill_requested',
                'created_by' => $staffServer->id,
                'finalized_by' => $staffCaptain->id,
            ],
            [
                'code' => 'security-suspended',
                'uuid' => 'e4f8b8df-0ceb-4f35-af93-e8c40ebba004',
                'table' => 'T07',
                'status' => TableSession::STATUS_SUSPENDED,
                'pin' => '4444',
                'opened_at' => '2026-04-19 22:10:00',
                'last_activity_at' => '2026-04-19 22:14:00',
                'expires_at' => '2026-04-19 23:10:00',
                'close_reason' => 'security_review',
                'created_by' => $staffServer->id,
            ],
        ];

        $result = [];

        foreach ($sessionSeeds as $seed) {
            $table = $tablesByName[$seed['table']] ?? null;
            if (! $table) {
                continue;
            }

            $session = TableSession::query()->firstOrNew([
                'uuid' => $seed['uuid'],
            ]);

            $session->fill([
                'restaurant_id' => $restaurant->id,
                'restaurant_table_id' => $table->id,
                'table_number' => (int) ltrim($seed['table'], 'T0'),
                'status' => $seed['status'],
                'pin_hash' => Hash::make($seed['pin']),
                'pin_attempts' => 0,
                'opened_at' => Carbon::parse($seed['opened_at']),
                'last_activity_at' => Carbon::parse($seed['last_activity_at']),
                'expires_at' => Carbon::parse($seed['expires_at']),
                'closed_at' => isset($seed['closed_at']) ? Carbon::parse($seed['closed_at']) : null,
                'close_reason' => $seed['close_reason'] ?? null,
                'created_by_staff_id' => $seed['created_by'] ?? null,
                'finalized_by_staff_id' => $seed['finalized_by'] ?? null,
            ]);

            $session->save();
            $result[$seed['code']] = $session;
        }

        return $result;
    }

    /**
     * @param \Illuminate\Support\Collection<string, \App\Models\RestaurantTable> $tablesByName
     * @param array<string, TableSession> $sessionsByCode
     * @param array<string, Dish> $dishesByCode
     */
    private function seedOrders(
        Restaurant $restaurant,
        $tablesByName,
        array $sessionsByCode,
        array $dishesByCode,
        User $staffCaptain,
        User $staffServer
    ): void {
        $orders = [
            [
                'order_number' => 'CFK-20260419-001',
                'guest_name' => 'Lina H.',
                'table' => 'T01',
                'session' => 'lunch-active',
                'status' => Order::STATUS_PENDING_STAFF_CONFIRMATION,
                'notes' => 'No onions for one guest.',
                'vat_rate' => 11,
                'items' => [
                    ['dish' => 'hummus-trio', 'quantity' => 1],
                    ['dish' => 'chicken-shawarma-plate', 'quantity' => 2],
                    ['dish' => 'mint-lemonade', 'quantity' => 2],
                ],
            ],
            [
                'order_number' => 'CFK-20260419-002',
                'guest_name' => 'Nour A.',
                'table' => 'T03',
                'session' => 'rush-active',
                'status' => Order::STATUS_STAFF_CONFIRMED,
                'notes' => 'Serve kunafa after mains.',
                'vat_rate' => 11,
                'confirmed_by' => $staffCaptain->id,
                'confirmed_at' => '2026-04-19 19:41:00',
                'items' => [
                    ['dish' => 'mixed-grill-platter', 'quantity' => 1],
                    ['dish' => 'fattoush-signature', 'quantity' => 1],
                    ['dish' => 'pomegranate-spritz', 'quantity' => 2],
                ],
            ],
            [
                'order_number' => 'CFK-20260419-003',
                'invoice_number' => 'INV-CFK-20260419-003',
                'guest_name' => 'Rami D.',
                'table' => 'T05',
                'session' => 'closed-billed',
                'status' => Order::STATUS_ACCOUNTED,
                'notes' => 'Birthday table, apply 10% goodwill discount.',
                'vat_rate' => 11,
                'discount_type' => 'percentage',
                'discount_value' => 10,
                'confirmed_by' => $staffServer->id,
                'confirmed_at' => '2026-04-19 20:44:00',
                'accounted_by' => $staffCaptain->id,
                'accounted_at' => '2026-04-19 21:08:00',
                'items' => [
                    ['dish' => 'sayadieh-seafood', 'quantity' => 1],
                    ['dish' => 'truffle-risotto', 'quantity' => 1],
                    ['dish' => 'pistachio-kunafa', 'quantity' => 1],
                ],
            ],
            [
                'order_number' => 'CFK-20260419-004',
                'guest_name' => 'Maya S.',
                'table' => 'T07',
                'session' => 'security-suspended',
                'status' => Order::STATUS_STAFF_CANCELLED,
                'notes' => 'Order cancelled due to access suspension.',
                'vat_rate' => 11,
                'cancelled_by' => $staffCaptain->id,
                'cancelled_at' => '2026-04-19 22:16:00',
                'items' => [
                    ['dish' => 'pepperoni-pizza', 'quantity' => 1],
                    ['dish' => 'mint-lemonade', 'quantity' => 1],
                ],
            ],
        ];

        foreach ($orders as $row) {
            $table = $tablesByName[$row['table']] ?? null;
            $session = $sessionsByCode[$row['session']] ?? null;

            if (! $table) {
                continue;
            }

            $lineItems = collect($row['items'])->map(function (array $item) use ($dishesByCode) {
                $dish = $dishesByCode[$item['dish']] ?? null;
                if (! $dish) {
                    return null;
                }

                $unitPrice = (float) $dish->price;
                $quantity = (int) $item['quantity'];

                return [
                    'dish' => $dish,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_subtotal' => round($unitPrice * $quantity, 2),
                ];
            })->filter()->values();

            $subtotal = (float) $lineItems->sum('line_subtotal');
            $discountType = $row['discount_type'] ?? null;
            $discountValue = (float) ($row['discount_value'] ?? 0);

            $discountAmount = match ($discountType) {
                'percentage' => round($subtotal * ($discountValue / 100), 2),
                'fixed' => min(round($discountValue, 2), $subtotal),
                default => 0.0,
            };

            $taxableSubtotal = max(0, round($subtotal - $discountAmount, 2));
            $vatRate = (float) ($row['vat_rate'] ?? 0);
            $vatAmount = round($taxableSubtotal * ($vatRate / 100), 2);
            $total = round($taxableSubtotal + $vatAmount, 2);

            $order = Order::query()->firstOrNew([
                'order_number' => $row['order_number'],
            ]);

            if (! $order->exists) {
                $order->uuid = (string) Str::uuid();
            }

            $order->fill([
                'restaurant_id' => $restaurant->id,
                'restaurant_table_id' => $table->id,
                'table_session_id' => $session?->id,
                'invoice_number' => $row['invoice_number'] ?? null,
                'status' => $row['status'],
                'guest_name' => $row['guest_name'],
                'guest_phone' => null,
                'guest_email' => null,
                'table_reference' => $row['table'],
                'notes' => self::MARKER . ' ' . $row['notes'],
                'vat_rate' => $vatRate,
                'subtotal' => $subtotal,
                'discount_type' => $discountType,
                'discount_value' => $discountValue,
                'discount_amount' => $discountAmount,
                'taxable_subtotal' => $taxableSubtotal,
                'vat_amount' => $vatAmount,
                'total' => $total,
                'confirmed_by' => $row['confirmed_by'] ?? null,
                'confirmed_at' => isset($row['confirmed_at']) ? Carbon::parse($row['confirmed_at']) : null,
                'cancelled_by' => $row['cancelled_by'] ?? null,
                'cancelled_at' => isset($row['cancelled_at']) ? Carbon::parse($row['cancelled_at']) : null,
                'accounted_by' => $row['accounted_by'] ?? null,
                'accounted_at' => isset($row['accounted_at']) ? Carbon::parse($row['accounted_at']) : null,
            ]);

            $order->save();

            $order->items()->delete();
            foreach ($lineItems as $lineItem) {
                $order->items()->create([
                    'dish_id' => $lineItem['dish']->id,
                    'dish_name' => $lineItem['dish']->name,
                    'unit_price' => $lineItem['unit_price'],
                    'quantity' => $lineItem['quantity'],
                    'line_subtotal' => $lineItem['line_subtotal'],
                ]);
            }
        }
    }

    /**
     * @param \Illuminate\Support\Collection<string, \App\Models\RestaurantTable> $tablesByName
     * @param array<string, TableSession> $sessionsByCode
     */
    private function seedTableWaves(Restaurant $restaurant, $tablesByName, array $sessionsByCode, User $staffCaptain): void
    {
        $waves = [
            [
                'uuid' => '7f5a5798-a49d-49d5-84c4-34d7baf7d001',
                'table' => 'T03',
                'session' => 'rush-active',
                'status' => TableWave::STATUS_PENDING,
                'request_type' => TableWave::REQUEST_TYPE_CALL_WAITER,
            ],
            [
                'uuid' => '7f5a5798-a49d-49d5-84c4-34d7baf7d002',
                'table' => 'T05',
                'session' => 'closed-billed',
                'status' => TableWave::STATUS_RESOLVED,
                'request_type' => TableWave::REQUEST_TYPE_REQUEST_BILL,
                'resolved_at' => '2026-04-19 21:06:00',
                'resolved_by' => $staffCaptain->id,
            ],
        ];

        foreach ($waves as $row) {
            $table = $tablesByName[$row['table']] ?? null;
            if (! $table) {
                continue;
            }

            $wave = TableWave::query()->firstOrNew(['uuid' => $row['uuid']]);
            $wave->fill([
                'restaurant_id' => $restaurant->id,
                'restaurant_table_id' => $table->id,
                'table_session_id' => $sessionsByCode[$row['session']]->id ?? null,
                'status' => $row['status'],
                'request_type' => $row['request_type'],
                'table_reference' => $row['table'],
                'resolved_by' => $row['resolved_by'] ?? null,
                'resolved_at' => isset($row['resolved_at']) ? Carbon::parse($row['resolved_at']) : null,
            ]);
            $wave->save();
        }
    }


    private function normalizeLooseLookup(?string $value): string
    {
        if (! $value) {
            return '';
        }

        $normalized = mb_strtolower(trim($value));
        $normalized = str_replace(['-', '_'], ' ', $normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    private function normalizeIngredientName(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = str_replace('&', 'and', $normalized);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    }
}
