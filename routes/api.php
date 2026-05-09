<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AssetFileController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\CurrencySettingsController;
use App\Http\Controllers\DishController;
use App\Http\Controllers\GuestTableAccessController;
use App\Http\Controllers\GuestController;
use App\Http\Controllers\GlobalIngredientController;
use App\Http\Controllers\IngredientLibraryController;
use App\Http\Controllers\InventoryIngredientController;
use App\Http\Controllers\InventoryStockHistoryController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\FinanceExpenseCategoryController;
use App\Http\Controllers\FinanceExpenseController;
use App\Http\Controllers\FinancePayrollController;
use App\Http\Controllers\FinanceVendorController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\SuperAdmin\SuperAdminAuthController;
use App\Http\Controllers\SuperAdmin\SuperAdminFeatureFlagController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\PublicReservationController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\RoomPlanController;
use App\Http\Controllers\RoomPlanItemController;
use App\Http\Controllers\StaffScheduleController;
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
Route::post('/owner/auth/login', [SuperAdminAuthController::class, 'login'])
    ->middleware('throttle:owner-login');
Route::post('/super-admin/auth/login', [SuperAdminAuthController::class, 'login'])
    ->middleware('throttle:owner-login');
Route::middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, 'throttle:chat'])
    ->post('/chat', [ChatController::class, 'chat']);
Route::middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, 'throttle:chat-orders'])
    ->post('/chat/orders', [OrderController::class, 'storeChatOrder']);
Route::get('/test', [GuestController::class, 'test']);
Route::get('/test/{dish}', [GuestController::class, 'showTestDish']);
Route::get('/menu/dishes', [GuestController::class, 'listDishes'])->middleware('feature:qr_menu');
Route::get('/menu/dish/{dish_id}', [GuestController::class, 'showDish'])->middleware('feature:qr_menu');
Route::get('/menu/tables', [GuestController::class, 'listTables'])->middleware('feature:qr_menu');
Route::post('/menu/orders', [OrderController::class, 'store'])->middleware('feature:table_ordering');
Route::post('/menu/waves', [WaveController::class, 'store'])->middleware('feature:waiter_call');
Route::get('/menu/{restaurant_slug}/dishes', [GuestController::class, 'listDishesBySlug'])->middleware('feature:qr_menu');
Route::get('/menu/{restaurant_slug}/dish/{dish_id}', [GuestController::class, 'showDishBySlug'])->middleware('feature:qr_menu');
Route::get('/menu/{restaurant_slug}/tables', [GuestController::class, 'listTablesBySlug'])->middleware('feature:qr_menu');
Route::post('/menu/{restaurant_slug}/orders', [OrderController::class, 'store'])->middleware('feature:table_ordering');
Route::post('/menu/{restaurant_slug}/waves', [WaveController::class, 'store'])->middleware('feature:waiter_call');
Route::get('/menu/table/{table_id}', [MenuController::class, 'showTableMenu'])->middleware('feature:qr_menu');
Route::get('/menu/table/{table_id}/dish/{dish_id}', [MenuController::class, 'showTableDish'])->middleware('feature:qr_menu');
Route::post('/menu/table/{table_id}/verify-pin', [GuestTableAccessController::class, 'verifyPin'])->middleware('feature:qr_menu');
Route::get('/reservations/room-plans', [PublicReservationController::class, 'listRoomPlans'])->middleware('feature:table_reservations');
Route::get('/reservations/room-plans/{roomPlan}', [PublicReservationController::class, 'showRoomPlan'])->middleware('feature:table_reservations');
Route::get('/reservations/availability', [PublicReservationController::class, 'availability'])->middleware('feature:table_reservations');
Route::post('/reservations', [PublicReservationController::class, 'store'])->middleware('feature:table_reservations');
Route::middleware('guest.table.access')->group(function () {
    Route::get('/table-session/{tableSession}/orders', [OrderController::class, 'indexForSession'])
        ->middleware('feature:table_ordering');
    Route::get('/table-session/{tableSession}/invoice-split', [TableSessionController::class, 'guestInvoiceSplit'])
        ->middleware('feature:invoice_splitting');
    Route::patch('/table-session/{tableSession}/invoice-split', [TableSessionController::class, 'updateGuestInvoiceSplit'])
        ->middleware('feature:invoice_splitting');
    Route::post('/table-session/{tableSession}/order', [OrderController::class, 'storeForSession'])
        ->middleware('feature:table_ordering');
    Route::post('/table-session/{tableSession}/call-waiter', [WaveController::class, 'storeForSession'])
        ->middleware('feature:waiter_call');
    Route::post('/table-session/{tableSession}/request-bill', [TableSessionController::class, 'requestBill'])
        ->middleware('feature:request_bill');
});
Route::get('/assets/{asset}/file', [AssetFileController::class, 'show'])
    ->name('api.assets.show');
