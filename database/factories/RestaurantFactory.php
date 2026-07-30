<?php

namespace Database\Factories;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Restaurant>
 */
class RestaurantFactory extends Factory
{
    protected $model = Restaurant::class;

    public function definition(): array
    {
        $name = fake()->unique()->company().' Restaurant';

        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory()->admin(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'status' => 'active',
            'description' => fake()->sentence(),
            'address' => fake()->address(),
            'currency' => 'USD',
            'other_currency' => null,
            'dollar_rate' => '1.00',
            'custom_domain' => null,
            'custom_domain_status' => null,
            'custom_domain_error' => null,
            'ssl_issued_at' => null,
            'logo_path' => null,
            'profile' => [
                'legal_business_name' => fake()->company(),
                'contact_email' => fake()->companyEmail(),
                'primary_phone' => fake()->phoneNumber(),
            ],
            'manual_table_count' => null,
        ];
    }

    public function manualTables(int $count = 10): static
    {
        return $this->state(fn (): array => [
            'manual_table_count' => $count,
        ]);
    }

    public function withCustomDomain(?string $domain = null): static
    {
        $normalized = $domain ?? fake()->unique()->domainName();

        return $this->state(fn (): array => [
            'custom_domain' => $normalized,
            'custom_domain_status' => 'pending_dns',
        ]);
    }
}
