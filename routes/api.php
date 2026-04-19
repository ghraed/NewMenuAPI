<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AssetFileController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DishController;
use App\Http\Controllers\GuestTableAccessController;
use App\Http\Controllers\GuestController;
use App\Http\Controllers\GlobalIngredientController;
use App\Http\Controllers\IngredientLibraryController;
use App\Http\Controllers\InventoryIngredientController;
use App\Http\Controllers\InventoryStockHistoryController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\QRCodeController;
use App\Http\Controllers\RestaurantController;
use App\Http\Controllers\TableSessionController;
use App\Http\Controllers\WaveController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/auth/login', [AuthController::class, 'login']);
Route::middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, 'throttle:chat'])
    ->post('/chat', [ChatController::class, 'chat']);
Route::middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, 'throttle:chat-orders'])
    ->post('/chat/orders', [OrderController::class, 'storeChatOrder']);
Route::get('/test', [GuestController::class, 'test']);
Route::get('/test/{dish}', [GuestController::class, 'showTestDish']);
Route::get('/menu/{restaurant_slug}/dishes', [GuestController::class, 'listDishes']);
Route::get('/menu/{restaurant_slug}/dish/{dish_id}', [GuestController::class, 'showDish']);
Route::get('/menu/{restaurant_slug}/tables', [GuestController::class, 'listTables']);
Route::post('/menu/{restaurant_slug}/orders', [OrderController::class, 'store']);
Route::post('/menu/{restaurant_slug}/waves', [WaveController::class, 'store']);
Route::get('/menu/table/{table_id}', [MenuController::class, 'showTableMenu']);
Route::get('/menu/table/{table_id}/dish/{dish_id}', [MenuController::class, 'showTableDish']);
Route::post('/menu/table/{table_id}/verify-pin', [GuestTableAccessController::class, 'verifyPin']);
Route::middleware('guest.table.access')->group(function () {
    Route::get('/table-session/{tableSession}/orders', [OrderController::class, 'indexForSession']);
    Route::post('/table-session/{tableSession}/order', [OrderController::class, 'storeForSession']);
    Route::post('/table-session/{tableSession}/call-waiter', [WaveController::class, 'storeForSession']);
    Route::post('/table-session/{tableSession}/request-bill', [TableSessionController::class, 'requestBill']);
});
Route::get('/assets/{asset}/file', [AssetFileController::class, 'show'])
    ->name('api.assets.show');
Route::post('/analytics/track', [AnalyticsController::class, 'track']);

Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::middleware('role:admin,staff')->group(function () {
        Route::get('/orders/pending-confirmation', [OrderController::class, 'pendingConfirmation']);
        Route::patch('/orders/{order}', [OrderController::class, 'update']);
        Route::post('/orders/{order}/confirm', [OrderController::class, 'confirm']);
        Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);
        Route::get('/table-sessions/active', [TableSessionController::class, 'index']);
        Route::post('/table-sessions/activate', [TableSessionController::class, 'activate']);
        Route::post('/table-sessions/{tableSession}/reset-pin', [TableSessionController::class, 'resetPin']);
        Route::post('/table-sessions/{tableSession}/finalize', [TableSessionController::class, 'finalize']);
        Route::get('/dishes/published', [OrderController::class, 'publishedDishes']);
        Route::get('/waves/pending', [WaveController::class, 'pending']);
        Route::post('/waves/{wave}/resolve', [WaveController::class, 'resolve']);
        Route::get('/push/config', [PushSubscriptionController::class, 'config']);
        Route::post('/push/subscriptions', [PushSubscriptionController::class, 'store']);
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
        Route::get('/restaurant/tables/{restaurantTable}/qr-code', [QRCodeController::class, 'generateTable']);
        Route::get('/restaurant/tables/{restaurantTable}/qr-download', [QRCodeController::class, 'downloadTable']);

        // Analytics
        Route::get('/analytics/dashboard', [AnalyticsController::class, 'dashboard']);

        // Restaurant
        Route::patch('/restaurant/name', [RestaurantController::class, 'updateName']);
        Route::get('/restaurant/staff', [RestaurantController::class, 'indexStaff']);
        Route::post('/restaurant/staff', [RestaurantController::class, 'storeStaff']);
        Route::patch('/restaurant/staff/{staff}/tables', [RestaurantController::class, 'updateStaffTables']);

        // Global ingredients (catalog reference used by ingredient library only)
        Route::get('/global-ingredients', [GlobalIngredientController::class, 'index']);

        // Ingredient library
        Route::get('/ingredients', [IngredientLibraryController::class, 'index']);
        Route::post('/ingredients/bulk-upload', [IngredientLibraryController::class, 'bulkUpload']);
        Route::delete('/ingredients', [IngredientLibraryController::class, 'destroyAll']);

        // Inventory ingredients
        Route::get('/inventory/ingredients', [InventoryIngredientController::class, 'index']);
        Route::get('/inventory/stock-history', [InventoryStockHistoryController::class, 'index']);
        Route::post('/inventory/ingredients', [InventoryIngredientController::class, 'store']);
        Route::patch('/inventory/ingredients/{ingredient}', [InventoryIngredientController::class, 'update']);
        Route::post('/inventory/ingredients/{ingredient}/activate', [InventoryIngredientController::class, 'activate']);
        Route::post('/inventory/ingredients/{ingredient}/deactivate', [InventoryIngredientController::class, 'deactivate']);
        Route::post('/inventory/ingredients/{ingredient}/restock', [InventoryIngredientController::class, 'restock']);
        Route::post('/inventory/ingredients/{ingredient}/adjust', [InventoryIngredientController::class, 'adjust']);
    });
});