Route::post('/analytics/track', [AnalyticsController::class, 'track']);

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('owner')->middleware('saas_owner')->group(function () {
        Route::get('/auth/me', [SuperAdminAuthController::class, 'me']);
        Route::post('/auth/logout', [SuperAdminAuthController::class, 'logout']);

        Route::get('/restaurants', [SuperAdminFeatureFlagController::class, 'restaurants']);
        Route::get('/features', [SuperAdminFeatureFlagController::class, 'features']);
        Route::get('/restaurants/{restaurant}/features', [SuperAdminFeatureFlagController::class, 'restaurantFeatures']);
        Route::patch('/restaurants/{restaurant}/features/bulk', [SuperAdminFeatureFlagController::class, 'bulkUpdate']);
        Route::patch('/restaurants/{restaurant}/features/{feature}', [SuperAdminFeatureFlagController::class, 'updateFeature']);
    });
    Route::prefix('super-admin')->middleware('saas_owner')->group(function () {
        Route::get('/auth/me', [SuperAdminAuthController::class, 'me']);
        Route::post('/auth/logout', [SuperAdminAuthController::class, 'logout']);

        Route::get('/restaurants', [SuperAdminFeatureFlagController::class, 'restaurants']);
        Route::get('/features', [SuperAdminFeatureFlagController::class, 'features']);
        Route::get('/restaurants/{restaurant}/features', [SuperAdminFeatureFlagController::class, 'restaurantFeatures']);
        Route::patch('/restaurants/{restaurant}/features/bulk', [SuperAdminFeatureFlagController::class, 'bulkUpdate']);
        Route::patch('/restaurants/{restaurant}/features/{feature}', [SuperAdminFeatureFlagController::class, 'updateFeature']);
    });

    // Auth
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::middleware('role:admin,staff')->group(function () {
            Route::middleware('feature:realtime_staff_orders')->group(function () {
                Route::get('/orders/pending-confirmation', [OrderController::class, 'pendingConfirmation']);
                Route::get('/kitchen/orders', [OrderController::class, 'kitchenActiveOrders']);
                Route::get('/kitchen/orders/{order}', [OrderController::class, 'kitchenOrderDetails']);
                Route::patch('/orders/{order}', [OrderController::class, 'update']);
                Route::post('/orders/{order}/confirm', [OrderController::class, 'confirm']);
                Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);
                Route::post('/orders/{order}/served', [OrderController::class, 'markServed']);
                Route::get('/table-sessions/active', [TableSessionController::class, 'index']);
                Route::post('/table-sessions/activate', [TableSessionController::class, 'activate']);
                Route::post('/table-sessions/{tableSession}/reset-pin', [TableSessionController::class, 'resetPin']);
                Route::post('/table-sessions/{tableSession}/finalize', [TableSessionController::class, 'finalize']);
                Route::get('/dishes/published', [OrderController::class, 'publishedDishes']);
                Route::get('/waves/pending', [WaveController::class, 'pending']);
                Route::post('/waves/{wave}/resolve', [WaveController::class, 'resolve']);
            });
            Route::get('/table-sessions/{tableSession}/invoice-split', [TableSessionController::class, 'invoiceSplit'])
                ->middleware('feature:invoice_splitting');
            Route::post('/pos/checkout', [OrderController::class, 'quickCheckout'])
                ->middleware('feature:table_ordering');
            Route::get('/push/config', [PushSubscriptionController::class, 'config'])->middleware('feature:push_notifications');
            Route::post('/push/subscriptions', [PushSubscriptionController::class, 'store'])->middleware('feature:push_notifications');
            Route::post('/push/mobile-token', [PushSubscriptionController::class, 'storeMobileToken']);

            Route::middleware('feature:room_plan_editor')->group(function () {
                Route::get('/room-plans', [RoomPlanController::class, 'index']);
                Route::post('/room-plans', [RoomPlanController::class, 'store']);
                Route::get('/room-plans/{roomPlan}', [RoomPlanController::class, 'show']);
                Route::patch('/room-plans/{roomPlan}', [RoomPlanController::class, 'update']);
                Route::delete('/room-plans/{roomPlan}', [RoomPlanController::class, 'destroy']);
                Route::post('/room-plans/{roomPlan}/background', [RoomPlanController::class, 'uploadBackground']);
                Route::put('/room-plans/{roomPlan}/items/bulk', [RoomPlanController::class, 'saveItems']);

                Route::post('/room-plans/{roomPlan}/items', [RoomPlanItemController::class, 'store']);
                Route::patch('/room-plans/{roomPlan}/items/{roomPlanItem}', [RoomPlanItemController::class, 'update']);
                Route::delete('/room-plans/{roomPlan}/items/{roomPlanItem}', [RoomPlanItemController::class, 'destroy']);
                Route::post('/room-plans/{roomPlan}/items/{roomPlanItem}/duplicate', [RoomPlanItemController::class, 'duplicate']);
            });

            Route::middleware('feature:table_reservations')->group(function () {
                Route::get('/admin/reservations', [ReservationController::class, 'index']);
            });
        });

    Route::middleware('role:admin')->group(function () {
        Route::get('/orders/accounting', [OrderController::class, 'accounting'])
            ->middleware(['feature:finance_dashboard', 'feature:dish_profitability']);
        Route::post('/orders/{order}/account', [OrderController::class, 'account'])
            ->middleware(['feature:finance_dashboard', 'feature:dish_profitability']);

        // Dishes
        Route::apiResource('dishes', DishController::class);
        Route::post('/admin/dishes/generate-description', [DishController::class, 'generateDescription']);
        Route::post('/dishes/{dish}/copy-model', [DishController::class, 'copyModel']);
        Route::patch('/dishes/{dish}/publish', [DishController::class, 'publish']);
        Route::patch('/dishes/{dish}/unpublish', [DishController::class, 'unpublish']);
        Route::post('/dishes/{dish}/restore', [DishController::class, 'restore']);
        Route::delete('/dishes/{dish}/force', [DishController::class, 'forceDelete']);
        Route::get('/admin/finance/invoices/revenue-trends', [InvoiceController::class, 'revenueTrends'])
            ->middleware(['feature:finance_dashboard', 'feature:dish_profitability']);
        Route::get('/admin/finance/profit-loss', [InvoiceController::class, 'profitLoss'])
            ->middleware(['feature:finance_dashboard', 'feature:expense_management']);
        Route::get('/admin/finance/pnl', [InvoiceController::class, 'profitLoss'])
            ->middleware(['feature:finance_dashboard', 'feature:expense_management']);
        Route::get('/admin/finance/dashboard-metrics', [InvoiceController::class, 'dashboardMetrics'])
            ->middleware(['feature:finance_dashboard', 'feature:expense_management']);
        Route::get('/admin/finance/tax-report', [InvoiceController::class, 'taxReport'])
            ->middleware(['feature:finance_dashboard', 'feature:expense_management', 'feature:vat_invoices']);
        Route::get('/admin/finance/tax/summary', [InvoiceController::class, 'taxReport'])
            ->middleware(['feature:finance_dashboard', 'feature:expense_management', 'feature:vat_invoices']);
        Route::get('/admin/finance/invoices', [InvoiceController::class, 'index'])
            ->middleware(['feature:finance_dashboard', 'feature:vat_invoices', 'feature:expense_management']);
        Route::post('/admin/finance/invoices', [InvoiceController::class, 'store'])
            ->middleware(['feature:finance_dashboard', 'feature:vat_invoices', 'feature:expense_management']);
        Route::get('/admin/finance/invoices/{invoice}', [InvoiceController::class, 'show'])
            ->middleware(['feature:finance_dashboard', 'feature:vat_invoices', 'feature:expense_management']);
        Route::patch('/admin/finance/invoices/{invoice}', [InvoiceController::class, 'update'])
            ->middleware(['feature:finance_dashboard', 'feature:vat_invoices', 'feature:expense_management']);
        Route::get('/admin/finance/expense-categories', [FinanceExpenseCategoryController::class, 'index'])
            ->middleware(['feature:finance_dashboard', 'feature:expense_management']);
        Route::post('/admin/finance/expense-categories', [FinanceExpenseCategoryController::class, 'store'])
            ->middleware(['feature:finance_dashboard', 'feature:expense_management']);
        Route::patch('/admin/finance/expense-categories/{expenseCategory}', [FinanceExpenseCategoryController::class, 'update'])
            ->middleware(['feature:finance_dashboard', 'feature:expense_management']);
        Route::get('/admin/finance/vendors', [FinanceVendorController::class, 'index'])
            ->middleware(['feature:finance_dashboard', 'feature:expense_management']);
        Route::post('/admin/finance/vendors', [FinanceVendorController::class, 'store'])
            ->middleware(['feature:finance_dashboard', 'feature:expense_management']);
        Route::patch('/admin/finance/vendors/{vendor}', [FinanceVendorController::class, 'update'])
            ->middleware(['feature:finance_dashboard', 'feature:expense_management']);
        Route::get('/admin/finance/expenses', [FinanceExpenseController::class, 'index'])
            ->middleware(['feature:finance_dashboard', 'feature:expense_management']);
        Route::get('/admin/finance/expenses/unlinked-restocks', [FinanceExpenseController::class, 'unlinkedRestocks'])
            ->middleware(['feature:finance_dashboard', 'feature:expense_management']);
        Route::post('/admin/finance/expenses', [FinanceExpenseController::class, 'store'])
            ->middleware(['feature:finance_dashboard', 'feature:expense_management']);
        Route::patch('/admin/finance/expenses/{expense}', [FinanceExpenseController::class, 'update'])
            ->middleware(['feature:finance_dashboard', 'feature:expense_management']);
        Route::get('/admin/finance/payroll/periods', [FinancePayrollController::class, 'periodsIndex'])
            ->middleware('feature:payroll_management');
        Route::post('/admin/finance/payroll/periods', [FinancePayrollController::class, 'periodsStore'])
            ->middleware('feature:payroll_management');
        Route::post('/admin/finance/payroll/query', [FinancePayrollController::class, 'queryPeriods'])
            ->middleware('feature:payroll_management');
        Route::patch('/admin/finance/payroll/periods/{payrollPeriod}', [FinancePayrollController::class, 'periodsUpdate'])
            ->middleware('feature:payroll_management');
        Route::delete('/admin/finance/payroll/periods/{payrollPeriod}', [FinancePayrollController::class, 'periodsDestroy'])
            ->middleware('feature:payroll_management');
        Route::put('/admin/finance/payroll/periods/{payrollPeriod}/entries', [FinancePayrollController::class, 'entriesUpsert'])
            ->middleware('feature:payroll_management');
        Route::get('/admin/finance/payroll/summary', [FinancePayrollController::class, 'summary'])
            ->middleware('feature:payroll_management');
        Route::get('/admin/staff/schedules', [StaffScheduleController::class, 'index'])
            ->middleware('feature:staff_scheduling');
        Route::post('/admin/staff/schedules', [StaffScheduleController::class, 'store'])
            ->middleware('feature:staff_scheduling');
        Route::patch('/admin/staff/schedules/{staffShift}', [StaffScheduleController::class, 'update'])
            ->middleware('feature:staff_scheduling');

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
        Route::get('/analytics/dashboard', [AnalyticsController::class, 'dashboard'])
            ->middleware('feature:analytics');

        // Restaurant
        Route::patch('/restaurant/name', [RestaurantController::class, 'updateName']);
        Route::get('/restaurant/profile', [RestaurantController::class, 'showProfile']);
        Route::patch('/restaurant/profile', [RestaurantController::class, 'updateProfile']);
        Route::post('/restaurant/profile/logo', [RestaurantController::class, 'uploadLogo']);
        Route::get('/restaurant/currency-settings', [CurrencySettingsController::class, 'show']);
        Route::patch('/restaurant/currency-settings', [CurrencySettingsController::class, 'update']);
        Route::get('/restaurant/staff', [RestaurantController::class, 'indexStaff']);
        Route::post('/restaurant/staff', [RestaurantController::class, 'storeStaff']);
        Route::patch('/restaurant/staff/{staff}/tables', [RestaurantController::class, 'updateStaffTables']);
        Route::get('/restaurant/table-management', [RestaurantController::class, 'tableManagement']);
        Route::put('/restaurant/table-management/manual-count', [RestaurantController::class, 'updateManualTableCount']);

        // Global ingredients (catalog reference used by ingredient library only)
        Route::get('/global-ingredients', [GlobalIngredientController::class, 'index']);

        // Ingredient library
        Route::middleware('feature:inventory')->group(function () {
            Route::get('/ingredients', [IngredientLibraryController::class, 'index']);
            Route::post('/ingredients', [IngredientLibraryController::class, 'store']);
            Route::patch('/ingredients/{ingredient}', [IngredientLibraryController::class, 'update']);
            Route::delete('/ingredients/{ingredient}', [IngredientLibraryController::class, 'destroy']);
            Route::post('/ingredients/{ingredient}/generate-image', [IngredientLibraryController::class, 'generateImage']);
            Route::post('/ingredients/generate-missing-images', [IngredientLibraryController::class, 'generateMissingImages']);
            Route::post('/ingredients/bulk-upload', [IngredientLibraryController::class, 'bulkUpload']);
            Route::delete('/ingredients', [IngredientLibraryController::class, 'destroyAll']);
        });

        Route::middleware('feature:table_reservations')->group(function () {
            Route::post('/admin/reservations', [ReservationController::class, 'store']);
            Route::patch('/admin/reservations/{reservation}', [ReservationController::class, 'update']);
            Route::post('/admin/reservations/{reservation}/cancel', [ReservationController::class, 'cancel']);
            Route::post('/admin/reservations/{reservation}/busy', [ReservationController::class, 'markBusy']);
            Route::post('/admin/reservations/{reservation}/complete', [ReservationController::class, 'markCompleted']);
            Route::post('/admin/reservations/{reservation}/no-show', [ReservationController::class, 'markNoShow']);
        });

        // Inventory ingredients
        Route::middleware('feature:inventory')->group(function () {
            Route::get('/inventory/ingredients', [InventoryIngredientController::class, 'index']);
            Route::get('/inventory/stock-history', [InventoryStockHistoryController::class, 'index']);
            Route::post('/inventory/ingredients', [InventoryIngredientController::class, 'store']);
            Route::post('/inventory/ingredients/import-global', [InventoryIngredientController::class, 'importGlobal']);
            Route::patch('/inventory/ingredients/{ingredient}', [InventoryIngredientController::class, 'update']);
            Route::post('/inventory/ingredients/{ingredient}/activate', [InventoryIngredientController::class, 'activate']);
            Route::post('/inventory/ingredients/{ingredient}/deactivate', [InventoryIngredientController::class, 'deactivate']);
            Route::post('/inventory/ingredients/{ingredient}/restock', [InventoryIngredientController::class, 'restock']);
            Route::post('/inventory/ingredients/{ingredient}/adjust', [InventoryIngredientController::class, 'adjust']);
        });
    });

    Route::middleware(['role:chef', 'feature:realtime_staff_orders'])->group(function () {
        Route::post('/kitchen/orders/{order}/start', [OrderController::class, 'startKitchenPreparation']);
        Route::post('/kitchen/orders/{order}/ready', [OrderController::class, 'markKitchenReady']);
    });
});
