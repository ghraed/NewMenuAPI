<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('restaurant.{restaurantId}.table.{tableId}.waves', function (User $user, int $restaurantId, int $tableId) {
    $user->loadMissing('restaurant', 'staffRestaurants');

    $restaurant = $user->currentRestaurant();

    if (! $restaurant || $restaurant->id !== $restaurantId) {
        return false;
    }

    if ($user->isAdmin()) {
        return true;
    }

    return $user->hasTableAssignmentFor($tableId);
});

Broadcast::channel('restaurant.{restaurantId}.kitchen', function (User $user, int $restaurantId) {
    $user->loadMissing('restaurant', 'staffRestaurants');

    $restaurant = $user->currentRestaurant();

    if (! $restaurant || $restaurant->id !== $restaurantId) {
        return false;
    }

    return $user->isAdmin() || $user->isChef();
});

Broadcast::channel('restaurant.{restaurantId}.events', function (User $user, int $restaurantId) {
    $user->loadMissing('restaurant', 'staffRestaurants');

    $restaurant = $user->currentRestaurant();

    if (! $restaurant || $restaurant->id !== $restaurantId) {
        return false;
    }

    return $user->isAdmin() || $user->isChef() || $user->isStockManager();
});

Broadcast::channel('restaurant.{restaurantId}.accounting', function (User $user, int $restaurantId) {
    $user->loadMissing('restaurant', 'staffRestaurants');

    $restaurant = $user->currentRestaurant();

    if (! $restaurant || $restaurant->id !== $restaurantId) {
        return false;
    }

    return $user->isAdmin() || $user->isAccountant() || $user->isStaff();
});
