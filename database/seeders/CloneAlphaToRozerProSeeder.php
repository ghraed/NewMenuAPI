<?php

namespace Database\Seeders;

use App\Models\Restaurant;
use App\Models\RestaurantDomain;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CloneAlphaToRozerProSeeder extends Seeder
{
    private const SOURCE_DOMAIN = 'alpha.rozer.fun';
    private const TARGET_DOMAIN = 'rozer.pro';
    private const TARGET_ADMIN_EMAIL = 'rozer@rozer.pro';
    private const TARGET_EMAIL_DOMAIN = 'rozer.pro';

    /**
     * @var array<string, array<string, bool>>
     */
    private array $nullableColumns = [];

    public function run(): void
    {
        $sourceRestaurant = $this->resolveRestaurantByDomain(self::SOURCE_DOMAIN);
        $targetRestaurant = $this->resolveRestaurantByDomain(self::TARGET_DOMAIN);
        $targetAdmin = $this->resolveTargetAdmin($targetRestaurant);

        if ((int) $sourceRestaurant->id === (int) $targetRestaurant->id) {
            throw new RuntimeException('Source and target restaurants must be different.');
        }

        DB::transaction(function () use ($sourceRestaurant, $targetRestaurant, $targetAdmin): void {
            $userMap = $this->upsertMappedUsers((int) $sourceRestaurant->id);
            $this->copyRestaurantMetadata($sourceRestaurant, $targetRestaurant, $targetAdmin);
            $this->cleanupTargetRestaurantData((int) $targetRestaurant->id);
            $this->cloneRestaurantData(
                sourceRestaurantId: (int) $sourceRestaurant->id,
                targetRestaurantId: (int) $targetRestaurant->id,
                targetAdminId: (int) $targetAdmin->id,
                userMap: $userMap
            );
        }, 3);

        $this->command?->info(sprintf(
            'Cloned tenant data from %s (restaurant #%d) into %s (restaurant #%d).',
            self::SOURCE_DOMAIN,
            $sourceRestaurant->id,
            self::TARGET_DOMAIN,
            $targetRestaurant->id
        ));
        $this->command?->info('Alpha tenant users were inserted or updated with @rozer.pro emails and password equal to email.');
    }

    private function resolveRestaurantByDomain(string $domain): Restaurant
    {
        $record = RestaurantDomain::query()
            ->with('restaurant')
            ->where('domain', strtolower($domain))
            ->first();

        if (! $record?->restaurant) {
            throw new RuntimeException(sprintf('Restaurant for domain %s was not found.', $domain));
        }

        return $record->restaurant;
    }

    private function resolveTargetAdmin(Restaurant $targetRestaurant): User
    {
        $admin = User::query()->where('email', self::TARGET_ADMIN_EMAIL)->first();

        if (! $admin) {
            $admin = User::query()->find($targetRestaurant->user_id);
        }

        if (! $admin) {
            throw new RuntimeException('Target admin user was not found.');
        }

        return $admin;
    }

    private function copyRestaurantMetadata(Restaurant $sourceRestaurant, Restaurant $targetRestaurant, User $targetAdmin): void
    {
        $sourceAttributes = Arr::except($sourceRestaurant->getAttributes(), [
            'id',
            'uuid',
            'user_id',
            'slug',
            'custom_domain',
            'custom_domain_status',
            'custom_domain_error',
            'ssl_issued_at',
            'created_at',
            'updated_at',
            'deleted_at',
        ]);

        $targetRestaurant->fill($sourceAttributes);
        $targetRestaurant->user_id = $targetAdmin->id;
        $targetRestaurant->deleted_at = null;
        $targetRestaurant->save();
    }

    private function cleanupTargetRestaurantData(int $targetRestaurantId): void
    {
        $targetDishIds = DB::table('dishes')->where('restaurant_id', $targetRestaurantId)->pluck('id');
        $targetOrderIds = DB::table('orders')->where('restaurant_id', $targetRestaurantId)->pluck('id');
        $targetInvoiceIds = DB::table('invoices')->where('restaurant_id', $targetRestaurantId)->pluck('id');
        $targetExpenseIds = DB::table('expenses')->where('restaurant_id', $targetRestaurantId)->pluck('id');
        $targetEventReservationIds = DB::table('event_reservations')->where('restaurant_id', $targetRestaurantId)->pluck('id');
        $targetScanIds = DB::table('scans')->where('restaurant_id', $targetRestaurantId)->pluck('id');
        $targetScanJobIds = DB::table('scan_jobs')->whereIn('scan_id', $targetScanIds)->pluck('id');
        $targetRoomPlanIds = DB::table('room_plans')->where('restaurant_id', $targetRestaurantId)->pluck('id');
        $targetTableIds = DB::table('restaurant_tables')->where('restaurant_id', $targetRestaurantId)->pluck('id');
        $targetSessionIds = DB::table('table_sessions')->where('restaurant_id', $targetRestaurantId)->pluck('id');
        $targetPayrollPeriodIds = DB::table('payroll_periods')->where('restaurant_id', $targetRestaurantId)->pluck('id');

        DB::table('scan_job_outputs')->whereIn('job_id', $targetScanJobIds)->delete();
        DB::table('scan_jobs')->whereIn('scan_id', $targetScanIds)->delete();
        DB::table('scan_images')->whereIn('scan_id', $targetScanIds)->delete();

        DB::table('event_order_links')->whereIn('event_reservation_id', $targetEventReservationIds)->delete();
        DB::table('event_notification_logs')->whereIn('event_reservation_id', $targetEventReservationIds)->delete();
        DB::table('event_menu_items')->whereIn('event_reservation_id', $targetEventReservationIds)->delete();

        DB::table('expense_attachments')->whereIn('expense_id', $targetExpenseIds)->delete();
        DB::table('invoice_items')->whereIn('invoice_id', $targetInvoiceIds)->delete();

        $targetOrderItemIds = DB::table('order_items')->whereIn('order_id', $targetOrderIds)->pluck('id');
        $targetDishIngredientIds = DB::table('dish_ingredients')->whereIn('dish_id', $targetDishIds)->pluck('id');

        DB::table('order_item_ingredient_usages')->where('restaurant_id', $targetRestaurantId)->delete();
        DB::table('order_items')->whereIn('order_id', $targetOrderIds)->delete();

        DB::table('table_guest_accesses')->whereIn('table_session_id', $targetSessionIds)->delete();
        DB::table('table_waves')->where('restaurant_id', $targetRestaurantId)->delete();
        DB::table('table_sessions')->where('restaurant_id', $targetRestaurantId)->delete();

        DB::table('room_plan_items')->whereIn('room_plan_id', $targetRoomPlanIds)->delete();
        DB::table('restaurant_table_user')->whereIn('restaurant_table_id', $targetTableIds)->delete();
        DB::table('restaurant_user')->where('restaurant_id', $targetRestaurantId)->delete();

        DB::table('dish_related_dishes')
            ->whereIn('dish_id', $targetDishIds)
            ->orWhereIn('related_dish_id', $targetDishIds)
            ->delete();
        DB::table('dish_suggestions')
            ->whereIn('dish_id', $targetDishIds)
            ->orWhereIn('suggested_dish_id', $targetDishIds)
            ->delete();
        DB::table('dish_assets')->whereIn('dish_id', $targetDishIds)->delete();
        DB::table('qr_codes')->whereIn('dish_id', $targetDishIds)->delete();
        DB::table('dish_ingredients')->whereIn('dish_id', $targetDishIds)->delete();

        DB::table('analytics_events')->where('restaurant_id', $targetRestaurantId)->delete();
        DB::table('feature_flag_audit_logs')->where('restaurant_id', $targetRestaurantId)->delete();
        DB::table('restaurant_features')->where('restaurant_id', $targetRestaurantId)->delete();
        DB::table('stock_movements')->where('restaurant_id', $targetRestaurantId)->delete();
        DB::table('expenses')->where('restaurant_id', $targetRestaurantId)->delete();
        DB::table('orders')->where('restaurant_id', $targetRestaurantId)->delete();
        DB::table('event_reservations')->where('restaurant_id', $targetRestaurantId)->delete();
        DB::table('invoices')->where('restaurant_id', $targetRestaurantId)->delete();
        DB::table('reservations')->where('restaurant_id', $targetRestaurantId)->delete();
        DB::table('scans')->where('restaurant_id', $targetRestaurantId)->delete();
        DB::table('payroll_entries')->where('restaurant_id', $targetRestaurantId)->delete();
        DB::table('payroll_periods')->where('restaurant_id', $targetRestaurantId)->delete();
        DB::table('staff_shifts')->where('restaurant_id', $targetRestaurantId)->delete();
        DB::table('room_plans')->where('restaurant_id', $targetRestaurantId)->delete();
        DB::table('restaurant_tables')->where('restaurant_id', $targetRestaurantId)->delete();
        DB::table('dishes')->where('restaurant_id', $targetRestaurantId)->delete();
        DB::table('ingredients')->where('restaurant_id', $targetRestaurantId)->delete();
        DB::table('expense_categories')->where('restaurant_id', $targetRestaurantId)->delete();
        DB::table('vendors')->where('restaurant_id', $targetRestaurantId)->delete();

        unset($targetOrderItemIds, $targetDishIngredientIds, $targetPayrollPeriodIds);
    }

    private function cloneRestaurantData(int $sourceRestaurantId, int $targetRestaurantId, int $targetAdminId, array $userMap): void
    {
        $maps = [
            'dish_assets' => [],
            'dish_ingredients' => [],
            'dish_related_dishes' => [],
            'dish_suggestions' => [],
            'qr_codes' => [],
            'event_reservations' => [],
            'reservations' => [],
            'table_sessions' => [],
            'table_waves' => [],
            'orders' => [],
            'order_items' => [],
            'order_item_ingredient_usages' => [],
            'expenses' => [],
            'expense_attachments' => [],
            'stock_movements' => [],
            'analytics_events' => [],
            'feature_flag_audit_logs' => [],
            'scans' => [],
            'scan_jobs' => [],
            'scan_job_outputs' => [],
            'event_menu_items' => [],
            'event_notification_logs' => [],
            'event_order_links' => [],
            'table_guest_accesses' => [],
        ];

        $maps['ingredients'] = $this->cloneRestaurantScopedTable(
            table: 'ingredients',
            sourceRestaurantId: $sourceRestaurantId,
            targetRestaurantId: $targetRestaurantId
        );
        $maps['expense_categories'] = $this->cloneRestaurantScopedTable(
            table: 'expense_categories',
            sourceRestaurantId: $sourceRestaurantId,
            targetRestaurantId: $targetRestaurantId
        );
        $maps['vendors'] = $this->cloneRestaurantScopedTable(
            table: 'vendors',
            sourceRestaurantId: $sourceRestaurantId,
            targetRestaurantId: $targetRestaurantId
        );
        $this->cloneRestaurantUsers($sourceRestaurantId, $targetRestaurantId, $userMap);
        $maps['restaurant_features'] = $this->cloneRestaurantScopedTable(
            table: 'restaurant_features',
            sourceRestaurantId: $sourceRestaurantId,
            targetRestaurantId: $targetRestaurantId
        );
        $maps['room_plans'] = $this->cloneRestaurantScopedTable(
            table: 'room_plans',
            sourceRestaurantId: $sourceRestaurantId,
            targetRestaurantId: $targetRestaurantId
        );
        $maps['restaurant_tables'] = $this->cloneRestaurantScopedTable(
            table: 'restaurant_tables',
            sourceRestaurantId: $sourceRestaurantId,
            targetRestaurantId: $targetRestaurantId
        );
        $this->cloneRestaurantTableUsers($sourceRestaurantId, $maps['restaurant_tables'], $userMap);
        $maps['dishes'] = $this->cloneRestaurantScopedTable(
            table: 'dishes',
            sourceRestaurantId: $sourceRestaurantId,
            targetRestaurantId: $targetRestaurantId,
            mutator: function (array &$row) use (&$maps): void {
                if (array_key_exists('direct_stock_ingredient_id', $row)) {
                    $row['direct_stock_ingredient_id'] = $this->mapNullableId(
                        $row['direct_stock_ingredient_id'] ?? null,
                        $maps['ingredients']
                    );
                }
            }
        );
        $maps['room_plan_items'] = $this->cloneRowsWithMap(
            table: 'room_plan_items',
            sourceRows: DB::table('room_plan_items')
                ->whereIn('room_plan_id', array_keys($maps['room_plans']))
                ->orderBy('id')
                ->get(),
            mutator: function (array &$row) use (&$maps): void {
                $row['room_plan_id'] = $this->requireMappedId($row['room_plan_id'] ?? null, $maps['room_plans'], 'room_plan_items.room_plan_id');
                $row['restaurant_table_id'] = $this->mapNullableId($row['restaurant_table_id'] ?? null, $maps['restaurant_tables']);
            }
        );
        $maps['invoices'] = $this->cloneRestaurantScopedTable(
            table: 'invoices',
            sourceRestaurantId: $sourceRestaurantId,
            targetRestaurantId: $targetRestaurantId
        );
        $maps['staff_shifts'] = $this->cloneRestaurantScopedTable(
            table: 'staff_shifts',
            sourceRestaurantId: $sourceRestaurantId,
            targetRestaurantId: $targetRestaurantId,
            mutator: function (array &$row) use ($userMap, $targetAdminId): void {
                $this->remapUserColumns('staff_shifts', $row, $userMap, $targetAdminId);
            }
        );
        $maps['payroll_periods'] = $this->cloneRestaurantScopedTable(
            table: 'payroll_periods',
            sourceRestaurantId: $sourceRestaurantId,
            targetRestaurantId: $targetRestaurantId,
            mutator: function (array &$row) use ($userMap, $targetAdminId): void {
                $row['adjustment_of_period_id'] = null;
                $this->remapUserColumns('payroll_periods', $row, $userMap, $targetAdminId);
            }
        );
        foreach (
            DB::table('payroll_periods')
                ->where('restaurant_id', $sourceRestaurantId)
                ->whereNotNull('adjustment_of_period_id')
                ->get(['id', 'adjustment_of_period_id']) as $sourcePeriod
        ) {
            $newPeriodId = $maps['payroll_periods'][$sourcePeriod->id] ?? null;
            $newAdjustmentId = $maps['payroll_periods'][$sourcePeriod->adjustment_of_period_id] ?? null;

            if ($newPeriodId && $newAdjustmentId) {
                DB::table('payroll_periods')
                    ->where('id', $newPeriodId)
                    ->update(['adjustment_of_period_id' => $newAdjustmentId]);
            }
        }
        $maps['payroll_entries'] = $this->cloneRestaurantScopedTable(
            table: 'payroll_entries',
            sourceRestaurantId: $sourceRestaurantId,
            targetRestaurantId: $targetRestaurantId,
            mutator: function (array &$row) use (&$maps, $userMap, $targetAdminId): void {
                $row['payroll_period_id'] = $this->requireMappedId($row['payroll_period_id'] ?? null, $maps['payroll_periods'], 'payroll_entries.payroll_period_id');
                $this->remapUserColumns('payroll_entries', $row, $userMap, $targetAdminId);
            }
        );

        $this->cloneRowsWithoutMap(
            table: 'invoice_items',
            sourceRows: DB::table('invoice_items')
                ->whereIn('invoice_id', array_keys($maps['invoices']))
                ->orderBy('id')
                ->get(),
            mutator: function (array &$row) use (&$maps): void {
                $row['invoice_id'] = $this->requireMappedId($row['invoice_id'] ?? null, $maps['invoices'], 'invoice_items.invoice_id');
            }
        );

        $this->cloneRowsWithoutMap(
            table: 'dish_assets',
            sourceRows: DB::table('dish_assets')
                ->whereIn('dish_id', array_keys($maps['dishes']))
                ->orderBy('id')
                ->get(),
            mutator: function (array &$row) use (&$maps): void {
                $row['dish_id'] = $this->requireMappedId($row['dish_id'] ?? null, $maps['dishes'], 'dish_assets.dish_id');
            }
        );

        $maps['dish_ingredients'] = $this->cloneRowsWithMap(
            table: 'dish_ingredients',
            sourceRows: DB::table('dish_ingredients')
                ->whereIn('dish_id', array_keys($maps['dishes']))
                ->orderBy('id')
                ->get(),
            mutator: function (array &$row) use (&$maps): void {
                $row['dish_id'] = $this->requireMappedId($row['dish_id'] ?? null, $maps['dishes'], 'dish_ingredients.dish_id');
                $row['ingredient_id'] = $this->requireMappedId($row['ingredient_id'] ?? null, $maps['ingredients'], 'dish_ingredients.ingredient_id');
            }
        );

        $this->cloneRowsWithoutMap(
            table: 'dish_related_dishes',
            sourceRows: DB::table('dish_related_dishes')
                ->whereIn('dish_id', array_keys($maps['dishes']))
                ->orderBy('id')
                ->get(),
            mutator: function (array &$row) use (&$maps): void {
                $row['dish_id'] = $this->requireMappedId($row['dish_id'] ?? null, $maps['dishes'], 'dish_related_dishes.dish_id');
                $row['related_dish_id'] = $this->requireMappedId($row['related_dish_id'] ?? null, $maps['dishes'], 'dish_related_dishes.related_dish_id');
            }
        );

        $this->cloneRowsWithoutMap(
            table: 'dish_suggestions',
            sourceRows: DB::table('dish_suggestions')
                ->whereIn('dish_id', array_keys($maps['dishes']))
                ->orderBy('id')
                ->get(),
            mutator: function (array &$row) use (&$maps): void {
                $row['dish_id'] = $this->requireMappedId($row['dish_id'] ?? null, $maps['dishes'], 'dish_suggestions.dish_id');
                $row['suggested_dish_id'] = $this->requireMappedId($row['suggested_dish_id'] ?? null, $maps['dishes'], 'dish_suggestions.suggested_dish_id');
            }
        );

        $this->cloneRowsWithoutMap(
            table: 'qr_codes',
            sourceRows: DB::table('qr_codes')
                ->whereIn('dish_id', array_keys($maps['dishes']))
                ->orderBy('id')
                ->get(),
            mutator: function (array &$row) use (&$maps, $targetRestaurantId): void {
                $row['dish_id'] = $this->requireMappedId($row['dish_id'] ?? null, $maps['dishes'], 'qr_codes.dish_id');
                if (! empty($row['code_url'])) {
                    $row['code_url'] = rtrim((string) $row['code_url'], '/') . '?clone=' . $targetRestaurantId . '-' . Str::uuid()->toString();
                }
            }
        );

        $maps['reservations'] = $this->cloneRestaurantScopedTable(
            table: 'reservations',
            sourceRestaurantId: $sourceRestaurantId,
            targetRestaurantId: $targetRestaurantId,
            mutator: function (array &$row) use (&$maps): void {
                $row['room_plan_id'] = $this->mapNullableId($row['room_plan_id'] ?? null, $maps['room_plans']);
                $row['room_plan_item_id'] = $this->mapNullableId($row['room_plan_item_id'] ?? null, $maps['room_plan_items']);
            }
        );

        $maps['table_sessions'] = $this->cloneRestaurantScopedTable(
            table: 'table_sessions',
            sourceRestaurantId: $sourceRestaurantId,
            targetRestaurantId: $targetRestaurantId,
            mutator: function (array &$row) use (&$maps, $userMap, $targetAdminId): void {
                $row['restaurant_table_id'] = $this->requireMappedId($row['restaurant_table_id'] ?? null, $maps['restaurant_tables'], 'table_sessions.restaurant_table_id');
                $this->remapUserColumns('table_sessions', $row, $userMap, $targetAdminId);
            }
        );

        $this->cloneRowsWithoutMap(
            table: 'table_guest_accesses',
            sourceRows: DB::table('table_guest_accesses')
                ->whereIn('table_session_id', array_keys($maps['table_sessions']))
                ->orderBy('id')
                ->get(),
            mutator: function (array &$row) use (&$maps): void {
                $row['table_session_id'] = $this->requireMappedId($row['table_session_id'] ?? null, $maps['table_sessions'], 'table_guest_accesses.table_session_id');
                $row['token_hash'] = hash('sha256', Str::uuid()->toString());
            }
        );

        $maps['table_waves'] = $this->cloneRestaurantScopedTable(
            table: 'table_waves',
            sourceRestaurantId: $sourceRestaurantId,
            targetRestaurantId: $targetRestaurantId,
            mutator: function (array &$row) use (&$maps, $userMap, $targetAdminId): void {
                $row['restaurant_table_id'] = $this->requireMappedId($row['restaurant_table_id'] ?? null, $maps['restaurant_tables'], 'table_waves.restaurant_table_id');
                $row['table_session_id'] = $this->mapNullableId($row['table_session_id'] ?? null, $maps['table_sessions']);
                $this->remapUserColumns('table_waves', $row, $userMap, $targetAdminId);
            }
        );

        $maps['orders'] = $this->cloneRestaurantScopedTable(
            table: 'orders',
            sourceRestaurantId: $sourceRestaurantId,
            targetRestaurantId: $targetRestaurantId,
            mutator: function (array &$row) use (&$maps, $userMap, $targetAdminId, $targetRestaurantId): void {
                $row['restaurant_table_id'] = $this->mapNullableId($row['restaurant_table_id'] ?? null, $maps['restaurant_tables']);
                $row['table_session_id'] = $this->mapNullableId($row['table_session_id'] ?? null, $maps['table_sessions']);
                if (! empty($row['order_number'])) {
                    $row['order_number'] = (string) $row['order_number'] . '-clone-' . $targetRestaurantId . '-' . Str::random(6);
                }
                $this->remapUserColumns('orders', $row, $userMap, $targetAdminId);
            }
        );

        $maps['order_items'] = $this->cloneRowsWithMap(
            table: 'order_items',
            sourceRows: DB::table('order_items')
                ->whereIn('order_id', array_keys($maps['orders']))
                ->orderBy('id')
                ->get(),
            mutator: function (array &$row) use (&$maps, $userMap, $targetAdminId): void {
                $row['order_id'] = $this->requireMappedId($row['order_id'] ?? null, $maps['orders'], 'order_items.order_id');
                $row['dish_id'] = $this->mapNullableId($row['dish_id'] ?? null, $maps['dishes']);
                $this->remapUserColumns('order_items', $row, $userMap, $targetAdminId);
            }
        );

        $this->cloneRowsWithoutMap(
            table: 'order_item_ingredient_usages',
            sourceRows: DB::table('order_item_ingredient_usages')
                ->where('restaurant_id', $sourceRestaurantId)
                ->orderBy('id')
                ->get(),
            mutator: function (array &$row) use (&$maps, $targetRestaurantId): void {
                $row['restaurant_id'] = $targetRestaurantId;
                $row['order_id'] = $this->requireMappedId($row['order_id'] ?? null, $maps['orders'], 'order_item_ingredient_usages.order_id');
                $row['order_item_id'] = $this->requireMappedId($row['order_item_id'] ?? null, $maps['order_items'], 'order_item_ingredient_usages.order_item_id');
                $row['dish_id'] = $this->mapNullableId($row['dish_id'] ?? null, $maps['dishes']);
                $row['dish_ingredient_id'] = $this->mapNullableId($row['dish_ingredient_id'] ?? null, $maps['dish_ingredients']);
                $row['ingredient_id'] = $this->mapNullableId($row['ingredient_id'] ?? null, $maps['ingredients']);
            }
        );

        $maps['expenses'] = $this->cloneRestaurantScopedTable(
            table: 'expenses',
            sourceRestaurantId: $sourceRestaurantId,
            targetRestaurantId: $targetRestaurantId,
            mutator: function (array &$row) use (&$maps, $userMap, $targetAdminId): void {
                $row['expense_category_id'] = $this->mapNullableId($row['expense_category_id'] ?? null, $maps['expense_categories']);
                $row['vendor_id'] = $this->mapNullableId($row['vendor_id'] ?? null, $maps['vendors']);
                if (array_key_exists('payroll_period_id', $row)) {
                    $row['payroll_period_id'] = $this->mapNullableId($row['payroll_period_id'] ?? null, $maps['payroll_periods']);
                }
                $this->remapUserColumns('expenses', $row, $userMap, $targetAdminId);
            }
        );

        $this->cloneRowsWithoutMap(
            table: 'expense_attachments',
            sourceRows: DB::table('expense_attachments')
                ->whereIn('expense_id', array_keys($maps['expenses']))
                ->orderBy('id')
                ->get(),
            mutator: function (array &$row) use (&$maps): void {
                $row['expense_id'] = $this->requireMappedId($row['expense_id'] ?? null, $maps['expenses'], 'expense_attachments.expense_id');
            }
        );

        $this->cloneRowsWithoutMap(
            table: 'stock_movements',
            sourceRows: DB::table('stock_movements')
                ->where('restaurant_id', $sourceRestaurantId)
                ->orderBy('id')
                ->get(),
            mutator: function (array &$row) use (&$maps, $userMap, $targetAdminId, $targetRestaurantId): void {
                $row['restaurant_id'] = $targetRestaurantId;
                $row['ingredient_id'] = $this->mapNullableId($row['ingredient_id'] ?? null, $maps['ingredients']);
                $row['dish_id'] = $this->mapNullableId($row['dish_id'] ?? null, $maps['dishes']);
                $row['order_id'] = $this->mapNullableId($row['order_id'] ?? null, $maps['orders']);
                $row['order_item_id'] = $this->mapNullableId($row['order_item_id'] ?? null, $maps['order_items']);
                $row['linked_expense_id'] = $this->mapNullableId($row['linked_expense_id'] ?? null, $maps['expenses']);
                $this->remapUserColumns('stock_movements', $row, $userMap, $targetAdminId);
            }
        );

        $maps['event_reservations'] = $this->cloneRestaurantScopedTable(
            table: 'event_reservations',
            sourceRestaurantId: $sourceRestaurantId,
            targetRestaurantId: $targetRestaurantId,
            mutator: function (array &$row) use (&$maps): void {
                $row['invoice_id'] = $this->mapNullableId($row['invoice_id'] ?? null, $maps['invoices']);
                $row['room_plan_id'] = $this->mapNullableId($row['room_plan_id'] ?? null, $maps['room_plans']);
            }
        );

        $this->cloneRowsWithoutMap(
            table: 'event_menu_items',
            sourceRows: DB::table('event_menu_items')
                ->whereIn('event_reservation_id', array_keys($maps['event_reservations']))
                ->orderBy('id')
                ->get(),
            mutator: function (array &$row) use (&$maps): void {
                $row['event_reservation_id'] = $this->requireMappedId($row['event_reservation_id'] ?? null, $maps['event_reservations'], 'event_menu_items.event_reservation_id');
                $row['dish_id'] = $this->requireMappedId($row['dish_id'] ?? null, $maps['dishes'], 'event_menu_items.dish_id');
            }
        );

        $this->cloneRowsWithoutMap(
            table: 'event_notification_logs',
            sourceRows: DB::table('event_notification_logs')
                ->whereIn('event_reservation_id', array_keys($maps['event_reservations']))
                ->orderBy('id')
                ->get(),
            mutator: function (array &$row) use (&$maps): void {
                $row['event_reservation_id'] = $this->requireMappedId($row['event_reservation_id'] ?? null, $maps['event_reservations'], 'event_notification_logs.event_reservation_id');
                $row['dedupe_key'] = Str::uuid()->toString();
            }
        );

        $this->cloneRowsWithoutMap(
            table: 'event_order_links',
            sourceRows: DB::table('event_order_links')
                ->whereIn('event_reservation_id', array_keys($maps['event_reservations']))
                ->orderBy('id')
                ->get(),
            mutator: function (array &$row) use (&$maps): void {
                $row['event_reservation_id'] = $this->requireMappedId($row['event_reservation_id'] ?? null, $maps['event_reservations'], 'event_order_links.event_reservation_id');
                $row['order_id'] = $this->requireMappedId($row['order_id'] ?? null, $maps['orders'], 'event_order_links.order_id');
            }
        );

        $this->cloneRowsWithoutMap(
            table: 'analytics_events',
            sourceRows: DB::table('analytics_events')
                ->where('restaurant_id', $sourceRestaurantId)
                ->orderBy('id')
                ->get(),
            mutator: function (array &$row) use (&$maps, $targetRestaurantId): void {
                $row['restaurant_id'] = $targetRestaurantId;
                $row['dish_id'] = $this->mapNullableId($row['dish_id'] ?? null, $maps['dishes']);
            }
        );

        $this->cloneRowsWithoutMap(
            table: 'feature_flag_audit_logs',
            sourceRows: DB::table('feature_flag_audit_logs')
                ->where('restaurant_id', $sourceRestaurantId)
                ->orderBy('id')
                ->get(),
            mutator: function (array &$row) use ($targetRestaurantId, $userMap, $targetAdminId): void {
                $row['restaurant_id'] = $targetRestaurantId;
                $this->remapUserColumns('feature_flag_audit_logs', $row, $userMap, $targetAdminId);
            }
        );

        $maps['scans'] = $this->cloneRowsWithMap(
            table: 'scans',
            sourceRows: DB::table('scans')
                ->where('restaurant_id', $sourceRestaurantId)
                ->orderBy('id')
                ->get(),
            mutator: function (array &$row) use (&$maps, $targetRestaurantId, $userMap, $targetAdminId): void {
                $row['id'] = Str::uuid()->toString();
                $row['restaurant_id'] = $targetRestaurantId;
                $row['dish_id'] = $this->mapNullableId($row['dish_id'] ?? null, $maps['dishes']);
                $this->remapUserColumns('scans', $row, $userMap, $targetAdminId);
            },
            primaryKey: 'id',
            preservePrimaryKey: true
        );

        $this->cloneRowsWithoutMap(
            table: 'scan_images',
            sourceRows: DB::table('scan_images')
                ->whereIn('scan_id', array_keys($maps['scans']))
                ->orderBy('id')
                ->get(),
            mutator: function (array &$row) use (&$maps): void {
                $row['scan_id'] = $this->requireMappedId($row['scan_id'] ?? null, $maps['scans'], 'scan_images.scan_id');
            }
        );

        $maps['scan_jobs'] = $this->cloneRowsWithMap(
            table: 'scan_jobs',
            sourceRows: DB::table('scan_jobs')
                ->whereIn('scan_id', array_keys($maps['scans']))
                ->orderBy('created_at')
                ->get(),
            mutator: function (array &$row) use (&$maps): void {
                $row['id'] = Str::uuid()->toString();
                $row['scan_id'] = $this->requireMappedId($row['scan_id'] ?? null, $maps['scans'], 'scan_jobs.scan_id');
            },
            primaryKey: 'id',
            preservePrimaryKey: true
        );

        $this->cloneRowsWithoutMap(
            table: 'scan_job_outputs',
            sourceRows: DB::table('scan_job_outputs')
                ->whereIn('job_id', array_keys($maps['scan_jobs']))
                ->orderBy('created_at')
                ->get(),
            mutator: function (array &$row) use (&$maps): void {
                $row['id'] = Str::uuid()->toString();
                $row['job_id'] = $this->requireMappedId($row['job_id'] ?? null, $maps['scan_jobs'], 'scan_job_outputs.job_id');
            },
            preservePrimaryKey: true
        );
    }

    /**
     * @param callable(array<string, mixed>): void|null $mutator
     * @return array<int|string, int|string>
     */
    private function cloneRestaurantScopedTable(
        string $table,
        int $sourceRestaurantId,
        int $targetRestaurantId,
        ?callable $mutator = null,
        string $primaryKey = 'id',
        bool $preservePrimaryKey = false
    ): array {
        return $this->cloneRowsWithMap(
            table: $table,
            sourceRows: DB::table($table)
                ->where('restaurant_id', $sourceRestaurantId)
                ->orderBy($primaryKey)
                ->get(),
            mutator: function (array &$row) use ($table, $targetRestaurantId, $mutator): void {
                $row['restaurant_id'] = $targetRestaurantId;
                if ($mutator) {
                    $mutator($row);
                }
            },
            primaryKey: $primaryKey,
            preservePrimaryKey: $preservePrimaryKey
        );
    }

    /**
     * @param iterable<object> $sourceRows
     * @param callable(array<string, mixed>): void|null $mutator
     * @return array<int|string, int|string>
     */
    private function cloneRowsWithMap(
        string $table,
        iterable $sourceRows,
        ?callable $mutator = null,
        string $primaryKey = 'id',
        bool $preservePrimaryKey = false
    ): array {
        $map = [];

        foreach ($sourceRows as $sourceRow) {
            $row = (array) $sourceRow;
            $oldPrimaryKey = $row[$primaryKey] ?? null;
            $this->refreshUniqueIdentifiers($table, $row);

            if ($mutator) {
                $mutator($row);
            }

            if (! $preservePrimaryKey) {
                unset($row[$primaryKey]);
            }

            $newPrimaryKey = $row[$primaryKey] ?? DB::table($table)->insertGetId($row);
            if ($preservePrimaryKey) {
                DB::table($table)->insert($row);
            }

            if ($oldPrimaryKey !== null) {
                $map[$oldPrimaryKey] = $newPrimaryKey;
            }
        }

        return $map;
    }

    /**
     * @param iterable<object> $sourceRows
     * @param callable(array<string, mixed>): void|null $mutator
     */
    private function cloneRowsWithoutMap(
        string $table,
        iterable $sourceRows,
        ?callable $mutator = null,
        bool $preservePrimaryKey = false
    ): void {
        foreach ($sourceRows as $sourceRow) {
            $row = (array) $sourceRow;
            $this->refreshUniqueIdentifiers($table, $row);

            if ($mutator) {
                $mutator($row);
            }

            if (! $preservePrimaryKey) {
                unset($row['id']);
            }

            DB::table($table)->insert($row);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function refreshUniqueIdentifiers(string $table, array &$row): void
    {
        if (array_key_exists('uuid', $row) && $row['uuid']) {
            $row['uuid'] = Str::uuid()->toString();
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function remapUserColumns(string $table, array &$row, array $userMap, int $targetAdminId): void
    {
        $userColumns = [
            'approved_by',
            'created_by',
            'user_id',
            'created_by_user_id',
            'changed_by_user_id',
            'performed_by',
            'confirmed_by',
            'cancelled_by',
            'accounted_by',
            'kitchen_updated_by',
            'created_by_staff_id',
            'finalized_by_staff_id',
            'resolved_by',
            'employee_id',
            'processed_by',
            'approved_by_staff_id',
        ];

        foreach ($userColumns as $column) {
            if (! array_key_exists($column, $row)) {
                continue;
            }

            $sourceUserId = $row[$column];

            if ($sourceUserId === null) {
                $row[$column] = null;
                continue;
            }

            if (array_key_exists($sourceUserId, $userMap)) {
                $row[$column] = $userMap[$sourceUserId];
                continue;
            }

            $row[$column] = $this->isNullableColumn($table, $column) ? null : $targetAdminId;
        }
    }

    private function isNullableColumn(string $table, string $column): bool
    {
        if (! array_key_exists($table, $this->nullableColumns)) {
            $this->nullableColumns[$table] = DB::table('INFORMATION_SCHEMA.COLUMNS')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', $table)
                ->pluck('IS_NULLABLE', 'COLUMN_NAME')
                ->map(fn ($value) => $value === 'YES')
                ->all();
        }

        return $this->nullableColumns[$table][$column] ?? true;
    }

    /**
     * @param array<int|string, int|string> $map
     */
    private function mapNullableId(mixed $sourceId, array $map): mixed
    {
        if ($sourceId === null) {
            return null;
        }

        if (! array_key_exists($sourceId, $map)) {
            throw new RuntimeException(sprintf('Missing mapped identifier for source id %s.', (string) $sourceId));
        }

        return $map[$sourceId];
    }

    /**
     * @param array<int|string, int|string> $map
     */
    private function requireMappedId(mixed $sourceId, array $map, string $label): mixed
    {
        if ($sourceId === null) {
            throw new RuntimeException(sprintf('Missing required source identifier for %s.', $label));
        }

        if (! array_key_exists($sourceId, $map)) {
            throw new RuntimeException(sprintf('Missing mapped identifier for %s (%s).', $label, (string) $sourceId));
        }

        return $map[$sourceId];
    }

    /**
     * @return array<int, int>
     */
    private function upsertMappedUsers(int $sourceRestaurantId): array
    {
        $sourceUsers = User::query()
            ->whereIn('id', $this->collectSourceUserIds($sourceRestaurantId))
            ->orderBy('id')
            ->get();

        $map = [];

        foreach ($sourceUsers as $sourceUser) {
            $targetEmail = $this->transformUserEmail((string) $sourceUser->email, (int) $sourceUser->id);
            $targetUser = User::query()->firstOrNew(['email' => $targetEmail]);
            $targetUser->name = $sourceUser->name;
            $targetUser->role = $sourceUser->role;
            $targetUser->password = $targetEmail;
            $targetUser->phone = $this->resolveTargetPhone($sourceUser->phone, $targetUser->id ?: null);
            $targetUser->save();

            $map[(int) $sourceUser->id] = (int) $targetUser->id;
        }

        return $map;
    }

    /**
     * @return array<int>
     */
    private function collectSourceUserIds(int $sourceRestaurantId): array
    {
        $ids = collect();

        $ids->push(DB::table('restaurants')->where('id', $sourceRestaurantId)->value('user_id'));
        $ids = $ids
            ->merge(DB::table('restaurant_user')->where('restaurant_id', $sourceRestaurantId)->pluck('user_id'))
            ->merge(DB::table('restaurant_table_user')
                ->join('restaurant_tables', 'restaurant_tables.id', '=', 'restaurant_table_user.restaurant_table_id')
                ->where('restaurant_tables.restaurant_id', $sourceRestaurantId)
                ->pluck('restaurant_table_user.user_id'))
            ->merge(DB::table('orders')->where('restaurant_id', $sourceRestaurantId)->pluck('confirmed_by'))
            ->merge(DB::table('orders')->where('restaurant_id', $sourceRestaurantId)->pluck('cancelled_by'))
            ->merge(DB::table('orders')->where('restaurant_id', $sourceRestaurantId)->pluck('accounted_by'))
            ->merge(DB::table('orders')->where('restaurant_id', $sourceRestaurantId)->pluck('kitchen_updated_by'))
            ->merge(DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.restaurant_id', $sourceRestaurantId)
                ->pluck('approved_by_staff_id'))
            ->merge(DB::table('expenses')->where('restaurant_id', $sourceRestaurantId)->pluck('created_by'))
            ->merge(DB::table('expenses')->where('restaurant_id', $sourceRestaurantId)->pluck('approved_by'))
            ->merge(DB::table('stock_movements')->where('restaurant_id', $sourceRestaurantId)->pluck('performed_by'))
            ->merge(DB::table('table_sessions')->where('restaurant_id', $sourceRestaurantId)->pluck('created_by_staff_id'))
            ->merge(DB::table('table_sessions')->where('restaurant_id', $sourceRestaurantId)->pluck('finalized_by_staff_id'))
            ->merge(DB::table('table_waves')->where('restaurant_id', $sourceRestaurantId)->pluck('resolved_by'))
            ->merge(DB::table('scans')->where('restaurant_id', $sourceRestaurantId)->pluck('created_by_user_id'))
            ->merge(DB::table('feature_flag_audit_logs')->where('restaurant_id', $sourceRestaurantId)->pluck('changed_by_user_id'))
            ->merge(DB::table('staff_shifts')->where('restaurant_id', $sourceRestaurantId)->pluck('user_id'))
            ->merge(DB::table('payroll_periods')->where('restaurant_id', $sourceRestaurantId)->pluck('employee_id'))
            ->merge(DB::table('payroll_periods')->where('restaurant_id', $sourceRestaurantId)->pluck('processed_by'))
            ->merge(DB::table('payroll_entries')->where('restaurant_id', $sourceRestaurantId)->pluck('user_id'));

        return $ids
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function transformUserEmail(string $sourceEmail, int $sourceUserId): string
    {
        $normalized = strtolower(trim($sourceEmail));

        if ($normalized === '') {
            return 'user' . $sourceUserId . '@' . self::TARGET_EMAIL_DOMAIN;
        }

        $localPart = strstr($normalized, '@', true);

        if ($localPart === false || $localPart === '') {
            $localPart = 'user' . $sourceUserId;
        }

        return $localPart . '@' . self::TARGET_EMAIL_DOMAIN;
    }

    private function resolveTargetPhone(?string $sourcePhone, ?int $targetUserId): ?string
    {
        $normalized = is_string($sourcePhone) ? trim($sourcePhone) : null;

        if ($normalized === null || $normalized === '') {
            return null;
        }

        $conflictExists = User::query()
            ->where('phone', $normalized)
            ->when($targetUserId !== null, fn ($query) => $query->where('id', '!=', $targetUserId))
            ->exists();

        return $conflictExists ? null : $normalized;
    }

    private function cloneRestaurantUsers(int $sourceRestaurantId, int $targetRestaurantId, array $userMap): void
    {
        $sourceUserIds = collect();
        $sourceUserIds->push(DB::table('restaurants')->where('id', $sourceRestaurantId)->value('user_id'));
        $sourceUserIds = $sourceUserIds
            ->merge(DB::table('restaurant_user')->where('restaurant_id', $sourceRestaurantId)->pluck('user_id'))
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        foreach ($sourceUserIds as $sourceUserId) {
            $targetUserId = $userMap[$sourceUserId] ?? null;

            if (! $targetUserId) {
                continue;
            }

            DB::table('restaurant_user')->updateOrInsert(
                ['user_id' => $targetUserId],
                [
                    'restaurant_id' => $targetRestaurantId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    private function cloneRestaurantTableUsers(int $sourceRestaurantId, array $tableMap, array $userMap): void
    {
        $rows = DB::table('restaurant_table_user')
            ->join('restaurant_tables', 'restaurant_tables.id', '=', 'restaurant_table_user.restaurant_table_id')
            ->where('restaurant_tables.restaurant_id', $sourceRestaurantId)
            ->orderBy('restaurant_table_user.id')
            ->get([
                'restaurant_table_user.restaurant_table_id',
                'restaurant_table_user.user_id',
                'restaurant_table_user.created_at',
                'restaurant_table_user.updated_at',
            ]);

        foreach ($rows as $row) {
            $targetTableId = $tableMap[(int) $row->restaurant_table_id] ?? null;
            $targetUserId = $userMap[(int) $row->user_id] ?? null;

            if (! $targetTableId || ! $targetUserId) {
                continue;
            }

            DB::table('restaurant_table_user')->updateOrInsert(
                [
                    'restaurant_table_id' => $targetTableId,
                    'user_id' => $targetUserId,
                ],
                [
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]
            );
        }
    }
}
