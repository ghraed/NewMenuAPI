<?php

namespace Database\Factories;

use App\Models\Feature;
use App\Models\Restaurant;
use App\Models\RestaurantFeature;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestaurantFeature>
 */
class RestaurantFeatureFactory extends Factory
{
    protected $model = RestaurantFeature::class;

    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'feature_id' => Feature::factory(),
            'enabled' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['enabled' => false]);
    }
}
