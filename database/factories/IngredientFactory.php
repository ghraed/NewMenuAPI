<?php

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Ingredient>
 */
class IngredientFactory extends Factory
{
    protected $model = Ingredient::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => Restaurant::factory(),
            'global_ingredient_id' => null,
            'name' => Str::title(fake()->unique()->words(2, true)),
            'name_ar' => null,
            'storage_disk' => 'public',
            'file_path' => null,
            'source_file_name' => null,
            'file_size' => null,
            'mime_type' => null,
            'stock_unit' => Ingredient::UNIT_GRAM,
            'current_stock_quantity' => '5000.000',
            'low_stock_threshold' => '500.000',
            'target_quantity' => '7500.000',
            'unit_cost_cents' => 150,
            'average_cost_cents' => 150,
            'last_cost_cents' => 150,
            'cost_currency' => 'USD',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function pieces(float $stock = 100): static
    {
        return $this->state(fn (): array => [
            'stock_unit' => Ingredient::UNIT_PIECE,
            'current_stock_quantity' => number_format($stock, 3, '.', ''),
            'low_stock_threshold' => '10.000',
            'target_quantity' => '150.000',
        ]);
    }
}
