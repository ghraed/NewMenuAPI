<?php

namespace Database\Seeders;

use App\Models\Dish;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PayrollPeriod;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AlphaFinanceDemoSeeder extends Seeder
{
    public function run(): void
    {
        $restaurant = Restaurant::query()->where('slug', 'alpha')->first();
        if (! $restaurant) {
            $this->command?->warn('Alpha restaurant not found. Seeder skipped.');
            return;
        }

        $today = Carbon::today();
        $fromDate = $today->copy()->subMonthsNoOverflow(4)->startOfDay();
        $toDate = $today->copy()->endOfDay();

        $this->cleanupTemporaryGeneratedData($restaurant->id, $fromDate, $toDate);
        $seededRevenue = $this->seedSalesInvoices($restaurant->id, $fromDate, $toDate);
        $this->seedOperatingExpenses($restaurant->id, $fromDate, $toDate, $seededRevenue);

        $this->command?->info('Alpha finance demo seeded successfully.');
    }

    private function cleanupTemporaryGeneratedData(int $restaurantId, Carbon $fromDate, Carbon $toDate): void
    {
        Invoice::query()
            ->where('restaurant_id', $restaurantId)
            ->delete();

        Order::query()
            ->where('restaurant_id', $restaurantId)
            ->where(function ($query): void {
                $query->where('order_number', 'like', 'ALP-ORD-%')
                    ->orWhere('notes', 'like', 'Demo seeded order%')
                    ->orWhere('notes', 'like', 'Auto-generated from order%');
            })
            ->delete();

        if (Schema::hasTable('expenses')) {
            Expense::query()
                ->where('restaurant_id', $restaurantId)
                ->delete();
        }

        if (Schema::hasTable('payroll_periods')) {
            PayrollPeriod::query()
                ->where('restaurant_id', $restaurantId)
                ->delete();
        }
    }

    private function seedSalesInvoices(int $restaurantId, Carbon $fromDate, Carbon $toDate): float
    {
        $dishes = Dish::query()
            ->where('restaurant_id', $restaurantId)
            ->whereNotNull('price')
            ->orderBy('id')
            ->get(['id', 'name', 'price']);

        if ($dishes->isEmpty()) {
            $this->command?->warn('No dishes found for Alpha. Skipping sales invoice seeding.');
            return 0.0;
        }

        $staffId = User::query()->where('email', 'admin@alpha.com')->value('id')
            ?? User::query()->orderBy('id')->value('id');
        $tableId = RestaurantTable::query()->where('restaurant_id', $restaurantId)->orderBy('id')->value('id');

        $invoiceSequence = 1;
        $orderSequence = 1;
        $cursor = $fromDate->copy()->setTime(11, 15);
        $recognizedRevenue = 0.0;

        while ($cursor->lte($toDate)) {
            // Heavier sales on Thu/Fri/Sat, lighter on weekdays.
            $weekday = (int) $cursor->dayOfWeekIso;
            $invoiceCountToday = match (true) {
                in_array($weekday, [4, 5, 6], true) => random_int(3, 6),
                $weekday === 7 => random_int(2, 4),
                default => random_int(1, 3),
            };

            for ($i = 0; $i < $invoiceCountToday; $i++) {
                $invoiceAt = $cursor->copy()->setTime(random_int(11, 22), random_int(0, 59), random_int(0, 59));
                if ($invoiceAt->gt($toDate)) {
                    $invoiceAt = $toDate->copy()->subMinutes(random_int(0, 120));
                }

                $orderNumber = sprintf('ALP-ORD-%s-%04d', $invoiceAt->format('Ymd'), $orderSequence);
                $invoiceNumber = sprintf('INV-ALP-%s-%04d', $invoiceAt->format('Ymd'), $invoiceSequence);

                $pickedDishes = $dishes->shuffle()->take(random_int(1, 4))->values();
                $subtotal = 0.0;
                $itemsPayload = [];

                foreach ($pickedDishes as $dish) {
                    $quantity = random_int(1, 3);
                    $unitPrice = (float) $dish->price;
                    $lineSubtotal = round($unitPrice * $quantity, 2);
                    $subtotal += $lineSubtotal;

                    $itemsPayload[] = [
                        'dish_id' => $dish->id,
                        'dish_name' => (string) $dish->name,
                        'unit_price' => number_format($unitPrice, 2, '.', ''),
                        'quantity' => $quantity,
                        'line_subtotal' => number_format($lineSubtotal, 2, '.', ''),
                        'created_at' => $invoiceAt,
                        'updated_at' => $invoiceAt,
                    ];
                }

                $vatRate = 11.0;
                $vatAmount = round($subtotal * 0.11, 2);
                $total = round($subtotal + $vatAmount, 2);

                $statusRoll = random_int(1, 100);
                $invoiceStatus = match (true) {
                    $statusRoll <= 70 => Invoice::STATUS_PAID,
                    $statusRoll <= 92 => Invoice::STATUS_ISSUED,
                    $statusRoll <= 97 => Invoice::STATUS_DRAFT,
                    default => Invoice::STATUS_CANCELLED,
                };

                $orderStatus = match ($invoiceStatus) {
                    Invoice::STATUS_CANCELLED => Order::STATUS_STAFF_CANCELLED,
                    Invoice::STATUS_DRAFT => Order::STATUS_STAFF_CONFIRMED,
                    default => Order::STATUS_ACCOUNTED,
                };

                DB::transaction(function () use (
                    $restaurantId,
                    $tableId,
                    $staffId,
                    $orderNumber,
                    $invoiceNumber,
                    $invoiceAt,
                    $itemsPayload,
                    $subtotal,
                    $vatRate,
                    $vatAmount,
                    $total,
                    $invoiceStatus,
                    $orderStatus
                ): void {
                    $order = Order::query()->create([
                        'uuid' => (string) Str::uuid(),
                        'restaurant_id' => $restaurantId,
                        'restaurant_table_id' => $tableId,
                        'order_number' => $orderNumber,
                        'invoice_number' => $invoiceNumber,
                        'status' => $orderStatus,
                        'kitchen_status' => Order::KITCHEN_STATUS_SERVED,
                        'guest_name' => 'Walk-in Guest',
                        'notes' => 'Demo seeded order',
                        'vat_rate' => number_format($vatRate, 2, '.', ''),
                        'subtotal' => number_format($subtotal, 2, '.', ''),
                        'discount_type' => null,
                        'discount_value' => '0.00',
                        'discount_amount' => '0.00',
                        'taxable_subtotal' => number_format($subtotal, 2, '.', ''),
                        'vat_amount' => number_format($vatAmount, 2, '.', ''),
                        'total' => number_format($total, 2, '.', ''),
                        'confirmed_by' => $staffId,
                        'confirmed_at' => $invoiceAt->copy()->subMinutes(20),
                        'accounted_by' => in_array($invoiceStatus, [Invoice::STATUS_PAID, Invoice::STATUS_ISSUED], true) ? $staffId : null,
                        'accounted_at' => in_array($invoiceStatus, [Invoice::STATUS_PAID, Invoice::STATUS_ISSUED], true) ? $invoiceAt : null,
                        'cancelled_by' => $invoiceStatus === Invoice::STATUS_CANCELLED ? $staffId : null,
                        'cancelled_at' => $invoiceStatus === Invoice::STATUS_CANCELLED ? $invoiceAt : null,
                        'created_at' => $invoiceAt->copy()->subMinutes(35),
                        'updated_at' => $invoiceAt,
                    ]);

                    $order->items()->createMany($itemsPayload);

                    $invoice = Invoice::query()->create([
                        'uuid' => (string) Str::uuid(),
                        'restaurant_id' => $restaurantId,
                        'invoice_number' => $invoiceNumber,
                        'invoice_date' => $invoiceAt->toDateString(),
                        'status' => $invoiceStatus,
                        'subtotal' => number_format($subtotal, 2, '.', ''),
                        'total' => number_format($total, 2, '.', ''),
                        'notes' => 'Food and beverage invoice',
                        'paid_at' => $invoiceStatus === Invoice::STATUS_PAID ? $invoiceAt : null,
                        'created_at' => $invoiceAt->copy()->subMinutes(10),
                        'updated_at' => $invoiceAt,
                    ]);

                    $invoiceItemsPayload = [];
                    foreach ($itemsPayload as $index => $item) {
                        $invoiceItemsPayload[] = [
                            'name' => $item['dish_name'],
                            'quantity' => $item['quantity'],
                            'unit_price' => $item['unit_price'],
                            'line_total' => $item['line_subtotal'],
                            'order_index' => $index,
                            'created_at' => $invoiceAt,
                            'updated_at' => $invoiceAt,
                        ];
                    }

                    $invoice->items()->createMany($invoiceItemsPayload);
                });

                $invoiceSequence++;
                $orderSequence++;

                // Mirrors dashboard logic: draft/issued/paid count as recognized revenue.
                if (in_array($invoiceStatus, [Invoice::STATUS_DRAFT, Invoice::STATUS_ISSUED, Invoice::STATUS_PAID], true)) {
                    $recognizedRevenue += $total;
                }
            }

            $cursor->addDay()->setTime(11, 15);
        }

        return round($recognizedRevenue, 2);
    }

    private function seedOperatingExpenses(int $restaurantId, Carbon $fromDate, Carbon $toDate, float $seededRevenue): void
    {
        if (! Schema::hasTable('expenses') || ! Schema::hasTable('expense_categories') || ! Schema::hasTable('vendors')) {
            $this->command?->warn('Expense tables are missing. Skipping expense seeding.');
            return;
        }

        $staffId = User::query()->where('email', 'admin@alpha.com')->value('id')
            ?? User::query()->orderBy('id')->value('id');

        $categories = [
            ['code' => 'COGS_PRODUCE', 'name' => 'Food Ingredients'],
            ['code' => 'COGS_BEVERAGE', 'name' => 'Beverage Supply'],
            ['code' => 'PAYROLL', 'name' => 'Payroll'],
            ['code' => 'RENT', 'name' => 'Rent'],
            ['code' => 'UTIL', 'name' => 'Utilities'],
            ['code' => 'MAINT', 'name' => 'Maintenance'],
            ['code' => 'MARKETING', 'name' => 'Marketing'],
        ];

        $categoryMap = [];
        foreach ($categories as $row) {
            $category = ExpenseCategory::query()->firstOrCreate(
                [
                    'restaurant_id' => $restaurantId,
                    'code' => $row['code'],
                ],
                [
                    'name' => $row['name'],
                    'is_active' => true,
                ]
            );
            $categoryMap[$row['code']] = $category;
        }

        $vendorNames = [
            'Green Valley Produce',
            'Premium Protein Co.',
            'Blue Harbor Seafood',
            'Metro Utility Services',
            'Urban Facilities Maintenance',
            'Digital Ads Hub',
            'Alpha Payroll Services',
            'Sunrise Dairy Supplier',
        ];

        $vendors = [];
        foreach ($vendorNames as $name) {
            $vendors[] = Vendor::query()->firstOrCreate(
                ['restaurant_id' => $restaurantId, 'name' => $name],
                ['is_active' => true]
            );
        }

        $expenseRows = [];
        $monthCursor = $fromDate->copy()->startOfMonth();
        while ($monthCursor->lte($toDate)) {
            $monthStart = $monthCursor->copy()->startOfMonth();
            $monthEnd = $monthCursor->copy()->endOfMonth()->min($toDate);

            // Fixed monthly expenses.
            $expenseRows[] = $this->expenseRow(
                category: $categoryMap['RENT'],
                vendor: $vendors[4],
                date: $monthStart->copy()->addDays(1),
                amountCents: random_int(380000, 460000),
                taxCents: 0,
                description: 'Monthly restaurant rent',
                paymentMethod: 'bank_transfer',
                status: Expense::STATUS_PAID,
                staffId: $staffId
            );
            $expenseRows[] = $this->expenseRow(
                category: $categoryMap['PAYROLL'],
                vendor: $vendors[6],
                date: $monthStart->copy()->addDays(26),
                amountCents: random_int(950000, 1320000),
                taxCents: 0,
                description: 'Monthly payroll disbursement',
                paymentMethod: 'bank_transfer',
                status: Expense::STATUS_PAID,
                staffId: $staffId
            );
            $expenseRows[] = $this->expenseRow(
                category: $categoryMap['UTIL'],
                vendor: $vendors[3],
                date: $monthStart->copy()->addDays(10),
                amountCents: random_int(90000, 170000),
                taxCents: random_int(2000, 9000),
                description: 'Electricity, water, internet',
                paymentMethod: 'bank_transfer',
                status: Expense::STATUS_PAID,
                staffId: $staffId
            );

            // Weekly purchasing cadence for food and beverages.
            $day = $monthStart->copy()->next(Carbon::MONDAY);
            while ($day->lte($monthEnd)) {
                $expenseRows[] = $this->expenseRow(
                    category: $categoryMap['COGS_PRODUCE'],
                    vendor: $vendors[array_rand($vendors)],
                    date: $day->copy()->setTime(9, random_int(0, 50)),
                    amountCents: random_int(70000, 160000),
                    taxCents: random_int(3000, 9000),
                    description: 'Fresh produce replenishment',
                    paymentMethod: 'bank_transfer',
                    status: Expense::STATUS_PAID,
                    staffId: $staffId
                );
                $expenseRows[] = $this->expenseRow(
                    category: $categoryMap['COGS_BEVERAGE'],
                    vendor: $vendors[array_rand($vendors)],
                    date: $day->copy()->addDays(2)->setTime(10, random_int(0, 50)),
                    amountCents: random_int(50000, 110000),
                    taxCents: random_int(2000, 7000),
                    description: 'Beverage and bar stock order',
                    paymentMethod: 'card',
                    status: Expense::STATUS_PAID,
                    staffId: $staffId
                );

                if (random_int(1, 100) <= 30) {
                    $expenseRows[] = $this->expenseRow(
                        category: $categoryMap['MAINT'],
                        vendor: $vendors[4],
                        date: $day->copy()->addDays(1)->setTime(15, random_int(0, 50)),
                        amountCents: random_int(30000, 95000),
                        taxCents: random_int(1000, 5000),
                        description: 'Kitchen equipment or facilities maintenance',
                        paymentMethod: 'bank_transfer',
                        status: Expense::STATUS_APPROVED,
                        staffId: $staffId
                    );
                }

                if (random_int(1, 100) <= 20) {
                    $expenseRows[] = $this->expenseRow(
                        category: $categoryMap['MARKETING'],
                        vendor: $vendors[5],
                        date: $day->copy()->addDays(3)->setTime(13, random_int(0, 50)),
                        amountCents: random_int(20000, 85000),
                        taxCents: random_int(0, 4000),
                        description: 'Social ads and promotions',
                        paymentMethod: 'card',
                        status: Expense::STATUS_APPROVED,
                        staffId: $staffId
                    );
                }

                $day->addWeek();
            }

            $monthCursor->addMonthNoOverflow()->startOfMonth();
        }

        $expenseRows = array_values(array_filter(
            $expenseRows,
            static fn (array $row): bool => Carbon::parse($row['expense_date'])->lte($toDate)
        ));

        // Enforce a positive net-profit demo profile by capping seeded operating costs
        // to a safe percentage of recognized seeded revenue.
        $rawTotalCents = 0;
        foreach ($expenseRows as $row) {
            $rawTotalCents += (int) ($row['amount_cents'] ?? 0) + (int) ($row['tax_amount_cents'] ?? 0);
        }
        $targetCostCents = (int) round(max(0.0, $seededRevenue) * 100 * 0.62);
        $expenseScale = $rawTotalCents > 0
            ? min(1.0, max(0.05, $targetCostCents / $rawTotalCents))
            : 1.0;

        foreach ($expenseRows as $index => $row) {
            $scaledAmountCents = max(1000, (int) round(((int) ($row['amount_cents'] ?? 0)) * $expenseScale));
            $scaledTaxCents = max(0, (int) round(((int) ($row['tax_amount_cents'] ?? 0)) * $expenseScale));

            Expense::query()->create(array_merge($row, [
                'uuid' => (string) Str::uuid(),
                'reference_no' => sprintf('ALP-EXP-%04d', $index + 1),
                'amount_cents' => $scaledAmountCents,
                'tax_amount_cents' => $scaledTaxCents,
            ]));
        }

        $this->command?->info(sprintf(
            'AlphaFinanceDemoSeeder economics: revenue=$%s, expense-scale=%.4f, target-cost-ratio=62%%',
            number_format($seededRevenue, 2, '.', ','),
            $expenseScale
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function expenseRow(
        ExpenseCategory $category,
        Vendor $vendor,
        Carbon $date,
        int $amountCents,
        int $taxCents,
        string $description,
        string $paymentMethod,
        string $status,
        ?int $staffId
    ): array {
        $dueDate = $date->copy()->addDays(random_int(3, 14));
        if ($dueDate->gt(Carbon::today())) {
            $dueDate = Carbon::today();
        }

        return [
            'restaurant_id' => $category->restaurant_id,
            'expense_category_id' => $category->id,
            'vendor_id' => $vendor->id,
            'expense_date' => $date->toDateString(),
            'amount_cents' => $amountCents,
            'tax_amount_cents' => $taxCents,
            'currency' => 'USD',
            'status' => $status,
            'payment_method' => $paymentMethod,
            'description' => $description,
            'notes' => 'Finance demo seed data',
            'due_date' => $dueDate->toDateString(),
            'paid_at' => $status === Expense::STATUS_PAID ? $date->copy()->setTime(random_int(16, 21), random_int(0, 50)) : null,
            'created_by' => $staffId,
            'approved_by' => in_array($status, [Expense::STATUS_APPROVED, Expense::STATUS_PAID], true) ? $staffId : null,
            'created_at' => $date->copy()->setTime(9, random_int(0, 59)),
            'updated_at' => $date->copy()->setTime(10, random_int(0, 59)),
        ];
    }
}
