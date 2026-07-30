<?php

namespace Database\Factories;

use App\Models\Restaurant;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vendor>
 */
class VendorFactory extends Factory
{
    protected $model = Vendor::class;

    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'name' => fake()->company(),
            'contact_name' => fake()->name(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->companyEmail(),
            'tax_number' => fake()->bothify('TAX-####'),
            'notes' => null,
            'is_active' => true,
        ];
    }
}
