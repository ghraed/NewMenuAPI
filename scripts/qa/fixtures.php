<?php

require __DIR__.'/bootstrap.php';
if (getenv('DB_DATABASE') !== 'menu_test_QA_RUN_'.getenv('QA_RUN_ID').'_browser') {
    throw new RuntimeException('Fixture reset requires this run\'s browser schema.');
}

if (in_array('--clean', $argv, true)) {
    Illuminate\Support\Facades\Artisan::call('db:wipe', ['--force' => true]);
    Illuminate\Support\Facades\File::deleteDirectory(storage_path('framework/testing/disks'));
    exit(0);
}
Illuminate\Support\Facades\Artisan::call('migrate:fresh', ['--force' => true]);

(new Database\Seeders\FeatureSeeder)->run();
$prefix = 'QA_RUN_'.getenv('QA_RUN_ID').'_'.getenv('QA_SCENARIO_ID');
$admin = App\Models\User::factory()->admin()->create([
    'name' => $prefix.'_admin',
    'email' => getenv('PLAYWRIGHT_PROFILE_EMAIL'),
    'password' => getenv('PLAYWRIGHT_PROFILE_PASSWORD'),
]);
$restaurant = App\Models\Restaurant::factory()->for($admin, 'user')->create([
    'name' => $prefix.'_restaurant',
    'slug' => getenv('VITE_GUEST_RESTAURANT_SLUG'),
    'profile' => ['menu_categories' => ['Drinks', 'Main Courses']],
    'manual_table_count' => 10,
]);
$disabled = ['ar_3d_dishes', 'animated_ingredients', 'ai_chatbot', 'ai_recommendations', 'push_notifications', 'custom_domain'];
foreach (App\Models\Feature::all() as $feature) {
    App\Models\RestaurantFeature::create([
        'restaurant_id' => $restaurant->id,
        'feature_id' => $feature->id,
        'enabled' => ! in_array($feature->key, $disabled, true),
    ]);
}
file_put_contents(getenv('QA_EVIDENCE_DIR').'/browser-fixtures.json', json_encode([
    'run_id' => getenv('QA_RUN_ID'), 'role' => $admin->role,
    'restaurant_slug' => $restaurant->slug,
    'feature_flags' => app(App\Services\FeatureFlagService::class)->flagsForRestaurant($restaurant),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
