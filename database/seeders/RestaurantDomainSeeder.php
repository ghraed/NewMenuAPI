<?php

namespace Database\Seeders;

use App\Models\Restaurant;
use App\Models\RestaurantDomain;
use Illuminate\Database\Seeder;

class RestaurantDomainSeeder extends Seeder
{
    public function run(): void
    {
        $restaurants = Restaurant::query()
            ->orderBy('id')
            ->get(['id']);

        if ($restaurants->isEmpty()) {
            return;
        }

        $defaultDomains = [
            ['domain' => 'rozer.fun', 'kind' => 'custom'],
            ['domain' => 'alpha.rozer.fun', 'kind' => 'subdomain'],
            ['domain' => 'sigma.rozer.fun', 'kind' => 'subdomain'],
        ];

        foreach ($defaultDomains as $index => $entry) {
            $restaurant = $restaurants->get($index);
            if (! $restaurant) {
                break;
            }

            RestaurantDomain::query()->updateOrCreate(
                ['domain' => strtolower($entry['domain'])],
                [
                    'restaurant_id' => $restaurant->id,
                    'kind' => $entry['kind'],
                    'is_primary' => true,
                    'verified_at' => now(),
                ]
            );
        }
    }
}
