<?php

use App\Http\Controllers\AnalyticsController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DishController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\QRCodeController;
use App\Http\Controllers\GuestController;

// Public routes
Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/test', [GuestController::class, 'test']);
Route::get('/test/{dish}', [GuestController::class, 'showTestDish']);
Route::get('/menu/{restaurant_slug}/dishes', [GuestController::class, 'listDishes']);
Route::get('/menu/{restaurant_slug}/dish/{dish_id}', [GuestController::class, 'showDish']);
Route::post('/analytics/track', [AnalyticsController::class, 'track']);

// Protected admin routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Dishes
    Route::apiResource('dishes', DishController::class);
    Route::patch('/dishes/{dish}/publish', [DishController::class, 'publish']);
    Route::patch('/dishes/{dish}/unpublish', [DishController::class, 'unpublish']);
    Route::post('/dishes/{dish}/restore', [DishController::class, 'restore']);
    Route::delete('/dishes/{dish}/force', [DishController::class, 'forceDelete']);

    // Assets
    Route::post('/dishes/{dish}/assets', [AssetController::class, 'upload']);
    Route::delete('/assets/{asset}', [AssetController::class, 'delete']);

    // QR Codes
    Route::get('/dishes/{dish}/qr-code', [QRCodeController::class, 'generate']);
    Route::get('/dishes/{dish}/qr-download', [QRCodeController::class, 'download']);

    // Analytics
    Route::get('/analytics/dashboard', [AnalyticsController::class, 'dashboard']);
});
