<?php

namespace Database\Factories;

use App\Models\Restaurant;
use App\Models\RoomPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomPlan>
 */
class RoomPlanFactory extends Factory
{
    protected $model = RoomPlan::class;

    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'name' => fake()->unique()->words(2, true),
            'width' => 1600,
            'height' => 900,
            'background_image_path' => null,
        ];
    }
}
