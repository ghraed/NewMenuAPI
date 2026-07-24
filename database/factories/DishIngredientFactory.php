<?php

namespace Database\Factories;

use App\Models\Dish;
use App\Models\DishIngredient;
use App\Models\Ingredient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DishIngredient>
 */
class DishIngredientFactory extends Factory
{
    protected $model = DishIngredient::class;

    public function definition(): array
    {
        return [
            'dish_id' => Dish::factory(),
            'ingredient_id' => Ingredient::factory(),
            'quantity' => '250.000',
            'unit' => 'g',
            'order_index' => 1,
            'show_in_animation' => true,
        ];
    }
}

