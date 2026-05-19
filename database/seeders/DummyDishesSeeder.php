<?php

namespace Database\Seeders;

use App\Models\Dish;
use App\Models\DishIngredient;
use App\Models\Ingredient;
use App\Models\Restaurant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DummyDishesSeeder extends Seeder
{
    private const DISH_COUNT = 200;
    private const DESCRIPTION_MARKER = '[dummy-dishes-seeder]';

    private const CATEGORY_IMAGE_URLS = [
        'Pizza' => [
            'https://images.unsplash.com/photo-1565299624946-b28f40a0ae38?auto=format&fit=crop&w=1200&q=80',
            'https://images.unsplash.com/photo-1513104890138-7c749659a591?auto=format&fit=crop&w=1200&q=80',
            'https://images.unsplash.com/photo-1548365328-9f547fb0953b?auto=format&fit=crop&w=1200&q=80',
        ],
        'Specialty Pizza' => [
            'https://images.unsplash.com/photo-1513104890138-7c749659a591?auto=format&fit=crop&w=1200&q=80',
            'https://images.unsplash.com/photo-1565299624946-b28f40a0ae38?auto=format&fit=crop&w=1200&q=80',
            'https://images.unsplash.com/photo-1548365328-9f547fb0953b?auto=format&fit=crop&w=1200&q=80',
        ],
        'Burgers' => [
            'https://images.unsplash.com/photo-1550547660-d9450f859349?auto=format&fit=crop&w=1200&q=80',
            'https://images.unsplash.com/photo-1568901346375-23c9450c58cd?auto=format&fit=crop&w=1200&q=80',
            'https://images.unsplash.com/photo-1553979459-d2229ba7433b?auto=format&fit=crop&w=1200&q=80',
        ],
        'Sandwiches' => [
            'https://images.unsplash.com/photo-1528735602780-2552fd46c7af?auto=format&fit=crop&w=1200&q=80',
            'https://images.unsplash.com/photo-1550507992-eb63ffee0847?auto=format&fit=crop&w=1200&q=80',
            'https://images.unsplash.com/photo-1481070414801-51fd732d7184?auto=format&fit=crop&w=1200&q=80',
        ],
        'Pasta' => [
            'https://images.unsplash.com/photo-1473093295043-cdd812d0e601?auto=format&fit=crop&w=1200&q=80',
            'https://images.unsplash.com/photo-1603133872878-684f208fb84b?auto=format&fit=crop&w=1200&q=80',
            'https://images.unsplash.com/photo-1621996346565-e3dbc353d2e5?auto=format&fit=crop&w=1200&q=80',
        ],
        'Salads' => [
            'https://images.unsplash.com/photo-1540189549336-e6e99c3679fe?auto=format&fit=crop&w=1200&q=80',
            'https://images.unsplash.com/photo-1512621776951-a57141f2eefd?auto=format&fit=crop&w=1200&q=80',
            'https://images.unsplash.com/photo-1511690743698-d9d85f2fbf38?auto=format&fit=crop&w=1200&q=80',
        ],
        'Appetizers' => [
            'https://images.unsplash.com/photo-1521389508051-d7ffb5dc8f70?auto=format&fit=crop&w=1200&q=80',
            'https://images.unsplash.com/photo-1571091718767-18b5b1457add?auto=format&fit=crop&w=1200&q=80',
            'https://images.unsplash.com/photo-1604908176997-4318c48b3a6b?auto=format&fit=crop&w=1200&q=80',
        ],
        'Sides' => [
            'https://images.unsplash.com/photo-1576107232684-1279f390859f?auto=format&fit=crop&w=1200&q=80',
            'https://images.unsplash.com/photo-1630384060421-cb20d0e0649d?auto=format&fit=crop&w=1200&q=80',
            'https://images.unsplash.com/photo-1585238342024-78d387f4a707?auto=format&fit=crop&w=1200&q=80',
        ],
        'Desserts' => [
            'https://images.unsplash.com/photo-1563805042-7684c019e1cb?auto=format&fit=crop&w=1200&q=80',
            'https://images.unsplash.com/photo-1505253213348-cd54c92b37c6?auto=format&fit=crop&w=1200&q=80',
            'https://images.unsplash.com/photo-1499636136210-6f4ee915583e?auto=format&fit=crop&w=1200&q=80',
        ],
        'Drinks' => [
            'https://images.unsplash.com/photo-1497534446932-c925b458314e?auto=format&fit=crop&w=1200&q=80',
            'https://images.unsplash.com/photo-1551024506-0bccd828d307?auto=format&fit=crop&w=1200&q=80',
            'https://images.unsplash.com/photo-1509042239860-f550ce710b93?auto=format&fit=crop&w=1200&q=80',
        ],
    ];

    public function run(): void
    {
        $removed = $this->purgeExistingDummyDishes();
        $removedOld = $this->purgeExistingNonDummyDishes();

        $restaurants = Restaurant::query()
            ->orderBy('id')
            ->get();

        if ($restaurants->isEmpty()) {
            $this->command?->warn('No restaurants found. Create at least one restaurant before seeding dummy dishes.');
            return;
        }

        $categoryRecipes = $this->categoryRecipes();
        $categories = array_keys($categoryRecipes);

        $created = 0;

        for ($index = 0; $index < self::DISH_COUNT; $index++) {
            $restaurant = $restaurants[$index % $restaurants->count()];
            $category = $categories[$index % count($categories)];
            $recipes = $categoryRecipes[$category];
            $recipe = $recipes[$index % count($recipes)];

            Dish::create([
                'uuid' => (string) Str::uuid(),
                'restaurant_id' => $restaurant->id,
                'name' => $recipe['name'],
                'description' => $this->buildDescription(
                    $index + 1,
                    $restaurant->name,
                    $category,
                    $recipe['ingredients']
                ),
                'price' => $this->priceForCategory($category, $index),
                'calories' => $this->caloriesForRecipe($recipe['ingredients'], $category, $index),
                'category' => $category,
                'status' => 'published',
                'image_url' => $this->imageForCategory($category, $index, $recipe['name'], $restaurant->id),
            ]);

            $created++;
        }

        $this->attachDishLinks($restaurants);
        $outOfStock = $this->markSomeDishesOutOfStock($restaurants);

        $this->command?->info(sprintf(
            'Removed %d previous dummy dishes and %d old non-dummy dishes, created %d new dummy dishes, and marked %d as out of stock.',
            $removed,
            $removedOld,
            $created,
            $outOfStock
        ));
    }

    public static function descriptionMarker(): string
    {
        return self::DESCRIPTION_MARKER;
    }

    private function categoryRecipes(): array
    {
        return [
            'Pizza' => [
                [
                    'name' => 'Margherita Pizza',
                    'ingredients' => ['pizza dough', 'tomato sauce', 'mozzarella', 'fresh basil', 'olive oil'],
                ],
                [
                    'name' => 'Pepperoni Pizza',
                    'ingredients' => ['pizza dough', 'tomato sauce', 'mozzarella', 'pepperoni', 'oregano'],
                ],
                [
                    'name' => 'Vegetarian Pizza',
                    'ingredients' => ['pizza dough', 'tomato sauce', 'mozzarella', 'mushrooms', 'bell peppers', 'olives', 'red onions'],
                ],
                [
                    'name' => 'Four Cheese Pizza',
                    'ingredients' => ['pizza dough', 'mozzarella', 'parmesan', 'gorgonzola', 'cheddar', 'olive oil'],
                ],
            ],
            'Specialty Pizza' => [
                [
                    'name' => 'BBQ Chicken Pizza',
                    'ingredients' => ['pizza dough', 'bbq sauce', 'mozzarella', 'grilled chicken', 'red onions', 'cilantro'],
                ],
                [
                    'name' => 'Buffalo Chicken Pizza',
                    'ingredients' => ['pizza dough', 'buffalo sauce', 'mozzarella', 'chicken', 'red onions', 'ranch drizzle'],
                ],
                [
                    'name' => 'Truffle Mushroom Pizza',
                    'ingredients' => ['pizza dough', 'cream sauce', 'mozzarella', 'mushrooms', 'truffle oil', 'parmesan'],
                ],
                [
                    'name' => 'Meat Lovers Pizza',
                    'ingredients' => ['pizza dough', 'tomato sauce', 'mozzarella', 'pepperoni', 'sausage', 'beef bacon'],
                ],
            ],
            'Burgers' => [
                [
                    'name' => 'Classic Beef Burger',
                    'ingredients' => ['beef patty', 'burger bun', 'lettuce', 'tomato', 'pickles', 'cheddar', 'burger sauce'],
                ],
                [
                    'name' => 'Mushroom Swiss Burger',
                    'ingredients' => ['beef patty', 'burger bun', 'mushrooms', 'swiss cheese', 'caramelized onions', 'mayonnaise'],
                ],
                [
                    'name' => 'Spicy Jalapeño Burger',
                    'ingredients' => ['beef patty', 'burger bun', 'jalapeños', 'pepper jack cheese', 'lettuce', 'spicy mayo'],
                ],
                [
                    'name' => 'Crispy Chicken Burger',
                    'ingredients' => ['fried chicken fillet', 'burger bun', 'lettuce', 'pickles', 'cheddar', 'garlic mayo'],
                ],
            ],
            'Sandwiches' => [
                [
                    'name' => 'Grilled Chicken Sandwich',
                    'ingredients' => ['grilled chicken', 'ciabatta bread', 'lettuce', 'tomato', 'garlic aioli'],
                ],
                [
                    'name' => 'Turkey Club Sandwich',
                    'ingredients' => ['turkey', 'toast bread', 'beef bacon', 'lettuce', 'tomato', 'mayonnaise'],
                ],
                [
                    'name' => 'Philly Cheesesteak',
                    'ingredients' => ['beef strips', 'hoagie roll', 'onions', 'bell peppers', 'provolone'],
                ],
                [
                    'name' => 'Tuna Melt Sandwich',
                    'ingredients' => ['tuna', 'toast bread', 'cheddar', 'tomato', 'mayonnaise'],
                ],
            ],
            'Pasta' => [
                [
                    'name' => 'Chicken Alfredo Pasta',
                    'ingredients' => ['fettuccine', 'grilled chicken', 'cream', 'parmesan', 'garlic', 'butter'],
                ],
                [
                    'name' => 'Spaghetti Bolognese',
                    'ingredients' => ['spaghetti', 'ground beef', 'tomato sauce', 'garlic', 'onions', 'parmesan'],
                ],
                [
                    'name' => 'Pesto Penne Pasta',
                    'ingredients' => ['penne', 'basil pesto', 'parmesan', 'cherry tomatoes', 'olive oil'],
                ],
                [
                    'name' => 'Shrimp Arrabbiata',
                    'ingredients' => ['penne', 'shrimp', 'tomato sauce', 'garlic', 'chili flakes', 'parsley'],
                ],
            ],
            'Salads' => [
                [
                    'name' => 'Caesar Salad',
                    'ingredients' => ['romaine lettuce', 'parmesan', 'croutons', 'caesar dressing'],
                ],
                [
                    'name' => 'Greek Salad',
                    'ingredients' => ['lettuce', 'cucumber', 'tomato', 'feta', 'olives', 'red onions', 'olive oil'],
                ],
                [
                    'name' => 'Grilled Chicken Salad',
                    'ingredients' => ['mixed greens', 'grilled chicken', 'cucumber', 'tomato', 'corn', 'vinaigrette'],
                ],
                [
                    'name' => 'Avocado Quinoa Salad',
                    'ingredients' => ['quinoa', 'avocado', 'mixed greens', 'cherry tomatoes', 'lemon dressing'],
                ],
            ],
            'Appetizers' => [
                [
                    'name' => 'Mozzarella Sticks',
                    'ingredients' => ['mozzarella', 'breadcrumbs', 'eggs', 'flour', 'marinara sauce'],
                ],
                [
                    'name' => 'Chicken Wings',
                    'ingredients' => ['chicken wings', 'buffalo sauce', 'garlic', 'butter'],
                ],
                [
                    'name' => 'Loaded Nachos',
                    'ingredients' => ['tortilla chips', 'cheddar', 'jalapeños', 'salsa', 'guacamole', 'sour cream'],
                ],
                [
                    'name' => 'Garlic Bread',
                    'ingredients' => ['baguette', 'garlic butter', 'parsley', 'mozzarella'],
                ],
            ],
            'Sides' => [
                [
                    'name' => 'French Fries',
                    'ingredients' => ['potatoes', 'salt', 'vegetable oil'],
                ],
                [
                    'name' => 'Cheesy Fries',
                    'ingredients' => ['potatoes', 'cheddar sauce', 'parsley'],
                ],
                [
                    'name' => 'Onion Rings',
                    'ingredients' => ['onions', 'breadcrumbs', 'flour', 'eggs', 'oil'],
                ],
                [
                    'name' => 'Coleslaw',
                    'ingredients' => ['cabbage', 'carrots', 'mayonnaise', 'vinegar', 'sugar'],
                ],
            ],
            'Desserts' => [
                [
                    'name' => 'Chocolate Lava Cake',
                    'ingredients' => ['dark chocolate', 'butter', 'eggs', 'sugar', 'flour'],
                ],
                [
                    'name' => 'New York Cheesecake',
                    'ingredients' => ['cream cheese', 'eggs', 'sugar', 'biscuits', 'butter'],
                ],
                [
                    'name' => 'Tiramisu',
                    'ingredients' => ['mascarpone', 'ladyfingers', 'espresso', 'cocoa powder', 'cream'],
                ],
                [
                    'name' => 'Brownie Sundae',
                    'ingredients' => ['brownie', 'vanilla ice cream', 'chocolate sauce', 'nuts'],
                ],
            ],
            'Drinks' => [
                [
                    'name' => 'Fresh Lemon Mint',
                    'ingredients' => ['lemon juice', 'mint', 'sugar syrup', 'ice water'],
                ],
                [
                    'name' => 'Iced Coffee',
                    'ingredients' => ['espresso', 'milk', 'ice', 'sugar syrup'],
                ],
                [
                    'name' => 'Strawberry Milkshake',
                    'ingredients' => ['strawberries', 'milk', 'vanilla ice cream', 'sugar'],
                ],
                [
                    'name' => 'Mango Smoothie',
                    'ingredients' => ['mango', 'yogurt', 'milk', 'honey', 'ice'],
                ],
            ],
        ];
    }

    private function buildDescription(int $sequence, string $restaurantName, string $category, array $ingredients): string
    {
        return sprintf(
            '%s Seeded sample dish %d for %s in the %s category. Prepared with %s.',
            self::DESCRIPTION_MARKER,
            $sequence,
            $restaurantName,
            $category,
            implode(', ', $ingredients)
        );
    }

    private function purgeExistingDummyDishes(): int
    {
        $query = Dish::query()
            ->where('description', 'like', self::DESCRIPTION_MARKER . '%');

        $count = (int) $query->count();

        if ($count === 0) {
            return 0;
        }

        $query->delete();

        return $count;
    }

    private function purgeExistingNonDummyDishes(): int
    {
        $query = Dish::query()
            ->where(function ($builder): void {
                $builder
                    ->whereNull('description')
                    ->orWhere('description', 'not like', self::DESCRIPTION_MARKER . '%');
            });

        $count = (int) $query->count();

        if ($count === 0) {
            return 0;
        }

        $query->delete();

        return $count;
    }

    private function attachDishLinks(Collection $restaurants): void
    {
        foreach ($restaurants as $restaurant) {
            $dishes = Dish::query()
                ->where('restaurant_id', $restaurant->id)
                ->where('description', 'like', self::DESCRIPTION_MARKER . '%')
                ->orderBy('id')
                ->get();

            foreach ($dishes as $position => $dish) {
                $suggestedIds = collect($this->linkCandidates($dishes, $dish, $position, [1, 2]))
                    ->unique()
                    ->values()
                    ->all();

                $relatedIds = collect($this->linkCandidates($dishes, $dish, $position, [3, 4]))
                    ->unique()
                    ->values()
                    ->all();

                $dish->suggestedDishes()->sync($suggestedIds);
                $dish->relatedDishes()->sync($relatedIds);
            }
        }
    }

    /**
     * @param Collection<int, Dish> $dishes
     * @param array<int, int> $offsets
     * @return array<int, int>
     */
    private function linkCandidates(Collection $dishes, Dish $dish, int $position, array $offsets): array
    {
        $dishCount = $dishes->count();
        if ($dishCount === 0) {
            return [];
        }

        $ids = collect($offsets)
            ->map(function (int $offset) use ($dishes, $dishCount, $position): ?int {
                $target = $dishes[($position + $offset + $dishCount) % $dishCount] ?? null;

                return $target?->id;
            })
            ->filter(fn (?int $id): bool => $id !== null && $id !== $dish->id)
            ->values()
            ->all();

        if ($ids !== []) {
            return $ids;
        }

        $fallbackId = $dishes
            ->first(fn (Dish $candidate): bool => $candidate->id !== $dish->id)?->id;

        if ($fallbackId !== null) {
            return [$fallbackId];
        }

        return [$dish->id];
    }

    private function markSomeDishesOutOfStock(Collection $restaurants): int
    {
        $totalMarked = 0;

        foreach ($restaurants as $restaurant) {
            $dishes = Dish::query()
                ->where('restaurant_id', $restaurant->id)
                ->where('description', 'like', self::DESCRIPTION_MARKER . '%')
                ->orderBy('id')
                ->get();

            $dishCount = $dishes->count();
            if ($dishCount === 0) {
                continue;
            }

            $outOfStockCount = (int) floor($dishCount * 0.18);
            $outOfStockCount = max(1, $outOfStockCount);
            $outOfStockCount = min($outOfStockCount, max(1, $dishCount - 1));

            $blockerIngredient = Ingredient::query()->firstOrCreate(
                [
                    'restaurant_id' => $restaurant->id,
                    'name' => 'Seeder Out Of Stock Blocker',
                ],
                [
                    'uuid' => (string) Str::uuid(),
                    'storage_disk' => 'public',
                    'file_path' => null,
                    'stock_unit' => Ingredient::UNIT_PIECE,
                    'current_stock_quantity' => 0,
                    'low_stock_threshold' => 0,
                    'is_active' => false,
                ]
            );

            $blockerIngredient->update([
                'stock_unit' => Ingredient::UNIT_PIECE,
                'current_stock_quantity' => 0,
                'low_stock_threshold' => 0,
                'is_active' => false,
            ]);

            $targetDishes = $dishes->take($outOfStockCount);

            foreach ($targetDishes as $dish) {
                DishIngredient::query()->updateOrCreate(
                    [
                        'dish_id' => $dish->id,
                        'ingredient_id' => $blockerIngredient->id,
                    ],
                    [
                        'quantity' => 1,
                        'unit' => Ingredient::UNIT_PIECE,
                        'order_index' => 999,
                        'show_in_animation' => false,
                    ]
                );

                $totalMarked++;
            }
        }

        return $totalMarked;
    }

    private function priceForCategory(string $category, int $index): float
    {
        $basePrices = [
            'Pizza' => 10.50,
            'Specialty Pizza' => 13.50,
            'Burgers' => 9.50,
            'Sandwiches' => 8.50,
            'Pasta' => 11.00,
            'Salads' => 7.50,
            'Appetizers' => 6.50,
            'Sides' => 4.50,
            'Desserts' => 5.50,
            'Drinks' => 3.50,
        ];

        return round($basePrices[$category] + (($index * 0.73) % 4.5), 2);
    }

    private function caloriesForRecipe(array $ingredients, string $category, int $index): int
    {
        $baseCalories = [
            'Pizza' => 680,
            'Specialty Pizza' => 760,
            'Burgers' => 720,
            'Sandwiches' => 540,
            'Pasta' => 690,
            'Salads' => 320,
            'Appetizers' => 430,
            'Sides' => 290,
            'Desserts' => 510,
            'Drinks' => 180,
        ];

        return $baseCalories[$category] + ((count($ingredients) * 18) + ($index % 85));
    }

    private function imageForCategory(string $category, int $index, string $dishName, int $restaurantId): string
    {
        $images = self::CATEGORY_IMAGE_URLS[$category] ?? [
            'https://images.unsplash.com/photo-1504674900247-0877df9cc836?auto=format&fit=crop&w=1200&q=80',
        ];

        $baseUrl = $images[$index % count($images)];

        // Keep category-realistic photos, but assign a unique URL per dish row
        // so each dish record requests its own image resource during load tests.
        $normalizedDish = trim((string) preg_replace('/[^a-z0-9 ]+/i', ' ', strtolower($dishName)));
        $dishKeywords = preg_replace('/\s+/', ',', $normalizedDish) ?: 'dish';
        $sig = abs(crc32(sprintf('%d:%s:%d', $restaurantId, $dishName, $index)));

        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        return sprintf(
            '%s%sdish=%s&sig=%d',
            $baseUrl,
            $separator,
            rawurlencode((string) $dishKeywords),
            $sig
        );
    }
}
