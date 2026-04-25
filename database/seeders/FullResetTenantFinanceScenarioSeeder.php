<?php

namespace Database\Seeders;

use App\Models\AnalyticsEvent;
use App\Models\ChatOrder;
use App\Models\Dish;
use App\Models\DishAsset;
use App\Models\DishIngredient;
use App\Models\Ingredient;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemIngredientUsage;
use App\Models\PushSubscription;
use App\Models\QRCode;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Models\Scan;
use App\Models\ScanJob;
use App\Models\StockMovement;
use App\Models\TableSession;
use App\Models\TableWave;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class FullResetTenantFinanceScenarioSeeder extends Seeder
{
    /**
     * Tables are truncated in a dependency-safe order (children -> parents).
     *
     * @var array<int, string>
     */
    private array $tablesToTruncate = [
        'invoice_items',
        'invoices',
        'scan_job_outputs',
        'scan_jobs',
        'scan_images',
        'scans',
        'order_item_ingredient_usages',
        'stock_movements',
        'order_items',
        'orders',
        'table_guest_accesses',
        'table_waves',
        'table_sessions',
        'push_subscriptions',
        'chat_orders',
        'analytics_events',
        'qr_codes',
        'dish_related_dishes',
        'dish_suggestions',
        'dish_ingredients',
        'dish_assets',
        'dishes',
        'restaurant_table_user',
        'restaurant_user',
        'restaurant_domains',
        'ingredients',
        'global_ingredients',
        'restaurant_tables',
        'restaurants',
        'users',
        'personal_access_tokens',
    ];

    public function run(): void
    {
        $this->resetAllData();
        $this->call(RealWorldTenantScenarioSeeder::class);

        $restaurants = Restaurant::query()
            ->whereIn('slug', ['alpha', 'sigma'])
            ->with([
                'user',
                'staffUsers',
                'domains',
                'tables',
                'dishes.dishIngredients',
                'ingredients',
            ])
            ->get();

        foreach ($restaurants as $restaurant) {
            $this->seedDishAssetsAndQrCodes($restaurant);
            $this->seedDishLinks($restaurant);
            $this->seedStaffTableAssignments($restaurant);
            $this->seedFinancialAndOperationalHistory($restaurant);
            $this->seedScans($restaurant);
            $this->seedAnalytics($restaurant);
        }

        $this->seedPushSubscriptions($restaurants);
        $this->seedChatOrders($restaurants);

        $this->command?->info('FullResetTenantFinanceScenarioSeeder completed.');
    }

    private function resetAllData(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ($this->tablesToTruncate as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->truncate();
            }
        }

        Schema::enableForeignKeyConstraints();
    }

    private function seedDishAssetsAndQrCodes(Restaurant $restaurant): void
    {
        $domain = $restaurant->domains->first()?->domain ?? ($restaurant->slug.'.rozer.fun');

        foreach ($restaurant->dishes as $dish) {
            if (! is_string($dish->image_url) || trim($dish->image_url) === '') {
                $dish->image_url = $this->stableDishImageUrl($dish->name);
                $dish->save();
            }

            $asset = DishAsset::query()->firstOrNew([
                'dish_id' => $dish->id,
                'asset_type' => DishAsset::TYPE_PREVIEW_IMAGE,
            ]);

            if (! $asset->exists) {
                $asset->uuid = (string) Str::uuid();
            }

            $asset->storage_disk = 'public';
            $asset->file_path = "external/dishes/{$dish->uuid}/preview.jpg";
            $asset->file_url = $dish->image_url;
            $asset->file_size = 350000;
            $asset->mime_type = 'image/jpeg';
            $asset->metadata = [
                'source' => 'seeded_external_real_image',
                'external_url' => $dish->image_url,
            ];
            $asset->save();

            QRCode::query()->updateOrCreate(
                ['dish_id' => $dish->id],
                [
                    'uuid' => (string) Str::uuid(),
                    'code_url' => sprintf('https://%s/menu/%s/dish/%d', $domain, $restaurant->slug, $dish->id),
                    'qr_data' => null,
                ]
            );
        }
    }

    private function seedDishLinks(Restaurant $restaurant): void
    {
        $dishes = $restaurant->dishes->values();
        $dishCount = $dishes->count();

        if ($dishCount < 5) {
            return;
        }

        foreach ($dishes as $index => $dish) {
            $suggestedIds = collect([
                $dishes[($index + 1) % $dishCount]->id,
                $dishes[($index + 2) % $dishCount]->id,
            ])->filter(fn (int $id): bool => $id !== $dish->id)->values()->all();

            $relatedIds = collect([
                $dishes[($index + 3) % $dishCount]->id,
                $dishes[($index + 4) % $dishCount]->id,
            ])->filter(fn (int $id): bool => $id !== $dish->id)->values()->all();

            $dish->suggestedDishes()->sync($suggestedIds);
            $dish->relatedDishes()->sync($relatedIds);
        }
    }

    private function seedStaffTableAssignments(Restaurant $restaurant): void
    {
        $staff = $restaurant->staffUsers->first();
        if (! $staff) {
            return;
        }

        $tableIds = $restaurant->tables
            ->sortBy('name')
            ->take(6)
            ->pluck('id')
            ->all();

        $staff->assignedTables()->sync($tableIds);
    }

    private function seedFinancialAndOperationalHistory(Restaurant $restaurant): void
    {
        $admin = $restaurant->user;
        $staff = $restaurant->staffUsers->first() ?? $admin;

        if (! $admin || ! $staff) {
            return;
        }

        $tables = $restaurant->tables->values();
        $dishes = $restaurant->dishes->values();
        $ingredientsById = $restaurant->ingredients->keyBy('id');

        if ($tables->isEmpty() || $dishes->isEmpty()) {
            return;
        }

        $this->seedOpeningStockMovements($restaurant, $ingredientsById->values()->all(), $admin);

        $invoiceSequence = 1;
        $sessionsByTable = [];

        for ($i = 0; $i < 180; $i++) {
            /** @var RestaurantTable $table */
            $table = $tables->random();
            $baseDate = now()->subDays(random_int(20, 520))->setTime(random_int(11, 22), random_int(0, 59));

            $statusRoll = random_int(1, 100);
            $invoiceStatus = match (true) {
                $statusRoll <= 65 => Invoice::STATUS_PAID,
                $statusRoll <= 90 => Invoice::STATUS_ISSUED,
                $statusRoll <= 96 => Invoice::STATUS_DRAFT,
                default => Invoice::STATUS_CANCELLED,
            };

            $session = $sessionsByTable[$table->id] ?? null;
            if (! $session || Carbon::parse($session->opened_at)->lt($baseDate->copy()->subHours(8))) {
                $session = TableSession::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'restaurant_id' => $restaurant->id,
                    'restaurant_table_id' => $table->id,
                    'table_number' => $this->tableNumberFromName($table->name),
                    'status' => TableSession::STATUS_CLOSED,
                    'pin_hash' => Hash::make((string) random_int(1000, 9999)),
                    'pin_attempts' => 0,
                    'opened_at' => $baseDate->copy()->subHours(random_int(1, 3)),
                    'last_activity_at' => $baseDate->copy()->subMinutes(random_int(5, 25)),
                    'expires_at' => $baseDate->copy()->addHours(2),
                    'closed_at' => $baseDate->copy()->addMinutes(random_int(20, 70)),
                    'close_reason' => 'finalized',
                    'created_by_staff_id' => $staff->id,
                    'finalized_by_staff_id' => $staff->id,
                ]);

                $sessionsByTable[$table->id] = $session;

                DB::table('table_guest_accesses')->insert([
                    'table_session_id' => $session->id,
                    'token_hash' => hash('sha256', Str::uuid()->toString()),
                    'device_fingerprint' => substr(hash('sha256', $table->name.'-'.$session->id), 0, 64),
                    'joined_at' => $baseDate->copy()->subHours(1),
                    'last_seen_at' => $baseDate->copy()->subMinutes(6),
                    'expires_at' => $baseDate->copy()->addHours(2),
                    'revoked_at' => null,
                    'revoke_reason' => null,
                    'ip_address' => '10.0.'.random_int(1, 254).'.'.random_int(1, 254),
                    'user_agent' => 'SeederGuest/1.0',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $itemCount = random_int(2, 6);
            $selectedDishes = $dishes->shuffle()->take($itemCount)->values();

            $lineItems = [];
            $subtotal = 0.0;

            foreach ($selectedDishes as $dish) {
                $quantity = random_int(1, 3);
                $unitPrice = (float) $dish->price;
                $lineTotal = round($unitPrice * $quantity, 2);

                $lineItems[] = [
                    'dish' => $dish,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_subtotal' => $lineTotal,
                ];

                $subtotal += $lineTotal;
            }

            $vatRate = (float) random_int(8, 12);
            $discountType = random_int(1, 100) <= 30 ? (random_int(0, 1) === 1 ? 'fixed' : 'percentage') : null;
            $discountValue = 0.0;
            if ($discountType === 'fixed') {
                $discountValue = random_int(1, 7);
            } elseif ($discountType === 'percentage') {
                $discountValue = random_int(5, 18);
            }

            $discountAmount = $discountType === 'fixed'
                ? min($subtotal, $discountValue)
                : ($discountType === 'percentage' ? round(($subtotal * $discountValue) / 100, 2) : 0.0);

            $taxableSubtotal = max(0, $subtotal - $discountAmount);
            $vatAmount = round(($taxableSubtotal * $vatRate) / 100, 2);
            $total = round($taxableSubtotal + $vatAmount, 2);

            $orderStatus = match ($invoiceStatus) {
                Invoice::STATUS_CANCELLED => Order::STATUS_STAFF_CANCELLED,
                Invoice::STATUS_DRAFT => Order::STATUS_STAFF_CONFIRMED,
                default => Order::STATUS_ACCOUNTED,
            };

            $order = Order::query()->create([
                'uuid' => (string) Str::uuid(),
                'restaurant_id' => $restaurant->id,
                'restaurant_table_id' => $table->id,
                'table_session_id' => $session->id,
                'order_number' => sprintf(
                    'ORD-%s-%05d',
                    Carbon::parse($baseDate)->format('Ymd'),
                    $invoiceSequence
                ),
                'invoice_number' => $invoiceStatus === Invoice::STATUS_DRAFT
                    ? null
                    : sprintf('INV-%s-%05d', Carbon::parse($baseDate)->format('Ymd'), $invoiceSequence),
                'status' => $orderStatus,
                'guest_name' => $table->name,
                'table_reference' => $table->name,
                'notes' => $this->randomInvoiceNote($invoiceStatus),
                'vat_rate' => number_format($vatRate, 2, '.', ''),
                'subtotal' => number_format($subtotal, 2, '.', ''),
                'discount_type' => $discountType,
                'discount_value' => number_format($discountValue, 2, '.', ''),
                'discount_amount' => number_format($discountAmount, 2, '.', ''),
                'taxable_subtotal' => number_format($taxableSubtotal, 2, '.', ''),
                'vat_amount' => number_format($vatAmount, 2, '.', ''),
                'total' => number_format($total, 2, '.', ''),
                'confirmed_by' => $staff->id,
                'confirmed_at' => $baseDate->copy()->subMinutes(20),
                'cancelled_by' => $invoiceStatus === Invoice::STATUS_CANCELLED ? $staff->id : null,
                'cancelled_at' => $invoiceStatus === Invoice::STATUS_CANCELLED ? $baseDate->copy()->subMinutes(10) : null,
                'accounted_by' => in_array($invoiceStatus, [Invoice::STATUS_PAID, Invoice::STATUS_ISSUED], true) ? $admin->id : null,
                'accounted_at' => in_array($invoiceStatus, [Invoice::STATUS_PAID, Invoice::STATUS_ISSUED], true) ? $baseDate : null,
                'created_at' => $baseDate->copy()->subMinutes(35),
                'updated_at' => $baseDate,
            ]);

            $invoiceItemsPayload = [];
            foreach ($lineItems as $index => $lineItem) {
                $orderItem = OrderItem::query()->create([
                    'order_id' => $order->id,
                    'dish_id' => $lineItem['dish']->id,
                    'dish_name' => $lineItem['dish']->name,
                    'unit_price' => number_format($lineItem['unit_price'], 2, '.', ''),
                    'quantity' => $lineItem['quantity'],
                    'line_subtotal' => number_format($lineItem['line_subtotal'], 2, '.', ''),
                    'created_at' => $baseDate->copy()->subMinutes(30),
                    'updated_at' => $baseDate,
                ]);

                $this->seedIngredientUsageAndStock(
                    restaurant: $restaurant,
                    order: $order,
                    orderItem: $orderItem,
                    dish: $lineItem['dish'],
                    orderItemQuantity: (int) $lineItem['quantity'],
                    ingredientsById: $ingredientsById,
                    actorId: $staff->id,
                    occurredAt: $baseDate->copy()->subMinutes(8)
                );

                $invoiceItemsPayload[] = [
                    'name' => $lineItem['dish']->name,
                    'quantity' => number_format((float) $lineItem['quantity'], 3, '.', ''),
                    'unit_price' => number_format($lineItem['unit_price'], 2, '.', ''),
                    'line_total' => number_format($lineItem['line_subtotal'], 2, '.', ''),
                    'order_index' => $index,
                    'created_at' => $baseDate->copy()->subMinutes(28),
                    'updated_at' => $baseDate,
                ];
            }

            $this->seedInvoiceRecord(
                restaurant: $restaurant,
                order: $order,
                invoiceStatus: $invoiceStatus,
                invoiceDate: $baseDate,
                invoiceItemsPayload: $invoiceItemsPayload,
                sequence: $invoiceSequence
            );

            TableWave::query()->create([
                'uuid' => (string) Str::uuid(),
                'restaurant_id' => $restaurant->id,
                'restaurant_table_id' => $table->id,
                'table_session_id' => $session->id,
                'status' => TableWave::STATUS_RESOLVED,
                'request_type' => random_int(1, 100) <= 60
                    ? TableWave::REQUEST_TYPE_REQUEST_BILL
                    : TableWave::REQUEST_TYPE_CALL_WAITER,
                'table_reference' => $table->name,
                'resolved_by' => $staff->id,
                'resolved_at' => $baseDate->copy()->subMinutes(5),
                'created_at' => $baseDate->copy()->subMinutes(12),
                'updated_at' => $baseDate->copy()->subMinutes(5),
            ]);

            $invoiceSequence++;
        }
    }

    /**
     * @param array<int, Ingredient> $ingredients
     */
    private function seedOpeningStockMovements(Restaurant $restaurant, array $ingredients, User $admin): void
    {
        $openingTime = now()->subDays(620);

        foreach ($ingredients as $ingredient) {
            StockMovement::query()->create([
                'restaurant_id' => $restaurant->id,
                'ingredient_id' => $ingredient->id,
                'order_id' => null,
                'order_item_id' => null,
                'performed_by' => $admin->id,
                'movement_type' => StockMovement::TYPE_OPENING_BALANCE,
                'unit' => $ingredient->stock_unit,
                'quantity_delta' => number_format((float) $ingredient->current_stock_quantity, 3, '.', ''),
                'quantity_before' => number_format(0, 3, '.', ''),
                'quantity_after' => number_format((float) $ingredient->current_stock_quantity, 3, '.', ''),
                'ingredient_name_snapshot' => $ingredient->name,
                'reference' => 'SEED-OPENING',
                'notes' => 'Seed opening stock balance.',
                'occurred_at' => $openingTime,
                'created_at' => $openingTime,
                'updated_at' => $openingTime,
            ]);
        }
    }

    /**
     * @param \Illuminate\Support\Collection<int, Ingredient> $ingredientsById
     */
    private function seedIngredientUsageAndStock(
        Restaurant $restaurant,
        Order $order,
        OrderItem $orderItem,
        Dish $dish,
        int $orderItemQuantity,
        $ingredientsById,
        int $actorId,
        Carbon $occurredAt
    ): void {
        $dishIngredients = DishIngredient::query()
            ->where('dish_id', $dish->id)
            ->orderBy('order_index')
            ->take(3)
            ->get();

        foreach ($dishIngredients as $dishIngredient) {
            $ingredient = $ingredientsById->get($dishIngredient->ingredient_id);
            if (! $ingredient) {
                continue;
            }

            $recipeQty = (float) $dishIngredient->quantity;
            $consumed = round($recipeQty * $orderItemQuantity, 3);
            $before = (float) $ingredient->current_stock_quantity;
            $after = max(0, round($before - $consumed, 3));

            OrderItemIngredientUsage::query()->updateOrCreate(
                [
                    'order_item_id' => $orderItem->id,
                    'ingredient_id' => $ingredient->id,
                ],
                [
                    'restaurant_id' => $restaurant->id,
                    'order_id' => $order->id,
                    'dish_id' => $dish->id,
                    'dish_ingredient_id' => $dishIngredient->id,
                    'ingredient_name_snapshot' => $ingredient->name,
                    'unit' => $dishIngredient->unit,
                    'recipe_quantity_per_dish' => number_format($recipeQty, 3, '.', ''),
                    'order_item_quantity' => $orderItemQuantity,
                    'consumed_quantity' => number_format($consumed, 3, '.', ''),
                    'created_at' => $occurredAt,
                    'updated_at' => $occurredAt,
                ]
            );

            StockMovement::query()->create([
                'restaurant_id' => $restaurant->id,
                'ingredient_id' => $ingredient->id,
                'order_id' => $order->id,
                'order_item_id' => $orderItem->id,
                'performed_by' => $actorId,
                'movement_type' => StockMovement::TYPE_ORDER_CONSUMPTION,
                'unit' => $ingredient->stock_unit,
                'quantity_delta' => number_format($consumed * -1, 3, '.', ''),
                'quantity_before' => number_format($before, 3, '.', ''),
                'quantity_after' => number_format($after, 3, '.', ''),
                'ingredient_name_snapshot' => $ingredient->name,
                'reference' => 'ORDER-'.$order->id,
                'notes' => 'Auto consumption for seeded order.',
                'occurred_at' => $occurredAt,
                'created_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ]);

            $ingredient->current_stock_quantity = number_format($after, 3, '.', '');
            $ingredient->save();
        }
    }

    /**
     * @param array<int, array{name:string,quantity:string,unit_price:string,line_total:string,order_index:int,created_at:Carbon,updated_at:Carbon}> $invoiceItemsPayload
     */
    private function seedInvoiceRecord(
        Restaurant $restaurant,
        Order $order,
        string $invoiceStatus,
        Carbon $invoiceDate,
        array $invoiceItemsPayload,
        int $sequence
    ): void {
        if (! Schema::hasTable('invoices') || ! Schema::hasTable('invoice_items')) {
            return;
        }

        $invoiceNumber = sprintf(
            'FIN-%s-%s-%04d',
            strtoupper(substr($restaurant->slug, 0, 3)),
            $invoiceDate->format('Ymd'),
            $sequence
        );

        $invoice = Invoice::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'invoice_number' => $invoiceNumber,
            'invoice_date' => $invoiceDate->toDateString(),
            'status' => $invoiceStatus,
            'subtotal' => $order->subtotal,
            'total' => $order->total,
            'notes' => $this->randomInvoiceNote($invoiceStatus),
            'paid_at' => $invoiceStatus === Invoice::STATUS_PAID ? $invoiceDate->copy()->addMinutes(random_int(8, 28)) : null,
            'created_at' => $invoiceDate->copy()->subMinutes(3),
            'updated_at' => $invoiceDate,
        ]);

        $invoice->items()->createMany($invoiceItemsPayload);
    }

    private function seedScans(Restaurant $restaurant): void
    {
        if (! Schema::hasTable('scans')) {
            return;
        }

        $creator = $restaurant->staffUsers->first() ?? $restaurant->user;
        if (! $creator) {
            return;
        }

        $dishes = $restaurant->dishes->shuffle()->take(6);
        foreach ($dishes as $dish) {
            $scanId = (string) Str::uuid();
            $jobId = (string) Str::uuid();

            DB::table('scans')->insert([
                'id' => $scanId,
                'device_id' => 'seed-device-'.substr($scanId, 0, 8),
                'restaurant_id' => $restaurant->id,
                'created_by_user_id' => $creator->id,
                'dish_id' => $dish->id,
                'target_type' => 'dish',
                'scale_meters' => 0.240,
                'slots_total' => 24,
                'status' => 'completed',
                'created_at' => now()->subDays(random_int(10, 220)),
                'updated_at' => now()->subDays(random_int(2, 30)),
            ]);

            DB::table('scan_jobs')->insert([
                'id' => $jobId,
                'scan_id' => $scanId,
                'type' => 'model',
                'status' => 'done',
                'progress' => 1,
                'message' => 'Seeded completed scan job.',
                'meta' => json_encode(['source' => 'seeder'], JSON_UNESCAPED_UNICODE),
                'created_at' => now()->subDays(random_int(10, 220)),
                'updated_at' => now()->subDays(random_int(2, 30)),
            ]);

            for ($slot = 0; $slot < 6; $slot++) {
                DB::table('scan_images')->insert([
                    'id' => (string) Str::uuid(),
                    'scan_id' => $scanId,
                    'slot' => $slot,
                    'heading' => number_format($slot * 60, 3, '.', ''),
                    'path_original' => "seed/scans/{$scanId}/images/slot-{$slot}-original.jpg",
                    'path_mask' => null,
                    'path_rgba' => null,
                    'created_at' => now()->subDays(random_int(10, 220)),
                    'updated_at' => now()->subDays(random_int(2, 30)),
                ]);
            }

            DB::table('scan_job_outputs')->insert([
                'id' => (string) Str::uuid(),
                'job_id' => $jobId,
                'glb_path' => "seed/scans/{$scanId}/model.glb",
                'usdz_path' => "seed/scans/{$scanId}/model.usdz",
                'preview_path' => "seed/scans/{$scanId}/preview.jpg",
                'obj_path' => null,
                'created_at' => now()->subDays(random_int(10, 220)),
                'updated_at' => now()->subDays(random_int(2, 30)),
            ]);
        }
    }

    private function seedAnalytics(Restaurant $restaurant): void
    {
        $dishes = $restaurant->dishes->shuffle()->take(30);
        $eventTypes = ['page_view', 'dish_view', '3d_viewer_opened', 'ar_launch_attempt', 'ar_launch_success'];

        foreach ($dishes as $dish) {
            $eventsPerDish = random_int(8, 22);
            for ($i = 0; $i < $eventsPerDish; $i++) {
                AnalyticsEvent::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'dish_id' => $dish->id,
                    'restaurant_id' => $restaurant->id,
                    'event_type' => $eventTypes[array_rand($eventTypes)],
                    'device_type' => ['mobile', 'desktop', 'tablet'][array_rand(['mobile', 'desktop', 'tablet'])],
                    'platform' => ['ios', 'android', 'web'][array_rand(['ios', 'android', 'web'])],
                    'user_agent' => 'SeederAnalytics/1.0',
                    'ip_address' => '172.16.'.random_int(1, 250).'.'.random_int(1, 250),
                    'created_at' => now()->subDays(random_int(1, 420)),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * @param \Illuminate\Database\Eloquent\Collection<int, Restaurant> $restaurants
     */
    private function seedPushSubscriptions($restaurants): void
    {
        if (! Schema::hasTable('push_subscriptions')) {
            return;
        }

        $users = collect();
        foreach ($restaurants as $restaurant) {
            if ($restaurant->user) {
                $users->push($restaurant->user);
            }
            foreach ($restaurant->staffUsers as $staff) {
                $users->push($staff);
            }
        }

        $users = $users->unique('id')->values();
        foreach ($users as $index => $user) {
            PushSubscription::query()->updateOrCreate(
                ['endpoint' => sprintf('https://push.example.test/sub/%d/%d', $user->id, $index + 1)],
                [
                    'user_id' => $user->id,
                    'public_key' => 'seed-public-key-'.$user->id,
                    'auth_token' => 'seed-auth-token-'.$user->id,
                    'content_encoding' => 'aes128gcm',
                    'user_agent' => 'SeederPush/1.0',
                    'last_used_at' => now()->subDays(random_int(1, 20)),
                ]
            );
        }
    }

    /**
     * @param \Illuminate\Database\Eloquent\Collection<int, Restaurant> $restaurants
     */
    private function seedChatOrders($restaurants): void
    {
        if (! Schema::hasTable('chat_orders')) {
            return;
        }

        $statuses = ['pending', 'confirmed', 'cancelled'];
        foreach ($restaurants as $restaurant) {
            $sampleDishes = $restaurant->dishes->shuffle()->take(3)->values();
            if ($sampleDishes->isEmpty()) {
                continue;
            }

            for ($i = 0; $i < 5; $i++) {
                $picked = $sampleDishes->shuffle()->take(random_int(1, min(3, $sampleDishes->count())));
                $items = $picked->map(fn (Dish $dish): array => [
                    'dish_id' => $dish->id,
                    'name' => $dish->name,
                    'quantity' => random_int(1, 3),
                    'price' => (float) $dish->price,
                ])->values()->all();

                ChatOrder::query()->create([
                    'items' => $items,
                    'status' => $statuses[array_rand($statuses)],
                    'user_session_id' => sprintf('%s-chat-%d', $restaurant->slug, $i + 1),
                    'created_at' => now()->subDays(random_int(1, 120)),
                    'updated_at' => now()->subDays(random_int(0, 30)),
                ]);
            }
        }
    }

    private function randomInvoiceNote(string $status): string
    {
        return match ($status) {
            Invoice::STATUS_PAID => [
                'Paid by card at cashier.',
                'Settled in full by table host.',
                'Paid and closed after service check.',
            ][array_rand([0, 1, 2])],
            Invoice::STATUS_ISSUED => [
                'Issued to accounting queue.',
                'Awaiting final settlement confirmation.',
                'Issued and pending payment posting.',
            ][array_rand([0, 1, 2])],
            Invoice::STATUS_DRAFT => [
                'Draft invoice for manager review.',
                'Draft pending item verification.',
                'Draft awaiting pricing confirmation.',
            ][array_rand([0, 1, 2])],
            default => [
                'Cancelled due to guest change request.',
                'Cancelled after duplicate bill issue.',
                'Cancelled and replaced by corrected invoice.',
            ][array_rand([0, 1, 2])],
        };
    }

    private function stableDishImageUrl(string $name): string
    {
        $keywords = trim((string) preg_replace('/[^a-z0-9 ]+/i', ' ', strtolower($name)));
        $keywords = preg_replace('/\s+/', ',', $keywords) ?: 'restaurant,dish';

        return sprintf('https://source.unsplash.com/1600x900/?food,%s', urlencode((string) $keywords));
    }

    private function tableNumberFromName(string $name): int
    {
        if (preg_match('/(\d+)/', $name, $matches) === 1) {
            return max(1, (int) $matches[1]);
        }

        return 1;
    }
}
