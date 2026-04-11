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
        // Pizza
        'https://images.unsplash.com/photo-1565299624946-b28f40a0ae38?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1513104890138-7c749659a591?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1548365328-9f547fb0953b?auto=format&fit=crop&w=1200&q=80',

        // Burgers
        'https://images.unsplash.com/photo-1550547660-d9450f859349?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1568901346375-23c9450c58cd?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1553979459-d2229ba7433b?auto=format&fit=crop&w=1200&q=80',

        // Pasta
        'https://images.unsplash.com/photo-1473093295043-cdd812d0e601?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1603133872878-684f208fb84b?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1621996346565-e3dbc353d2e5?auto=format&fit=crop&w=1200&q=80',

        // Salads
        'https://images.unsplash.com/photo-1540189549336-e6e99c3679fe?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1512621776951-a57141f2eefd?auto=format&fit=crop&w=1200&q=80',

        // Sandwiches
        'https://images.unsplash.com/photo-1528735602780-2552fd46c7af?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1550507992-eb63ffee0847?auto=format&fit=crop&w=1200&q=80',

        // Appetizers / sides
        'https://images.unsplash.com/photo-1521389508051-d7ffb5dc8f70?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1604908176997-4318c48b3a6b?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1571091718767-18b5b1457add?auto=format&fit=crop&w=1200&q=80',

        // Desserts
        'https://images.unsplash.com/photo-1563805042-7684c019e1cb?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1505253213348-cd54c92b37c6?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1499636136210-6f4ee915583e?auto=format&fit=crop&w=1200&q=80',

        // Drinks
        'https://images.unsplash.com/photo-1497534446932-c925b458314e?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1551024506-0bccd828d307?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1509042239860-f550ce710b93?auto=format&fit=crop&w=1200&q=80',

        // General food
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

        $categories = [
            'Pizza',
            'Specialty Pizza',
            'Burgers',
            'Sandwiches',
            'Pasta',
            'Salads',
            'Appetizers',
            'Sides',
            'Desserts',
            'Drinks',
        ];

        $dishNamePrefixes = [
            'Classic',
            'Smoky',
            'Crispy',
            'Garden',
            'Loaded',
            'Signature',
            'Golden',
            'Spicy',
            'Herbed',
            'Roasted',
        ];

        $dishNameSuffixes = [
            'Delight',
            'Feast',
            'Stack',
            'Special',
            'Bite',
            'Selection',
            'Plate',
            'Fusion',
            'Combo',
            'Creation',
        ];

        $created = 0;

        for ($index = 0; $index < self::DISH_COUNT; $index++) {
            $restaurant = $restaurants[$index % $restaurants->count()];
            $category = $categories[$index % count($categories)];
            $prefix = $dishNamePrefixes[$index % count($dishNamePrefixes)];
            $suffix = $dishNameSuffixes[intdiv($index, count($dishNamePrefixes)) % count($dishNameSuffixes)];
            $sequence = str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);

            Dish::create([
                'uuid' => (string) Str::uuid(),
                'restaurant_id' => $restaurant->id,
                'name' => sprintf('Dummy %s %s %s', $prefix, $category, $sequence),
                'description' => sprintf(
                    '%s Seeded sample dish %s for %s in the %s category. Remove with php artisan dishes:purge-dummy.',
                    self::DESCRIPTION_MARKER,
                    $sequence,
                    $restaurant->name,
                    $category
                ),
                'price' => $this->priceForIndex($index),
                'calories' => 280 + (($index * 17) % 520),
                'category' => $category,
                'status' => 'published',
                'image_url' => $this->imageForIndex($index),
            ]);

            $created++;
        }

        $this->attachDishLinks($restaurants);

        $this->command?->info(sprintf('Created %d dummy dishes.', $created));
    }

    public static function descriptionMarker(): string
    {
        return self::DESCRIPTION_MARKER;
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
                    ->filter(fn(int $id): bool => $id !== $dish->id)
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

    private function priceForIndex(int $index): float
    {
        return round(6.50 + (($index * 1.37) % 18), 2);
    }

    private function imageForIndex(int $index): string
    {
        return self::IMAGE_URLS[$index % count(self::IMAGE_URLS)];
    }
}
