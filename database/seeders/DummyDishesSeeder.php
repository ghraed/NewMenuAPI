<?php

namespace Database\Seeders;

use App\Models\Dish;
use App\Models\Restaurant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DummyDishesSeeder extends Seeder
{
    private const DISH_COUNT = 200;
    private const DESCRIPTION_MARKER = '[dummy-dishes-seeder]';

    private const IMAGE_URLS = [
        'https://images.unsplash.com/photo-1565299624946-b28f40a0ae38?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1513104890138-7c749659a591?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1548365328-9f547fb0953b?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1550547660-d9450f859349?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1568901346375-23c9450c58cd?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1553979459-d2229ba7433b?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1473093295043-cdd812d0e601?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1603133872878-684f208fb84b?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1621996346565-e3dbc353d2e5?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1540189549336-e6e99c3679fe?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1512621776951-a57141f2eefd?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1528735602780-2552fd46c7af?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1550507992-eb63ffee0847?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1521389508051-d7ffb5dc8f70?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1604908176997-4318c48b3a6b?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1571091718767-18b5b1457add?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1563805042-7684c019e1cb?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1505253213348-cd54c92b37c6?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1499636136210-6f4ee915583e?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1497534446932-c925b458314e?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1551024506-0bccd828d307?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1509042239860-f550ce710b93?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1504674900247-0877df9cc836?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1490645935967-10de6ba17061?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1600891964599-f61ba0e24092?auto=format&fit=crop&w=1200&q=80',
    ];

    public function run(): void
    {
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
            $sequence = str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);

            Dish::create([
                'uuid' => (string) Str::uuid(),
                'restaurant_id' => $restaurant->id,
                'name' => $this->buildDishName($recipe['name'], $sequence),
                'description' => $this->buildDescription(
                    $sequence,
                    $restaurant->name,
                    $category,
                    $recipe['ingredients']
                ),
                'price' => $this->priceForCategory($category, $index),
                'calories' => $this->caloriesForRecipe($recipe['ingredients'], $category, $index),
                'category' => $category,
                'status' => 'published',
                'image_url' => $this->imageForIndex($index),
            ]);

            $created++;
        }

        $this->attachDishLinks($restaurants);

        $this->command?->info(sprintf('Created %d dummy dishes with real ingredients.', $created));
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

    private function buildDishName(string $baseName, string $sequence): string
    {
        return sprintf('%s %s', $baseName, $sequence);
    }

    private function buildDescription(
        string $sequence,
        string $restaurantName,
        string $category,
        array $ingredients
    ): string {
        return sprintf(
            '%s Seeded sample dish %s for %s in the %s category. Prepared with %s.',
            self::DESCRIPTION_MARKER,
            $sequence,
            $restaurantName,
            $category,
            implode(', ', $ingredients)
        );
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
                $suggestedIds = $dishes
                    ->slice($position + 1, 2)
                    ->pluck('id')
                    ->all();

                $relatedIds = $dishes
                    ->slice(max(0, $position - 2), 2)
                    ->pluck('id')
                    ->filter(fn (int $id): bool => $id !== $dish->id)
                    ->values()
                    ->all();

                if ($suggestedIds !== []) {
                    $dish->suggestedDishes()->syncWithoutDetaching($suggestedIds);
                }

                if ($relatedIds !== []) {
                    $dish->relatedDishes()->syncWithoutDetaching($relatedIds);
                }
            }
        }
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

    private function imageForIndex(int $index): string
    {
        return self::IMAGE_URLS[$index % count(self::IMAGE_URLS)];
    }
}