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
    private const DEFAULT_IMAGE_URL = 'https://images.unsplash.com/photo-1565299624946-b28f40a0ae38?auto=format&fit=crop&w=1200&q=80';

    /**
     * Seed a large set of dummy dishes across existing restaurants.
     */
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
                'image_url' => self::DEFAULT_IMAGE_URL,
            ]);

            $created++;
        }

        $this->attachDishLinks($restaurants);

        $this->command?->info(sprintf('Created %d dummy dishes.', $created));
    }

    /**
     * Returns the marker used by the cleanup command.
     */
    public static function descriptionMarker(): string
    {
        return self::DESCRIPTION_MARKER;
    }

    /**
     * Returns the shared image URL used by the seeded dishes.
     */
    public static function imageUrl(): string
    {
        return self::DEFAULT_IMAGE_URL;
    }

    private function attachDishLinks(Collection $restaurants): void
    {
        foreach ($restaurants as $restaurant) {
            $dishes = Dish::query()
                ->where('restaurant_id', $restaurant->id)
                ->where('description', 'like', self::DESCRIPTION_MARKER.'%')
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

    private function priceForIndex(int $index): float
    {
        return round(6.50 + (($index * 1.37) % 18), 2);
    }
}
