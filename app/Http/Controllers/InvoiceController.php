<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'status' => ['nullable', 'in:draft,issued,paid,cancelled'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = Invoice::query()
            ->where('restaurant_id', $restaurant->id)
            ->with('items')
            ->orderByDesc('invoice_date')
            ->orderByDesc('id');

        if (! empty($validated['date_from'])) {
            $query->whereDate('invoice_date', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('invoice_date', '<=', $validated['date_to']);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $perPage = (int) ($validated['per_page'] ?? 80);
        $paginator = $query->paginate($perPage);

        return response()->json([
            'invoices' => collect($paginator->items())
                ->map(fn (Invoice $invoice): array => $this->formatInvoice($invoice))
                ->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        $validated = $request->validate([
            'invoice_date' => ['required', 'date'],
            'status' => ['nullable', 'in:draft,issued,paid,cancelled'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        $normalizedItems = $this->normalizeItems($validated['items']);
        $totals = $this->calculateTotals($normalizedItems);
        $status = $validated['status'] ?? Invoice::STATUS_ISSUED;

        $invoice = DB::transaction(function () use ($restaurant, $validated, $normalizedItems, $totals, $status): Invoice {
            $invoice = $restaurant->invoices()->create([
                'uuid' => (string) Str::uuid(),
                'invoice_number' => $this->generateInvoiceNumber($restaurant),
                'invoice_date' => $validated['invoice_date'],
                'status' => $status,
                'subtotal' => $totals['subtotal'],
                'total' => $totals['total'],
                'notes' => $this->normalizeOptionalString($validated['notes'] ?? null),
                'paid_at' => $status === Invoice::STATUS_PAID ? now() : null,
            ]);

            $invoice->items()->createMany($normalizedItems);

            return $invoice->fresh('items');
        });

        return response()->json([
            'message' => 'Invoice created successfully.',
            'invoice' => $this->formatInvoice($invoice),
        ], 201);
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertInvoiceBelongsToRestaurant($invoice, $restaurant);
        $invoice->loadMissing('items');

        return response()->json([
            'invoice' => $this->formatInvoice($invoice),
        ]);
    }

    public function update(Request $request, Invoice $invoice): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertInvoiceBelongsToRestaurant($invoice, $restaurant);

        $validated = $request->validate([
            'invoice_date' => ['sometimes', 'date'],
            'status' => ['sometimes', 'in:draft,issued,paid,cancelled'],
            'notes' => ['nullable', 'string'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.name' => ['required_with:items', 'string', 'max:255'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'min:0'],
        ]);

        $hasItems = array_key_exists('items', $validated);
        $normalizedItems = $hasItems ? $this->normalizeItems($validated['items']) : [];
        $totals = $hasItems ? $this->calculateTotals($normalizedItems) : null;

        $invoice = DB::transaction(function () use ($invoice, $validated, $hasItems, $normalizedItems, $totals): Invoice {
            $nextStatus = $validated['status'] ?? $invoice->status;
            $isTransitioningToPaid = $invoice->status !== Invoice::STATUS_PAID && $nextStatus === Invoice::STATUS_PAID;
            $isTransitioningOutOfPaid = $invoice->status === Invoice::STATUS_PAID && $nextStatus !== Invoice::STATUS_PAID;

            $payload = [];

            if (array_key_exists('invoice_date', $validated)) {
                $payload['invoice_date'] = $validated['invoice_date'];
            }

            if (array_key_exists('status', $validated)) {
                $payload['status'] = $nextStatus;
            }

            if (array_key_exists('notes', $validated)) {
                $payload['notes'] = $this->normalizeOptionalString($validated['notes']);
            }

            if ($totals !== null) {
                $payload['subtotal'] = $totals['subtotal'];
                $payload['total'] = $totals['total'];
            }

            if ($isTransitioningToPaid) {
                $payload['paid_at'] = now();
            } elseif ($isTransitioningOutOfPaid) {
                $payload['paid_at'] = null;
            }

            if ($payload !== []) {
                $invoice->update($payload);
            }

            if ($hasItems) {
                $invoice->items()->delete();
                $invoice->items()->createMany($normalizedItems);
            }

            return $invoice->fresh('items');
        });

        return response()->json([
            'message' => 'Invoice updated successfully.',
            'invoice' => $this->formatInvoice($invoice),
        ]);
    }

    public function revenueTrends(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        $validated = $request->validate([
            'range' => ['nullable', 'in:daily,monthly,yearly'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $range = $validated['range'] ?? 'monthly';
        [$from, $to] = $this->resolveTrendDateRange(
            $range,
            $validated['date_from'] ?? null,
            $validated['date_to'] ?? null
        );

        [$bucketExpression, $labelFormat] = match ($range) {
            'daily' => ["DATE_FORMAT(invoice_date, '%Y-%m-%d')", 'M d'],
            'yearly' => ["DATE_FORMAT(invoice_date, '%Y')", 'Y'],
            default => ["DATE_FORMAT(invoice_date, '%Y-%m')", 'M Y'],
        };

        $rows = Invoice::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PAID])
            ->whereBetween('invoice_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw($bucketExpression.' AS bucket, SUM(total) AS revenue, COUNT(*) AS invoice_count')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->keyBy('bucket');

        $points = collect($this->generateBuckets($range, $from, $to))
            ->map(function (string $bucket) use ($rows, $labelFormat): array {
                $row = $rows->get($bucket);
                $referenceDate = match (strlen($bucket)) {
                    4 => Carbon::createFromFormat('Y', $bucket)->startOfYear(),
                    7 => Carbon::createFromFormat('Y-m', $bucket)->startOfMonth(),
                    default => Carbon::createFromFormat('Y-m-d', $bucket)->startOfDay(),
                };

                return [
                    'bucket' => $bucket,
                    'label' => $referenceDate->format($labelFormat),
                    'revenue' => round((float) ($row->revenue ?? 0), 2),
                    'invoice_count' => (int) ($row->invoice_count ?? 0),
                ];
            })
            ->values();

        return response()->json([
            'range' => $range,
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'points' => $points,
            'totals' => [
                'revenue' => round((float) $points->sum('revenue'), 2),
                'invoice_count' => (int) $points->sum('invoice_count'),
            ],
        ]);
    }

    public function profitLoss(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
                        'group_by' => ['nullable', 'in:daily,monthly,yearly'],
        ]);

        [$from, $to] = $this->resolveProfitLossDateRange(
            $validated['date_from'] ?? null,
            $validated['date_to'] ?? null
        );

        $groupBy = in_array($validated['group_by'] ?? null, ['daily', 'monthly', 'yearly'], true)
            ? (string) $validated['group_by']
            : 'monthly';

        $revenue = (float) Invoice::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PAID])
            ->whereBetween('invoice_date', [$from->toDateString(), $to->toDateString()])
            ->sum('total');

        $expenseStatuses = ($validated['expense_status'] ?? 'approved_paid') === 'all_non_void'
            ? ['draft', 'approved', 'paid']
            : ['approved', 'paid'];

        $expenseRows = DB::table('expenses')
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('status', $expenseStatuses)
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('expense_category_id, SUM(amount_cents + tax_amount_cents) AS total_cents')
            ->groupBy('expense_category_id')
            ->get();

        $categoryNames = DB::table('expense_categories')
            ->whereIn('id', $expenseRows->pluck('expense_category_id')->filter()->values()->all())
            ->pluck('name', 'id');

        $expenseByCategory = $expenseRows
            ->map(function (object $row) use ($categoryNames): array {
                $categoryId = $row->expense_category_id !== null ? (int) $row->expense_category_id : null;
                $total = round(((float) $row->total_cents) / 100, 2);

                return [
                    'expense_category_id' => $categoryId,
                    'expense_category_name' => $categoryId !== null
                        ? (string) ($categoryNames[$categoryId] ?? 'Uncategorized')
                        : 'Uncategorized',
                    'total' => $total,
                ];
            })
            ->sortByDesc('total')
            ->values();

        $expenseTotal = round((float) $expenseByCategory->sum('total'), 2);
        $cogs = $this->resolveCogsTotal($restaurant->id, $from, $to);
        $profit = round($revenue - $expenseTotal - $cogs, 2);
        $profitMarginPercent = $revenue > 0
            ? round(($profit / $revenue) * 100, 2)
            : 0.0;

        return response()->json([
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'group_by' => $groupBy,
            'revenue' => round($revenue, 2),
            'cogs' => $cogs,
            'gross_profit' => round($revenue - $cogs, 2),
            'operating_expenses' => $expenseTotal,
            'net_profit' => $profit,
            'mode' => [
                'expense_status' => $validated['expense_status'] ?? 'approved_paid',
            ],
            'totals' => [
                'revenue' => round($revenue, 2),
                'expenses' => $expenseTotal,
                'cogs' => $cogs,
                'profit' => $profit,
                'profit_margin_percent' => $profitMarginPercent,
            ],
            'expense_breakdown' => $expenseByCategory,
        ]);
    }

    public function dashboardMetrics(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'expense_status' => ['nullable', 'in:approved_paid,all_non_void'],
        ]);

        [$currentFrom, $currentTo] = $this->resolveDashboardDateRange(
            $validated['date_from'] ?? null,
            $validated['date_to'] ?? null
        );

        $days = $currentFrom->copy()->startOfDay()->diffInDays($currentTo->copy()->startOfDay()) + 1;
        $previousTo = $currentFrom->copy()->subDay()->endOfDay();
        $previousFrom = $previousTo->copy()->subDays($days - 1)->startOfDay();

        $mode = $validated['expense_status'] ?? 'approved_paid';
        $current = $this->aggregateDashboardSnapshot($restaurant->id, $currentFrom, $currentTo, $mode);
        $previous = $this->aggregateDashboardSnapshot($restaurant->id, $previousFrom, $previousTo, $mode);

        return response()->json([
            'date_from' => $currentFrom->toDateString(),
            'date_to' => $currentTo->toDateString(),
            'previous_date_from' => $previousFrom->toDateString(),
            'previous_date_to' => $previousTo->toDateString(),
            'mode' => [
                'expense_status' => $mode,
            ],
            'kpis' => [
                'revenue' => $this->metricPayload($current['revenue'], $previous['revenue']),
                'expenses' => $this->metricPayload($current['expenses'], $previous['expenses']),
                'cogs' => $this->metricPayload($current['cogs'], $previous['cogs']),
                'profit' => $this->metricPayload($current['profit'], $previous['profit']),
                'profit_margin_percent' => $this->metricPayload($current['profit_margin_percent'], $previous['profit_margin_percent']),
                'invoice_count' => $this->metricPayload($current['invoice_count'], $previous['invoice_count']),
                'average_invoice_value' => $this->metricPayload($current['average_invoice_value'], $previous['average_invoice_value']),
            ],
        ]);
    }

    public function taxReport(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'expense_status' => ['nullable', 'in:approved_paid,all_non_void'],
        ]);

        [$from, $to] = $this->resolveTaxDateRange(
            $validated['date_from'] ?? null,
            $validated['date_to'] ?? null
        );

        $expenseStatuses = ($validated['expense_status'] ?? 'approved_paid') === 'all_non_void'
            ? ['draft', 'approved', 'paid']
            : ['approved', 'paid'];

        $taxableSales = round((float) Invoice::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PAID])
            ->whereBetween('invoice_date', [$from->toDateString(), $to->toDateString()])
            ->sum('subtotal'), 2);

        $outputVat = round((float) Invoice::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PAID])
            ->whereBetween('invoice_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('SUM(GREATEST(total - subtotal, 0)) AS output_vat')
            ->value('output_vat'), 2);

        $inputVat = round(((int) DB::table('expenses')
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('status', $expenseStatuses)
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->sum('tax_amount_cents')) / 100, 2);

        $netVat = round($outputVat - $inputVat, 2);

        $invoiceRows = Invoice::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PAID])
            ->whereBetween('invoice_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw("DATE_FORMAT(invoice_date, '%Y-%m') AS bucket, SUM(GREATEST(total - subtotal, 0)) AS output_vat")
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->keyBy('bucket');

        $expenseRows = DB::table('expenses')
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('status', $expenseStatuses)
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw("DATE_FORMAT(expense_date, '%Y-%m') AS bucket, SUM(tax_amount_cents) AS input_vat_cents")
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->keyBy('bucket');

        $breakdown = collect($this->generateBuckets('monthly', $from->copy()->startOfMonth(), $to->copy()->endOfMonth()))
            ->map(function (string $bucket) use ($invoiceRows, $expenseRows): array {
                $output = round((float) ($invoiceRows->get($bucket)->output_vat ?? 0), 2);
                $input = round(((int) ($expenseRows->get($bucket)->input_vat_cents ?? 0)) / 100, 2);

                return [
                    'bucket' => $bucket,
                    'output_vat' => $output,
                    'input_vat' => $input,
                    'net_vat' => round($output - $input, 2),
                ];
            })
            ->values();

        return response()->json([
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'taxable_sales' => $taxableSales,
            'output_vat' => $outputVat,
            'input_vat' => $inputVat,
            'net_vat_payable' => $netVat,
            'mode' => [
                'expense_status' => $validated['expense_status'] ?? 'approved_paid',
            ],
            'totals' => [
                'output_vat' => $outputVat,
                'input_vat' => $inputVat,
                'net_vat' => $netVat,
                'payable_vat' => $netVat > 0 ? $netVat : 0.0,
                'refundable_vat' => $netVat < 0 ? abs($netVat) : 0.0,
            ],
            'breakdown' => $breakdown,
        ]);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array{
     *   name:string,
     *   quantity:string,
     *   unit_price:string,
     *   line_total:string,
     *   order_index:int
     * }>
     */
    private function normalizeItems(array $rows): array
    {
        $normalized = [];

        foreach ($rows as $index => $row) {
            $quantity = round((float) ($row['quantity'] ?? 0), 3);
            $unitPrice = round((float) ($row['unit_price'] ?? 0), 2);
            $lineTotal = round($quantity * $unitPrice, 2);

            $normalized[] = [
                'name' => trim((string) ($row['name'] ?? '')),
                'quantity' => number_format($quantity, 3, '.', ''),
                'unit_price' => number_format($unitPrice, 2, '.', ''),
                'line_total' => number_format($lineTotal, 2, '.', ''),
                'order_index' => (int) $index,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<int,array{
     *   line_total:string
     * }> $normalizedItems
     * @return array{subtotal:string,total:string}
     */
    private function calculateTotals(array $normalizedItems): array
    {
        $subtotal = collect($normalizedItems)->reduce(
            fn (float $carry, array $row): float => $carry + (float) $row['line_total'],
            0.0
        );

        return [
            'subtotal' => number_format(round($subtotal, 2), 2, '.', ''),
            'total' => number_format(round($subtotal, 2), 2, '.', ''),
        ];
    }

    private function getRestaurantForRequest(Request $request): Restaurant
    {
        $user = $request->user();
        $user->loadMissing('restaurant', 'staffRestaurants');

        $restaurant = $user->currentRestaurant();

        if (! $restaurant) {
            abort(403, 'No restaurant is linked to this account');
        }

        return $restaurant;
    }

    private function assertInvoiceBelongsToRestaurant(Invoice $invoice, Restaurant $restaurant): void
    {
        if ((int) $invoice->restaurant_id !== (int) $restaurant->id) {
            abort(404);
        }
    }

    private function generateInvoiceNumber(Restaurant $restaurant): string
    {
        $datePart = now()->format('Ymd');
        $prefix = "INV-{$datePart}-";

        $lastInvoiceNumber = Invoice::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('invoice_number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('invoice_number');

        $nextSequence = 1;
        if (is_string($lastInvoiceNumber) && str_starts_with($lastInvoiceNumber, $prefix)) {
            $tail = substr($lastInvoiceNumber, strlen($prefix));
            if (is_numeric($tail)) {
                $nextSequence = ((int) $tail) + 1;
            }
        }

        return $prefix.str_pad((string) $nextSequence, 4, '0', STR_PAD_LEFT);
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array{0:Carbon,1:Carbon}
     */
    private function resolveTrendDateRange(string $range, ?string $dateFrom, ?string $dateTo): array
    {
        if ($dateFrom !== null && $dateTo !== null) {
            return [
                Carbon::parse($dateFrom)->startOfDay(),
                Carbon::parse($dateTo)->endOfDay(),
            ];
        }

        return match ($range) {
            'daily' => [now()->subDays(13)->startOfDay(), now()->endOfDay()],
            'yearly' => [now()->subYears(4)->startOfYear(), now()->endOfYear()],
            default => [now()->subMonths(11)->startOfMonth(), now()->endOfMonth()],
        };
    }

    /**
     * @return array{0:Carbon,1:Carbon}
     */
    private function resolveProfitLossDateRange(?string $dateFrom, ?string $dateTo): array
    {
        if ($dateFrom !== null && $dateTo !== null) {
            return [
                Carbon::parse($dateFrom)->startOfDay(),
                Carbon::parse($dateTo)->endOfDay(),
            ];
        }

        return [now()->startOfMonth(), now()->endOfMonth()];
    }

    /**
     * @return array{0:Carbon,1:Carbon}
     */
    private function resolveDashboardDateRange(?string $dateFrom, ?string $dateTo): array
    {
        if ($dateFrom !== null && $dateTo !== null) {
            return [
                Carbon::parse($dateFrom)->startOfDay(),
                Carbon::parse($dateTo)->endOfDay(),
            ];
        }

        return [now()->startOfMonth(), now()->endOfMonth()];
    }

    /**
     * @return array{0:Carbon,1:Carbon}
     */
    private function resolveTaxDateRange(?string $dateFrom, ?string $dateTo): array
    {
        if ($dateFrom !== null && $dateTo !== null) {
            return [
                Carbon::parse($dateFrom)->startOfDay(),
                Carbon::parse($dateTo)->endOfDay(),
            ];
        }

        return [now()->startOfMonth(), now()->endOfMonth()];
    }

    /**
     * @return array{
     *   revenue:float,
     *   expenses:float,
     *   cogs:float,
     *   profit:float,
     *   profit_margin_percent:float,
     *   invoice_count:int,
     *   average_invoice_value:float
     * }
     */
    private function aggregateDashboardSnapshot(
        int $restaurantId,
        Carbon $from,
        Carbon $to,
        string $expenseStatusMode
    ): array {
        $invoicesQuery = Invoice::query()
            ->where('restaurant_id', $restaurantId)
            ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PAID])
            ->whereBetween('invoice_date', [$from->toDateString(), $to->toDateString()]);

        $revenue = round((float) (clone $invoicesQuery)->sum('total'), 2);
        $invoiceCount = (int) (clone $invoicesQuery)->count();
        $averageInvoiceValue = $invoiceCount > 0
            ? round($revenue / $invoiceCount, 2)
            : 0.0;

        $expenseStatuses = $expenseStatusMode === 'all_non_void'
            ? ['draft', 'approved', 'paid']
            : ['approved', 'paid'];

        $expenseCents = (int) DB::table('expenses')
            ->where('restaurant_id', $restaurantId)
            ->whereIn('status', $expenseStatuses)
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->sum(DB::raw('amount_cents + tax_amount_cents'));

        $expenses = round($expenseCents / 100, 2);
        $cogs = $this->resolveCogsTotal($restaurantId, $from, $to);
        $profit = round($revenue - $expenses - $cogs, 2);
        $margin = $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0.0;

        return [
            'revenue' => $revenue,
            'expenses' => $expenses,
            'cogs' => $cogs,
            'profit' => $profit,
            'profit_margin_percent' => $margin,
            'invoice_count' => $invoiceCount,
            'average_invoice_value' => $averageInvoiceValue,
        ];
    }

    private function resolveCogsTotal(int $restaurantId, Carbon $from, Carbon $to): float
    {
        $row = StockMovement::query()
            ->where('restaurant_id', $restaurantId)
            ->whereIn('movement_type', [
                StockMovement::TYPE_ORDER_CONSUMPTION,
                StockMovement::TYPE_ORDER_RESTORATION,
            ])
            ->whereBetween('occurred_at', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->selectRaw("
                SUM(
                    CASE
                        WHEN movement_type = ? THEN COALESCE(total_cost_cents, 0)
                        WHEN movement_type = ? THEN -ABS(COALESCE(total_cost_cents, 0))
                        ELSE 0
                    END
                ) AS cogs_cents
            ", [
                StockMovement::TYPE_ORDER_CONSUMPTION,
                StockMovement::TYPE_ORDER_RESTORATION,
            ])
            ->first();

        $cogsCents = (int) ($row?->cogs_cents ?? 0);

        return round(max(0, $cogsCents) / 100, 2);
    }

    /**
     * @return array{value:float|int,previous:float|int,delta:float|int,delta_percent:float|null}
     */
    private function metricPayload(float|int $value, float|int $previous): array
    {
        $delta = round((float) $value - (float) $previous, 2);

        $deltaPercent = null;
        if ((float) $previous !== 0.0) {
            $deltaPercent = round(($delta / abs((float) $previous)) * 100, 2);
        } elseif ((float) $value === 0.0) {
            $deltaPercent = 0.0;
        }

        return [
            'value' => $value,
            'previous' => $previous,
            'delta' => is_int($value) && is_int($previous) ? (int) $delta : $delta,
            'delta_percent' => $deltaPercent,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function generateBuckets(string $range, Carbon $from, Carbon $to): array
    {
        $buckets = [];

        if ($range === 'daily') {
            $cursor = $from->copy()->startOfDay();
            while ($cursor->lte($to)) {
                $buckets[] = $cursor->format('Y-m-d');
                $cursor->addDay();
            }

            return $buckets;
        }

        if ($range === 'yearly') {
            $cursor = $from->copy()->startOfYear();
            while ($cursor->lte($to)) {
                $buckets[] = $cursor->format('Y');
                $cursor->addYear();
            }

            return $buckets;
        }

        $cursor = $from->copy()->startOfMonth();
        while ($cursor->lte($to)) {
            $buckets[] = $cursor->format('Y-m');
            $cursor->addMonth();
        }

        return $buckets;
    }

    private function formatInvoice(Invoice $invoice): array
    {
        $invoice->loadMissing('items');
        $linkedOrders = collect();

        if (is_string($invoice->invoice_number) && trim($invoice->invoice_number) !== '') {
            $linkedOrders = Order::query()
                ->where('restaurant_id', $invoice->restaurant_id)
                ->where('invoice_number', trim($invoice->invoice_number))
                ->where('status', Order::STATUS_ACCOUNTED)
                ->with(['confirmedBy:id,name,email,phone,role'])
                ->orderBy('accounted_at')
                ->orderBy('id')
                ->get();
        }

        $tableReference = $linkedOrders
            ->map(fn (Order $order): ?string => $order->table_reference ?: $order->guest_name)
            ->first(fn (?string $value): bool => is_string($value) && trim($value) !== '');

        $waiter = $linkedOrders
            ->map(fn (Order $order) => $order->confirmedBy)
            ->first(fn ($value): bool => $value !== null);

        $discountTypes = $linkedOrders
            ->pluck('discount_type')
            ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->unique()
            ->values();
        $discountType = $discountTypes->count() === 1 ? (string) $discountTypes->first() : null;
        $discountValue = $discountType === 'percentage'
            ? (float) $linkedOrders->max(fn (Order $order): float => (float) ($order->discount_value ?? 0))
            : (float) $linkedOrders->sum(fn (Order $order): float => (float) ($order->discount_value ?? 0));
        $discountAmount = (float) $linkedOrders->sum(fn (Order $order): float => (float) ($order->discount_amount ?? 0));
        $taxableSubtotal = (float) $linkedOrders->sum(fn (Order $order): float => (float) ($order->taxable_subtotal ?? 0));
        $vatAmount = (float) $linkedOrders->sum(fn (Order $order): float => (float) ($order->vat_amount ?? 0));
        $vatRates = $linkedOrders
            ->map(fn (Order $order): float => (float) ($order->vat_rate ?? 0))
            ->unique()
            ->values();
        $vatRate = $vatRates->count() === 1
            ? (float) $vatRates->first()
            : (float) ($linkedOrders->first()?->vat_rate ?? 0);

        return [
            'id' => $invoice->id,
            'uuid' => $invoice->uuid,
            'restaurant_id' => $invoice->restaurant_id,
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'status' => $invoice->status,
            'subtotal' => $invoice->subtotal,
            'total' => $invoice->total,
            'notes' => $invoice->notes,
            'paid_at' => $invoice->paid_at?->toIso8601String(),
            'table_reference' => $tableReference,
            'waiter_name' => $waiter?->name,
            'waiter' => $waiter ? [
                'id' => $waiter->id,
                'name' => $waiter->name,
                'email' => $waiter->email,
                'phone' => $waiter->phone,
                'role' => $waiter->role,
            ] : null,
            'discount_type' => $discountType,
            'discount_value' => number_format($discountValue, 2, '.', ''),
            'discount_amount' => number_format($discountAmount, 2, '.', ''),
            'taxable_subtotal' => number_format($taxableSubtotal, 2, '.', ''),
            'vat_rate' => number_format($vatRate, 2, '.', ''),
            'vat_amount' => number_format($vatAmount, 2, '.', ''),
            'created_at' => $invoice->created_at?->toIso8601String(),
            'updated_at' => $invoice->updated_at?->toIso8601String(),
            'items' => $invoice->items
                ->sortBy('order_index')
                ->values()
                ->map(fn ($item): array => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'line_total' => $item->line_total,
                    'order_index' => $item->order_index,
                    'order_item_id' => $item->order_item_id,
                    'status' => $item->status ?? 'normal',
                    'compensation_type' => $item->compensation_type ?? 'none',
                    'compensation_reason' => $item->compensation_reason,
                    'complaint_category' => $item->complaint_category,
                    'operational_loss_category' => $item->operational_loss_category,
                    'adjustment_action_type' => $item->adjustment_action_type,
                    'compensation_note' => $item->compensation_note,
                    'approved_by_staff_name' => $item->approved_by_staff_name,
                    'approved_by_staff_role' => $item->approved_by_staff_role,
                    'approved_at' => $item->approved_at?->toIso8601String(),
                    'original_unit_price' => $item->original_unit_price,
                    'final_unit_price' => $item->final_unit_price,
                    'original_line_total' => $item->original_line_total,
                    'partial_discount_percentage' => $item->partial_discount_percentage,
                    'partial_discount_type' => $item->partial_discount_type,
                    'partial_discount_value' => $item->partial_discount_value,
                    'is_complimentary' => (bool) ($item->is_complimentary ?? false),
                    'accounting_bucket' => $item->accounting_bucket,
                    'customer_satisfaction_rating' => $item->customer_satisfaction_rating,
                    'evidence_photo_url' => $item->evidence_photo_url,
                ])
                ->all(),
        ];
    }
}
