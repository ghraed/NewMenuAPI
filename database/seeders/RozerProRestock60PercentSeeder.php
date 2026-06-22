<?php

namespace Database\Seeders;

use App\Models\RestaurantDomain;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RozerProRestock60PercentSeeder extends Seeder
{
    private const TARGET_DOMAIN = 'rozer.pro';

    /**
     * @var array<int, array{name:string, quantity:string}>
     */
    private const RESTOCK_LEVELS = [
        4307 => ['name' => 'Avocado', 'quantity' => '2500.000'],
        4309 => ['name' => 'Baguette', 'quantity' => '24.000'],
        4310 => ['name' => 'Basil', 'quantity' => '1200.000'],
        4313 => ['name' => 'Breadcrumbs', 'quantity' => '2500.000'],
        4316 => ['name' => 'Burger Bun', 'quantity' => '36.000'],
        4317 => ['name' => 'Butter', 'quantity' => '2500.000'],
        4318 => ['name' => 'Cabbage', 'quantity' => '5000.000'],
        4319 => ['name' => 'Caesar Dressing', 'quantity' => '3000.000'],
        4320 => ['name' => 'Carrot', 'quantity' => '3000.000'],
        4321 => ['name' => 'Cheddar Cheese', 'quantity' => '5000.000'],
        4323 => ['name' => 'Chili Flakes', 'quantity' => '800.000'],
        4326 => ['name' => 'Coffee', 'quantity' => '2500.000'],
        4327 => ['name' => 'Cream', 'quantity' => '5000.000'],
        4329 => ['name' => 'Croutons', 'quantity' => '2000.000'],
        4335 => ['name' => 'Jalapeno', 'quantity' => '1800.000'],
        4337 => ['name' => 'Mango', 'quantity' => '4000.000'],
        4339 => ['name' => 'Mayonnaise', 'quantity' => '4000.000'],
        4340 => ['name' => 'Milk', 'quantity' => '7000.000'],
        4341 => ['name' => 'Mozzarella Cheese', 'quantity' => '9000.000'],
        4342 => ['name' => 'Mushroom', 'quantity' => '3000.000'],
        4343 => ['name' => 'Onion', 'quantity' => '5000.000'],
        4344 => ['name' => 'Parmesan Cheese', 'quantity' => '3000.000'],
        4345 => ['name' => 'Penne Pasta', 'quantity' => '7000.000'],
        4346 => ['name' => 'Pepperoni', 'quantity' => '3000.000'],
        4347 => ['name' => 'Pizza Dough', 'quantity' => '36.000'],
        4348 => ['name' => 'Pizza Sauce', 'quantity' => '6000.000'],
        4350 => ['name' => 'Potato', 'quantity' => '12000.000'],
        4351 => ['name' => 'Quinoa', 'quantity' => '4000.000'],
        4352 => ['name' => 'Sandwich Bread', 'quantity' => '60.000'],
        4353 => ['name' => 'Spaghetti Pasta', 'quantity' => '6000.000'],
        4354 => ['name' => 'Strawberry', 'quantity' => '3500.000'],
        4357 => ['name' => 'Tortilla Chips', 'quantity' => '3000.000'],
        4361 => ['name' => 'Vanilla Ice Cream', 'quantity' => '5000.000'],
    ];

    public function run(): void
    {
        $domain = RestaurantDomain::query()
            ->with('restaurant')
            ->where('domain', self::TARGET_DOMAIN)
            ->first();

        if (! $domain?->restaurant) {
            throw new RuntimeException('Restaurant for domain rozer.pro was not found.');
        }

        $restaurantId = (int) $domain->restaurant->id;
        $updated = 0;

        DB::transaction(function () use ($restaurantId, &$updated): void {
            foreach (self::RESTOCK_LEVELS as $ingredientId => $row) {
                $affected = DB::table('ingredients')
                    ->where('restaurant_id', $restaurantId)
                    ->where('id', $ingredientId)
                    ->where('name', $row['name'])
                    ->update([
                        'current_stock_quantity' => DB::raw(sprintf(
                            'GREATEST(current_stock_quantity, %s)',
                            $row['quantity']
                        )),
                        'updated_at' => now(),
                    ]);

                if ($affected > 0) {
                    $updated++;
                }
            }
        }, 3);

        $this->command?->info(sprintf(
            'rozer.pro targeted restock completed for restaurant #%d. Updated %d ingredient rows.',
            $restaurantId,
            $updated
        ));
        $this->command?->line('Target outcome: about 24 orderable dishes out of the current 40 published dishes.');
    }
}
