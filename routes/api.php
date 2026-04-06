<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AssetFileController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DishController;
use App\Http\Controllers\GuestController;
use App\Http\Controllers\IngredientLibraryController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\QRCodeController;
use App\Http\Controllers\RestaurantController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/test', [GuestController::class, 'test']);
Route::get('/test/{dish}', [GuestController::class, 'showTestDish']);
Route::get('/menu/{restaurant_slug}/dishes', [GuestController::class, 'listDishes']);
Route::get('/menu/{restaurant_slug}/dish/{dish_id}', [GuestController::class, 'showDish']);
Route::get('/menu/{restaurant_slug}/tables', [GuestController::class, 'listTables']);
Route::post('/menu/{restaurant_slug}/orders', [OrderController::class, 'store']);
Route::get('/assets/{asset}/file', [AssetFileController::class, 'show'])
    ->name('api.assets.show');
Route::post('/analytics/track', [AnalyticsController::class, 'track']);

Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::middleware('role:admin,staff')->group(function () {
        Route::get('/orders/pending-confirmation', [OrderController::class, 'pendingConfirmation']);
        Route::post('/orders/{order}/confirm', [OrderController::class, 'confirm']);
        Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);
    });

    Route::middleware('role:admin')->group(function () {
        Route::get('/orders/accounting', [OrderController::class, 'accounting']);
        Route::post('/orders/{order}/account', [OrderController::class, 'account']);

        // Dishes
        Route::apiResource('dishes', DishController::class);
        Route::post('/dishes/{dish}/copy-model', [DishController::class, 'copyModel']);
        Route::patch('/dishes/{dish}/publish', [DishController::class, 'publish']);
        Route::patch('/dishes/{dish}/unpublish', [DishController::class, 'unpublish']);
        Route::post('/dishes/{dish}/restore', [DishController::class, 'restore']);
        Route::delete('/dishes/{dish}/force', [DishController::class, 'forceDelete']);

        // Assets
        Route::post('/dishes/{dish}/assets', [AssetController::class, 'upload']);
        Route::patch('/assets/{asset}', [AssetController::class, 'update']);
        Route::delete('/assets/{asset}', [AssetController::class, 'delete']);

        // QR Codes
        Route::get('/dishes/{dish}/qr-code', [QRCodeController::class, 'generate']);
        Route::get('/dishes/{dish}/qr-download', [QRCodeController::class, 'download']);

        // Analytics
        Route::get('/analytics/dashboard', [AnalyticsController::class, 'dashboard']);

        // Restaurant
        Route::patch('/restaurant/name', [RestaurantController::class, 'updateName']);
        Route::post('/restaurant/staff', [RestaurantController::class, 'storeStaff']);

        // Ingredient library
        Route::get('/ingredients', [IngredientLibraryController::class, 'index']);
        Route::post('/ingredients/bulk-upload', [IngredientLibraryController::class, 'bulkUpload']);
        Route::delete('/ingredients', [IngredientLibraryController::class, 'destroyAll']);
    });
});
