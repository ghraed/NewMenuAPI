<?php

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('seed:prod', function () {
    $email = env('ADMIN_EMAIL');
    $password = env('ADMIN_PASSWORD');
    $name = env('ADMIN_NAME', 'Admin');

    if (!$email || !$password) {
        $this->error('ADMIN_EMAIL and ADMIN_PASSWORD must be set in the environment.');
        return 1;
    }

    $user = User::firstOrCreate(
        ['email' => $email],
        ['name' => $name, 'password' => Hash::make($password)]
    );

    if (!$user->wasRecentlyCreated && $user->name !== $name) {
        $user->update(['name' => $name]);
    }

    $restaurant = $user->restaurant;
    if (!$restaurant) {
        $restaurantName = env('ADMIN_RESTAURANT_NAME', $name . "'s Restaurant");
        $restaurantSlug = env('ADMIN_RESTAURANT_SLUG', Str::slug($restaurantName));
        $restaurantAddress = env('ADMIN_RESTAURANT_ADDRESS', '');
        $restaurantDescription = env('ADMIN_RESTAURANT_DESCRIPTION', '');

        if (Restaurant::where('slug', $restaurantSlug)->exists()) {
            $restaurantSlug = $restaurantSlug . '-' . Str::random(4);
        }

        $restaurant = Restaurant::create([
            'uuid' => Str::uuid(),
            'user_id' => $user->id,
            'name' => $restaurantName,
            'slug' => $restaurantSlug,
            'description' => $restaurantDescription,
            'address' => $restaurantAddress,
        ]);
    }

    $this->info('Admin user ready: ' . $user->email);
    $this->info('Restaurant: ' . $restaurant->name . ' (' . $restaurant->slug . ')');
})->purpose('Create or update a production admin user and restaurant.');

Schedule::command('dishes:cleanup-deleted-assets')->dailyAt('02:00');
