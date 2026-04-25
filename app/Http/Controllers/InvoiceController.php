<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Restaurant;
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
        $prefix = "FIN-{$datePart}-";

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
                ])
                ->all(),
        ];
    }
}
