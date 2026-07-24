<?php

namespace Database\Factories;

use App\Models\Dish;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Dish>
 */
class DishFactory extends Factory
{
    protected $model = Dish::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => Restaurant::factory(),
            'name' => Str::title(fake()->unique()->words(2, true)),
            'name_ar' => null,
            'description' => fake()->sentence(),
            'description_ar' => null,
            'price' => fake()->randomFloat(2, 4, 40),
            'currency' => 'USD',
            'calories' => fake()->numberBetween(150, 900),
            'category' => MenuCategoryFactory::random(),
            'category_ar' => null,
            'status' => 'draft',
            'item_type' => Dish::ITEM_TYPE_PREPARED_DISH,
            'direct_stock_ingredient_id' => null,
            'direct_stock_quantity_per_sale' => null,
            'is_anchor' => false,
            'is_profitable' => true,
            'image_url' => null,
            'brand' => null,
            'barcode' => null,
            'size_label' => null,
            'packaged_unit' => null,
            'cost_price' => fake()->randomFloat(2, 1, 20),
            'supplier' => null,
            'packaged_stock_quantity' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => ['status' => 'published']);
    }

    public function category(string $category): static
    {
        return $this->state(fn (): array => ['category' => $category]);
    }

    public function packaged(): static
    {
        return $this->state(fn (): array => [
            'item_type' => Dish::ITEM_TYPE_PACKAGED_DRINK,
            'packaged_unit' => 'bottle',
            'packaged_stock_quantity' => '24.000',
        ]);
    }
}

