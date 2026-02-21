<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Restaurant;
use App\Models\Dish;
use App\Models\DishAsset;
use App\Models\AnalyticsEvent;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create users
        $this->createUsers();

        // Create restaurants
        $this->createRestaurants();

        // Create dishes
        $this->createDishes();

        // Create dish assets (disabled by default to avoid missing files)
        // $this->createDishAssets();

        // Create analytics events
        $this->createAnalyticsEvents();
    }

    private function createUsers(): void
    {
        User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        User::create([
            'name' => 'John Restaurant Owner',
            'email' => 'john@restaurant.com',
            'password' => bcrypt('password'),
        ]);

        User::create([
            'name' => 'Sarah Chef',
            'email' => 'sarah@eatery.com',
            'password' => bcrypt('password'),
        ]);
    }

    private function createRestaurants(): void
    {
        $users = User::all();

        $restaurants = [
            [
                'user_id' => $users[0]->id,
                'name' => 'Pizza Palace',
                'slug' => 'pizza-palace',
                'description' => 'Authentic Italian pizza made with fresh ingredients and traditional recipes.',
                'address' => '123 Main Street, New York, NY 10001',
            ],
            [
                'user_id' => $users[1]->id,
                'name' => 'Sushi Haven',
                'slug' => 'sushi-haven',
                'description' => 'Premium sushi and Japanese cuisine in a serene atmosphere.',
                'address' => '456 Sakura Avenue, San Francisco, CA 94102',
            ],
            [
                'user_id' => $users[2]->id,
                'name' => 'Burger Barn',
                'slug' => 'burger-barn',
                'description' => 'Gourmet burgers and hand-cut fries in a rustic setting.',
                'address' => '789 Oak Drive, Chicago, IL 60601',
            ],
        ];

        foreach ($restaurants as $restaurant) {
            Restaurant::create([
                'uuid' => Str::uuid(),
                'user_id' => $restaurant['user_id'],
                'name' => $restaurant['name'],
                'slug' => $restaurant['slug'],
                'description' => $restaurant['description'],
                'address' => $restaurant['address'],
            ]);
        }
    }

    private function createDishes(): void
    {
        $restaurants = Restaurant::all();

        // Pizza Palace dishes
        $pizzaDishes = [
            [
                'name' => 'Margherita Pizza',
                'description' => 'Classic pizza with tomato sauce, fresh mozzarella, basil, and olive oil.',
                'price' => 12.99,
                'category' => 'Pizza',
                'status' => 'published',
            ],
            [
                'name' => 'Pepperoni Supreme',
                'description' => 'Loaded with pepperoni, sausage, mushrooms, onions, and extra cheese.',
                'price' => 15.99,
                'category' => 'Pizza',
                'status' => 'published',
            ],
            [
                'name' => 'Truffle Mushroom Pizza',
                'description' => 'Wild mushrooms, truffle oil, fontina cheese, and arugula.',
                'price' => 18.99,
                'category' => 'Specialty Pizza',
                'status' => 'published',
            ],
        ];

        foreach ($pizzaDishes as $dish) {
            Dish::create([
                'uuid' => Str::uuid(),
                'restaurant_id' => $restaurants[0]->id,
                'name' => $dish['name'],
                'description' => $dish['description'],
                'price' => $dish['price'],
                'category' => $dish['category'],
                'status' => $dish['status'],
            ]);
        }

        // Sushi Haven dishes
        $sushiDishes = [
            [
                'name' => 'Dragon Roll',
                'description' => 'Eel, cucumber, and avocado topped with thinly sliced avocado and eel sauce.',
                'price' => 16.50,
                'category' => 'Rolls',
                'status' => 'published',
            ],
            [
                'name' => 'Salmon Nigiri',
                'description' => 'Fresh salmon slices over seasoned rice.',
                'price' => 8.50,
                'category' => 'Nigiri',
                'status' => 'published',
            ],
            [
                'name' => 'Miso Soup',
                'description' => 'Traditional Japanese soup with tofu, seaweed, and green onions.',
                'price' => 4.50,
                'category' => 'Appetizers',
                'status' => 'published',
            ],
        ];

        foreach ($sushiDishes as $dish) {
            Dish::create([
                'uuid' => Str::uuid(),
                'restaurant_id' => $restaurants[1]->id,
                'name' => $dish['name'],
                'description' => $dish['description'],
                'price' => $dish['price'],
                'category' => $dish['category'],
                'status' => $dish['status'],
            ]);
        }

        // Burger Barn dishes
        $burgerDishes = [
            [
                'name' => 'Classic Cheeseburger',
                'description' => 'Angus beef patty with cheddar cheese, lettuce, tomato, and special sauce.',
                'price' => 11.99,
                'category' => 'Burgers',
                'status' => 'published',
            ],
            [
                'name' => 'BBQ Bacon Burger',
                'description' => 'Beef patty with crispy bacon, cheddar, BBQ sauce, and onion rings.',
                'price' => 13.99,
                'category' => 'Burgers',
                'status' => 'published',
            ],
            [
                'name' => 'Truffle Fries',
                'description' => 'Hand-cut fries tossed in truffle oil and parmesan cheese.',
                'price' => 6.99,
                'category' => 'Sides',
                'status' => 'published',
            ],
        ];

        foreach ($burgerDishes as $dish) {
            Dish::create([
                'uuid' => Str::uuid(),
                'restaurant_id' => $restaurants[2]->id,
                'name' => $dish['name'],
                'description' => $dish['description'],
                'price' => $dish['price'],
                'category' => $dish['category'],
                'status' => $dish['status'],
            ]);
        }
    }

    private function createDishAssets(): void
    {
        $dishes = Dish::all();

        foreach ($dishes as $index => $dish) {
            // Create preview image for all dishes
            DishAsset::create([
                'uuid' => Str::uuid(),
                'dish_id' => $dish->id,
                'asset_type' => 'preview_image',
                'file_path' => "storage/dishes/{$dish->uuid}/preview.jpg",
                'file_url' => "https://example.com/storage/dishes/{$dish->uuid}/preview.jpg",
                'file_size' => rand(200000, 500000),
                'mime_type' => 'image/jpeg',
                'metadata' => [
                    'width' => 1200,
                    'height' => 800,
                    'alt' => $dish->name,
                ],
            ]);

            // Create GLB asset for 3D viewer (all dishes)
            DishAsset::create([
                'uuid' => Str::uuid(),
                'dish_id' => $dish->id,
                'asset_type' => 'glb',
                'file_path' => "storage/dishes/{$dish->uuid}/model.usdz",
                'file_url' => "https://example.com/storage/dishes/{$dish->uuid}/model.glb",
                'file_size' => rand(1000000, 5000000),
                'mime_type' => 'model/gltf-binary',
                'metadata' => [
                    'version' => '1.0',
                    'generator' => 'Blender',
                    'scale' => 1.0,
                ],
            ]);

            // Create USDZ asset for AR (iOS) - only for first 6 dishes
            if ($index < 6) {
                DishAsset::create([
                    'uuid' => Str::uuid(),
                    'dish_id' => $dish->id,
                    'asset_type' => 'usdz',
                    'file_path' => "storage/dishes/{$dish->uuid}/model.usdz",
                    'file_url' => "https://example.com/storage/dishes/{$dish->uuid}/model.usdz",
                    'file_size' => rand(2000000, 8000000),
                    'mime_type' => 'model/vnd.usdz+zip',
                    'metadata' => [
                        'version' => '1.0',
                        'platform' => 'iOS',
                        'requires_ar' => true,
                    ],
                ]);
            }
        }
    }

    private function createAnalyticsEvents(): void
    {
        $dishes = Dish::all();
        if ($dishes->isEmpty()) {
            return;
        }

        $restaurants = Restaurant::all();
        if ($restaurants->isEmpty()) {
            return;
        }

        $deviceTypes = ['mobile', 'tablet', 'desktop'];
        $platforms = ['ios', 'android', 'unknown'];

        // Create realistic user sessions
        for ($i = 0; $i < 100; $i++) {
            $dish = $dishes->random();
            $restaurant = $dish->restaurant;
            $platform = $platforms[array_rand($platforms)];
            $device = $deviceTypes[array_rand($deviceTypes)];
            $ip = long2ip(rand(0, 4294967295));
            $baseTime = now()->subDays(rand(0, 30));

            // Session flow: page_view -> 3d_viewer_opened -> 3d_model_loaded -> (maybe ar_launch)
            $events = [
                ['type' => 'page_view', 'delay' => 0],
                ['type' => '3d_viewer_opened', 'delay' => rand(1, 5)],
                ['type' => '3d_model_loaded', 'delay' => rand(2, 8)],
            ];

            // 30% chance to attempt AR
            if (rand(1, 100) <= 30) {
                $events[] = ['type' => 'ar_launch_attempt', 'delay' => rand(5, 15)];
                $events[] = ['type' => 'ar_launch_success', 'delay' => rand(1, 3)];
            }

            $currentTime = $baseTime;
            foreach ($events as $event) {
                $currentTime = $currentTime->addSeconds($event['delay']);

                AnalyticsEvent::create([
                    'uuid' => Str::uuid(),
                    'dish_id' => $dish->id,
                    'restaurant_id' => $restaurant->id,
                    'event_type' => $event['type'],
                    'device_type' => $device,
                    'platform' => $platform,
                    'user_agent' => "Mozilla/5.0 ($platform)",
                    'ip_address' => $ip,
                    'created_at' => $currentTime,
                    'updated_at' => $currentTime,
                ]);
            }
        }

        // Add some QR scans and errors
        for ($i = 0; $i < 20; $i++) {
            $dish = $dishes->random();
            AnalyticsEvent::create([
                'uuid' => Str::uuid(),
                'dish_id' => $dish->id,
                'restaurant_id' => $dish->restaurant_id,
                'event_type' => 'qr_scan',
                'device_type' => $deviceTypes[array_rand($deviceTypes)],
                'platform' => $platforms[array_rand($platforms)],
                'created_at' => now()->subDays(rand(0, 30)),
                'updated_at' => now()->subDays(rand(0, 30)),
            ]);
        }
    }
}
