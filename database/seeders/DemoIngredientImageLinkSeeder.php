<?php

namespace Database\Seeders;

use App\Models\GlobalIngredient;
use App\Models\Ingredient;
use Illuminate\Database\Seeder;

class DemoIngredientImageLinkSeeder extends Seeder
{
    /**
     * Link demo restaurant ingredients to existing global ingredient images.
     *
     * This intentionally uses explicit name mappings so the result is stable
     * across local and server environments.
     */
    public function run(): void
    {
        $restaurantIds = [28, 29];

        $mappings = [
            'Pizza Dough' => 'pizza dough',
            'BBQ Sauce' => 'bbq sauce',
            'Mozzarella Cheese' => 'mozzarella',
            'Pepperoni' => 'pepperoni',
            'Beef Bacon' => 'beef bacon',
        ];

        $linkedCount = 0;

        foreach ($mappings as $ingredientName => $globalNormalizedName) {
            $global = GlobalIngredient::query()
                ->where('normalized_name', $globalNormalizedName)
                ->whereNotNull('file_path')
                ->first();

            if (! $global) {
                $this->command?->warn("Global ingredient not found or has no image: {$globalNormalizedName}");
                continue;
            }

            $updated = Ingredient::query()
                ->whereIn('restaurant_id', $restaurantIds)
                ->where('name', $ingredientName)
                ->update([
                    'global_ingredient_id' => $global->id,
                    'updated_at' => now(),
                ]);

            $linkedCount += $updated;
        }

        $this->command?->info("DemoIngredientImageLinkSeeder linked {$linkedCount} ingredient rows.");
    }
}
