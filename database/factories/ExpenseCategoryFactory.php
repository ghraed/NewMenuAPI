<?php

namespace Database\Factories;

use App\Models\ExpenseCategory;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpenseCategory>
 */
class ExpenseCategoryFactory extends Factory
{
    protected $model = ExpenseCategory::class;

    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'code' => fake()->unique()->slug(2, '_'),
            'name' => fake()->words(2, true),
            'is_active' => true,
        ];
    }
}
