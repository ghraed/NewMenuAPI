<?php

namespace Database\Seeders;

use App\Models\RestaurantDomain;
use App\Services\GlobalIngredientProvisioningService;
use Illuminate\Database\Seeder;
use RuntimeException;

class RozerProIngredientAccessSeeder extends Seeder
{
    public function run(): void
    {
        $domain = RestaurantDomain::query()
            ->with('restaurant')
            ->where('domain', 'rozer.pro')
            ->first();

        if (! $domain?->restaurant) {
            throw new RuntimeException('Restaurant for domain rozer.pro was not found.');
        }

        $result = app(GlobalIngredientProvisioningService::class)
            ->provisionForRestaurant($domain->restaurant);

        $this->command?->info(sprintf(
            'rozer.pro ingredient provisioning completed. Created: %d, linked: %d, skipped: %d.',
            $result['created_count'],
            $result['linked_count'],
            $result['skipped_count']
        ));
    }
}
