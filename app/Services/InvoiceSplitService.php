<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TableSession;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class InvoiceSplitService
{
    public const MODE_NONE = 'none';
    public const MODE_EQUAL = 'equal';
    public const MODE_BY_PERSON_ORDER = 'by_person_order';

    public function normalizeMode(?string $mode): string
    {
        return in_array($mode, [self::MODE_NONE, self::MODE_EQUAL, self::MODE_BY_PERSON_ORDER], true)
            ? $mode
            : self::MODE_NONE;
    }

    /**
     * @param Collection<int, Order> $orders
     * @return array<string, mixed>
     */
    public function buildPayload(TableSession $session, Collection $orders, bool $enabled): array
    {
        $editableItems = $this->buildEditableItems($orders);

        if (! $enabled) {
            return [
                'enabled' => false,
                'mode' => null,
                'split_count' => null,
                'breakdown' => [],
                'people' => [],
                'editable_items' => $editableItems,
                'remaining_items' => [],
                'is_complete' => false,
            ];
        }

        $mode = $this->normalizeMode($session->invoice_split_mode);
        $splitCount = is_numeric($session->invoice_split_count)
            ? max((int) $session->invoice_split_count, 1)
            : null;

        if ($mode === self::MODE_EQUAL) {
            return [
                'enabled' => true,
                'mode' => $mode,
                'split_count' => $splitCount,
                'breakdown' => $splitCount !== null && $splitCount >= 2
                    ? $this->equalBreakdown($orders, $splitCount)
                    : [],
                'people' => [],
                'editable_items' => $editableItems,
                'remaining_items' => [],
                'is_complete' => $splitCount !== null && $splitCount >= 2,
            ];
        }

        if ($mode === self::MODE_BY_PERSON_ORDER) {
            $effectiveSplitCount = $splitCount ?? 1;
            $normalizedPeople = $this->normalizePeopleAllocations(
                $editableItems,
                is_array($session->invoice_split_allocations) ? $session->invoice_split_allocations : [],
                $effectiveSplitCount,
                false
            );

            [$people, $remainingItems, $remainingSummary, $complete] = $this->buildPeopleAndRemaining(
                $orders,
                $editableItems,
                $normalizedPeople,
                $effectiveSplitCount
            );

            return [
                'enabled' => true,
                'mode' => $mode,
                'split_count' => $effectiveSplitCount,
                'breakdown' => $people
                    ? array_map(fn (array $person): array => [
                        'key' => 'person-'.$person['person_index'],
                        'label' => $person['label'],
                        'amount' => $person['total'],
                    ], $people)
                    : [],
                'people' => $people,
                'editable_items' => $editableItems,
                'remaining_items' => $remainingItems,
                'remaining_summary' => $remainingSummary,
                'is_complete' => $complete,
            ];
        }

        return [
            'enabled' => true,
            'mode' => self::MODE_NONE,
            'split_count' => null,
            'breakdown' => [],
            'people' => [],
            'editable_items' => $editableItems,
            'remaining_items' => $editableItems
                ? array_map(fn (array $item): array => [
                    ...$item,
                    'remaining_quantity' => $item['quantity'],
                    'line_subtotal' => $item['line_subtotal'],
                ], $editableItems)
                : [],
            'remaining_summary' => $this->emptySummary(),
            'is_complete' => false,
        ];
    }

    /**
     * @param Collection<int, Order> $orders
     * @param array<int, array<string,mixed>>|null $people
     */
    public function applySplitSettings(
        TableSession $session,
        Collection $orders,
        string $mode,
        ?int $splitCount,
        ?array $people
    ): void {
        if (! $this->splitColumnsExist()) {
            throw ValidationException::withMessages([
                'invoice_split' => 'Invoice split storage is not ready yet. Please run the latest database migrations.',
            ]);
        }

        $normalizedMode = $this->normalizeMode($mode);
        $editableItems = $this->buildEditableItems($orders);

        try {
            if ($normalizedMode === self::MODE_NONE) {
                $session->update([
                    'invoice_split_mode' => self::MODE_NONE,
                    'invoice_split_count' => null,
                    'invoice_split_allocations' => null,
                ]);
                return;
            }

            if ($normalizedMode === self::MODE_EQUAL) {
                if ($splitCount === null || $splitCount < 2) {
                    throw ValidationException::withMessages([
                        'split_count' => 'split_count is required and must be at least 2 when mode is equal.',
                    ]);
                }

                $session->update([
                    'invoice_split_mode' => self::MODE_EQUAL,
                    'invoice_split_count' => $splitCount,
                    'invoice_split_allocations' => null,
                ]);
                return;
            }

            $effectiveSplitCount = $splitCount ?? 0;
            if ($effectiveSplitCount < 1) {
                throw ValidationException::withMessages([
                    'split_count' => 'split_count is required and must be at least 1 when mode is by_person_order.',
                ]);
            }

            $normalizedPeople = $this->normalizePeopleAllocations(
                $editableItems,
                is_array($people) ? $people : [],
                $effectiveSplitCount,
                true
            );

            $session->update([
                'invoice_split_mode' => self::MODE_BY_PERSON_ORDER,
                'invoice_split_count' => $effectiveSplitCount,
                'invoice_split_allocations' => $normalizedPeople,
            ]);
        } catch (QueryException $exception) {
            if ($this->isMissingSplitColumnException($exception)) {
                throw ValidationException::withMessages([
                    'invoice_split' => 'Invoice split storage is not ready yet. Please run the latest database migrations.',
                ]);
            }

            throw $exception;
        }
    }

    /**
     * @param Collection<int, Order> $orders
     * @return array<int, array{order_id:int,order_item_id:int,key:string,dish_name:string,quantity:int,unit_price:string,line_subtotal:string}>
     */
    private function buildEditableItems(Collection $orders): array
    {
        return $orders
            ->flatMap(fn (Order $order) => $order->items->map(fn (OrderItem $item): array => [
                'order_id' => (int) $order->id,
                'order_item_id' => (int) $item->id,
                'key' => 'order-item-'.$item->id,
                'dish_name' => $item->dish_name,
                'quantity' => (int) $item->quantity,
                'unit_price' => Money::normalizeDecimal($item->final_unit_price ?? $item->unit_price, 2),
                'line_subtotal' => Money::normalizeDecimal($item->line_subtotal, 2),
            ]))
            ->values()
            ->all();
    }

    /**
     * @param array<int, array<string,mixed>> $editableItems
     * @param array<int, array<string,mixed>> $people
     * @return array<int, array{person_index:int,items:array<int,array{order_item_id:int,quantity:int}>}>
     */
    private function normalizePeopleAllocations(
        array $editableItems,
        array $people,
        int $splitCount,
        bool $strict
    ): array {
        $availableByOrderItemId = [];
        foreach ($editableItems as $item) {
            $availableByOrderItemId[(int) $item['order_item_id']] = (int) $item['quantity'];
        }

        $assignedByOrderItemId = [];
        $peopleMap = [];

        foreach ($people as $personInput) {
            if (! is_array($personInput)) {
                if ($strict) {
                    throw ValidationException::withMessages([
                        'people' => 'Each person entry must be an object.',
                    ]);
                }
                continue;
            }

            $personIndex = isset($personInput['person_index']) ? (int) $personInput['person_index'] : 0;
            if ($personIndex < 1 || $personIndex > $splitCount) {
                if ($strict) {
                    throw ValidationException::withMessages([
                        'people' => "person_index must be between 1 and {$splitCount}.",
                    ]);
                }
                continue;
            }

            $personItems = [];
            $itemsInput = is_array($personInput['items'] ?? null) ? $personInput['items'] : [];
            foreach ($itemsInput as $itemInput) {
                $orderItemId = isset($itemInput['order_item_id']) ? (int) $itemInput['order_item_id'] : 0;
                $quantity = isset($itemInput['quantity']) ? (int) $itemInput['quantity'] : 0;

                if ($quantity <= 0) {
                    continue;
                }

                if (! array_key_exists($orderItemId, $availableByOrderItemId)) {
                    if ($strict) {
                        throw ValidationException::withMessages([
                            'people' => "order_item_id {$orderItemId} is not valid for this table session.",
                        ]);
                    }
                    continue;
                }

                $nextAssigned = ($assignedByOrderItemId[$orderItemId] ?? 0) + $quantity;
                if ($nextAssigned > $availableByOrderItemId[$orderItemId]) {
                    if ($strict) {
                        throw ValidationException::withMessages([
                            'people' => "Assigned quantity for order_item_id {$orderItemId} exceeds available quantity.",
                        ]);
                    }
                    continue;
                }

                $assignedByOrderItemId[$orderItemId] = $nextAssigned;
                $personItems[$orderItemId] = ($personItems[$orderItemId] ?? 0) + $quantity;
            }

            $peopleMap[$personIndex] = [
                'person_index' => $personIndex,
                'items' => collect($personItems)
                    ->map(fn (int $quantity, int $orderItemId): array => [
                        'order_item_id' => $orderItemId,
                        'quantity' => $quantity,
                    ])
                    ->values()
                    ->all(),
            ];
        }

        $normalized = [];
        for ($personIndex = 1; $personIndex <= $splitCount; $personIndex++) {
            $normalized[] = $peopleMap[$personIndex] ?? [
                'person_index' => $personIndex,
                'items' => [],
            ];
        }

        return $normalized;
    }

    /**
     * @param Collection<int, Order> $orders
     * @param array<int, array<string,mixed>> $editableItems
     * @param array<int, array{person_index:int,items:array<int,array{order_item_id:int,quantity:int}>}> $normalizedPeople
     * @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>,2:array<string,string>,3:bool}
     */
    private function buildPeopleAndRemaining(Collection $orders, array $editableItems, array $normalizedPeople, int $splitCount): array
    {
        $editableByOrderItemId = [];
        foreach ($editableItems as $item) {
            $editableByOrderItemId[(int) $item['order_item_id']] = $item;
        }

        $assignedByOrderItemId = [];
        $personRows = [];
        $personSubtotalByOrder = [];
        $people = [];
        $remainingSummaryCents = $this->emptySummaryCents();

        foreach ($normalizedPeople as $person) {
            $personItems = [];
            $personIndex = (int) $person['person_index'];

            foreach ($person['items'] as $assignment) {
                $orderItemId = (int) $assignment['order_item_id'];
                $quantity = (int) $assignment['quantity'];
                $editableItem = $editableByOrderItemId[$orderItemId] ?? null;
                if (! $editableItem || $quantity <= 0) {
                    continue;
                }

                $lineSubtotalCents = Money::toCents($editableItem['unit_price']) * $quantity;
                $orderId = (int) $editableItem['order_id'];
                $assignedByOrderItemId[$orderItemId] = ($assignedByOrderItemId[$orderItemId] ?? 0) + $quantity;
                $personSubtotalByOrder[$personIndex][$orderId] = ($personSubtotalByOrder[$personIndex][$orderId] ?? 0) + $lineSubtotalCents;

                $personItems[] = [
                    'order_item_id' => $orderItemId,
                    'dish_name' => $editableItem['dish_name'],
                    'quantity' => $quantity,
                    'unit_price' => $editableItem['unit_price'],
                    'line_subtotal' => Money::formatCents($lineSubtotalCents),
                ];
            }

            $personRows[$personIndex] = [
                'person_index' => $personIndex,
                'label' => 'Person '.$personIndex,
                'items' => $personItems,
                'summary_cents' => $this->emptySummaryCents(),
            ];
        }

        for ($personIndex = 1; $personIndex <= $splitCount; $personIndex++) {
            $personRows[$personIndex] = $personRows[$personIndex] ?? [
                'person_index' => $personIndex,
                'label' => 'Person '.$personIndex,
                'items' => [],
                'summary_cents' => $this->emptySummaryCents(),
            ];
        }

        foreach ($orders as $order) {
            $bucketSubtotals = [];
            for ($personIndex = 1; $personIndex <= $splitCount; $personIndex++) {
                $bucketSubtotals['person-'.$personIndex] = (int) ($personSubtotalByOrder[$personIndex][(int) $order->id] ?? 0);
            }
            $bucketSubtotals['unassigned'] = 0;

            foreach ($order->items as $item) {
                $orderItemId = (int) $item->id;
                $remainingQuantity = max((int) $item->quantity - (int) ($assignedByOrderItemId[$orderItemId] ?? 0), 0);
                if ($remainingQuantity <= 0) {
                    continue;
                }

                $bucketSubtotals['unassigned'] += Money::toCents($item->final_unit_price ?? $item->unit_price) * $remainingQuantity;
            }

            $discountAllocations = $this->allocateProportionally(
                Money::toCents($order->discount_amount),
                $bucketSubtotals
            );

            $taxableBuckets = [];
            foreach ($bucketSubtotals as $bucketKey => $subtotalCents) {
                $taxableBuckets[$bucketKey] = max($subtotalCents - ($discountAllocations[$bucketKey] ?? 0), 0);
            }

            $serviceChargeAllocations = $this->allocateProportionally(
                Money::toCents($order->service_charge_amount),
                $taxableBuckets
            );
            $vatAllocations = $this->allocateProportionally(
                Money::toCents($order->vat_amount),
                $taxableBuckets
            );

            for ($personIndex = 1; $personIndex <= $splitCount; $personIndex++) {
                $bucketKey = 'person-'.$personIndex;
                $personRows[$personIndex]['summary_cents']['subtotal'] += $bucketSubtotals[$bucketKey];
                $personRows[$personIndex]['summary_cents']['discount_amount'] += $discountAllocations[$bucketKey] ?? 0;
                $personRows[$personIndex]['summary_cents']['taxable_subtotal'] += $taxableBuckets[$bucketKey];
                $personRows[$personIndex]['summary_cents']['service_charge_amount'] += $serviceChargeAllocations[$bucketKey] ?? 0;
                $personRows[$personIndex]['summary_cents']['vat_amount'] += $vatAllocations[$bucketKey] ?? 0;
            }

            $remainingSummaryCents['subtotal'] += $bucketSubtotals['unassigned'];
            $remainingSummaryCents['discount_amount'] += $discountAllocations['unassigned'] ?? 0;
            $remainingSummaryCents['taxable_subtotal'] += $taxableBuckets['unassigned'];
            $remainingSummaryCents['service_charge_amount'] += $serviceChargeAllocations['unassigned'] ?? 0;
            $remainingSummaryCents['vat_amount'] += $vatAllocations['unassigned'] ?? 0;
        }

        $remainingItems = [];
        foreach ($editableItems as $item) {
            $orderItemId = (int) $item['order_item_id'];
            $availableQuantity = (int) $item['quantity'];
            $assignedQuantity = (int) ($assignedByOrderItemId[$orderItemId] ?? 0);
            $remainingQuantity = max($availableQuantity - $assignedQuantity, 0);
            if ($remainingQuantity <= 0) {
                continue;
            }

            $remainingItems[] = [
                ...$item,
                'remaining_quantity' => $remainingQuantity,
                'line_subtotal' => Money::formatCents(Money::toCents($item['unit_price']) * $remainingQuantity),
            ];
        }

        for ($personIndex = 1; $personIndex <= $splitCount; $personIndex++) {
            $summaryCents = $personRows[$personIndex]['summary_cents'];
            $summary = $this->formatSummary($summaryCents);
            $people[] = [
                'person_index' => $personIndex,
                'label' => $personRows[$personIndex]['label'],
                'total' => $summary['total'],
                'items' => $personRows[$personIndex]['items'],
                'summary' => $summary,
            ];
        }

        return [$people, $remainingItems, $this->formatSummary($remainingSummaryCents), count($remainingItems) === 0];
    }

    /**
     * @param Collection<int, Order> $orders
     * @return array<int, array{key:string,label:string,amount:string}>
     */
    private function equalBreakdown(Collection $orders, int $splitCount): array
    {
        $totalCents = $orders->reduce(
            fn (int $carry, Order $order): int => $carry + Money::toCents($order->total),
            0
        );
        $baseShareCents = intdiv($totalCents, $splitCount);
        $remainderCents = $totalCents % $splitCount;

        $shares = [];

        for ($index = 1; $index <= $splitCount; $index++) {
            $shareCents = $baseShareCents + ($index <= $remainderCents ? 1 : 0);

            $shares[] = [
                'key' => 'equal-'.$index,
                'label' => 'Person '.$index,
                'amount' => Money::formatCents($shareCents),
            ];
        }

        return $shares;
    }

    private function splitColumnsExist(): bool
    {
        return Schema::hasColumns('table_sessions', [
            'invoice_split_mode',
            'invoice_split_count',
            'invoice_split_allocations',
        ]);
    }

    private function isMissingSplitColumnException(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'unknown column')
            && (
                str_contains($message, 'invoice_split_mode')
                || str_contains($message, 'invoice_split_count')
                || str_contains($message, 'invoice_split_allocations')
            );
    }

    /**
     * @param array<string,int> $bases
     * @return array<string,int>
     */
    private function allocateProportionally(int $amountCents, array $bases): array
    {
        $allocations = array_fill_keys(array_keys($bases), 0);
        $totalBase = array_sum($bases);

        if ($amountCents <= 0 || $totalBase <= 0) {
            return $allocations;
        }

        $remaining = $amountCents;
        $remainders = [];

        foreach ($bases as $key => $base) {
            if ($base <= 0) {
                $remainders[$key] = 0;
                continue;
            }

            $numerator = $amountCents * $base;
            $allocation = intdiv($numerator, $totalBase);
            $allocations[$key] = $allocation;
            $remaining -= $allocation;
            $remainders[$key] = $numerator % $totalBase;
        }

        uasort($remainders, static function (int $left, int $right): int {
            return $right <=> $left;
        });

        foreach (array_keys($remainders) as $key) {
            if ($remaining <= 0) {
                break;
            }

            $allocations[$key] += 1;
            $remaining--;
        }

        return $allocations;
    }

    /**
     * @return array{subtotal:int,discount_amount:int,taxable_subtotal:int,service_charge_amount:int,vat_amount:int}
     */
    private function emptySummaryCents(): array
    {
        return [
            'subtotal' => 0,
            'discount_amount' => 0,
            'taxable_subtotal' => 0,
            'service_charge_amount' => 0,
            'vat_amount' => 0,
        ];
    }

    /**
     * @param array{subtotal:int,discount_amount:int,taxable_subtotal:int,service_charge_amount:int,vat_amount:int} $summaryCents
     * @return array{subtotal:string,discount_amount:string,taxable_subtotal:string,service_charge_amount:string,vat_amount:string,total:string}
     */
    private function formatSummary(array $summaryCents): array
    {
        $total = $summaryCents['taxable_subtotal'] + $summaryCents['service_charge_amount'] + $summaryCents['vat_amount'];

        return [
            'subtotal' => Money::formatCents($summaryCents['subtotal']),
            'discount_amount' => Money::formatCents($summaryCents['discount_amount']),
            'taxable_subtotal' => Money::formatCents($summaryCents['taxable_subtotal']),
            'service_charge_amount' => Money::formatCents($summaryCents['service_charge_amount']),
            'vat_amount' => Money::formatCents($summaryCents['vat_amount']),
            'total' => Money::formatCents($total),
        ];
    }

    /**
     * @return array{subtotal:string,discount_amount:string,taxable_subtotal:string,service_charge_amount:string,vat_amount:string,total:string}
     */
    private function emptySummary(): array
    {
        return $this->formatSummary($this->emptySummaryCents());
    }
}
