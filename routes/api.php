<?php

use App\Http\Controllers\AnalyticsController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DishController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\QRCodeController;
use App\Http\Controllers\GuestController;

// Public routes
Route::get('/test', [GuestController::class, 'test']);
Route::get('/menu/{restaurant_slug}/dish/{dish_id}', [GuestController::class, 'showDish']);
Route::post('/analytics/track', [AnalyticsController::class, 'track']);
Route::get('/test/{dish}', [GuestController::class, 'showTestDish']);
Route::apiResource('dishes', DishController::class);
// Protected admin routes
Route::middleware('auth:sanctum')->group(function () {
    // Dishes
    Route::patch('/dishes/{dish}/publish', [DishController::class, 'publish']);
    Route::patch('/dishes/{dish}/unpublish', [DishController::class, 'unpublish']);
    Route::get('/dishes/{dish}/model', [DishController::class, 'showDish']);

    // Assets
    Route::post('/dishes/{dish}/assets', [AssetController::class, 'upload']);
    Route::delete('/assets/{asset}', [AssetController::class, 'delete']);

    // QR Codes
    Route::get('/dishes/{dish}/qr-code', [QRCodeController::class, 'generate']);
    Route::get('/dishes/{dish}/qr-download', [QRCodeController::class, 'download']);

    // Analytics
    Route::get('/analytics/overview', [AnalyticsController::class, 'overview']);
    Route::get('/analytics/dish/{dish}', [AnalyticsController::class, 'dishStats']);
});
