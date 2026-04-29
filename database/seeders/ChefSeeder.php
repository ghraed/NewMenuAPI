<?php

namespace Database\Seeders;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Database\Seeder;

class ChefSeeder extends Seeder
{
    public function run(): void
    {
        $chef = User::query()->updateOrCreate(
            ['email' => 'chef@alpha.com'],
            [
                'name' => 'Alpha Chef',
                'phone' => null,
                'role' => User::ROLE_CHEF,
                'password' => bcrypt('chef12345'),
            ]
        );

        $restaurant = Restaurant::query()->first();

        if ($restaurant) {
            $restaurant->staffUsers()->syncWithoutDetaching([$chef->id]);
        }
    }
}
