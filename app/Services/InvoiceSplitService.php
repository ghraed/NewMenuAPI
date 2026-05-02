<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TableSession;
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

            [$people, $remainingItems, $complete] = $this->buildPeopleAndRemaining(
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
     * @return array<int, array{order_item_id:int,key:string,dish_name:string,quantity:int,unit_price:string,line_subtotal:string}>
     */
    private function buildEditableItems(Collection $orders): array
    {
        return $orders
            ->flatMap(fn (Order $order) => $order->items->map(fn (OrderItem $item): array => [
                'order_item_id' => (int) $item->id,
                'key' => 'order-item-'.$item->id,
                'dish_name' => $item->dish_name,
                'quantity' => (int) $item->quantity,
                'unit_price' => number_format((float) $item->unit_price, 2, '.', ''),
                'line_subtotal' => number_format((float) $item->line_subtotal, 2, '.', ''),
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
     * @param array<int, array<string,mixed>> $editableItems
     * @param array<int, array{person_index:int,items:array<int,array{order_item_id:int,quantity:int}>}> $normalizedPeople
     * @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>,2:bool}
     */
    private function buildPeopleAndRemaining(array $editableItems, array $normalizedPeople, int $splitCount): array
    {
        $editableByOrderItemId = [];
        foreach ($editableItems as $item) {
            $editableByOrderItemId[(int) $item['order_item_id']] = $item;
        }

        $assignedByOrderItemId = [];
        $people = [];

        foreach ($normalizedPeople as $person) {
            $personTotalCents = 0;
            $personItems = [];

            foreach ($person['items'] as $assignment) {
                $orderItemId = (int) $assignment['order_item_id'];
                $quantity = (int) $assignment['quantity'];
                $editableItem = $editableByOrderItemId[$orderItemId] ?? null;
                if (! $editableItem || $quantity <= 0) {
                    continue;
                }

                $unitPrice = (float) $editableItem['unit_price'];
                $lineSubtotalCents = (int) round($unitPrice * $quantity * 100);
                $personTotalCents += $lineSubtotalCents;
                $assignedByOrderItemId[$orderItemId] = ($assignedByOrderItemId[$orderItemId] ?? 0) + $quantity;

                $personItems[] = [
                    'order_item_id' => $orderItemId,
                    'dish_name' => $editableItem['dish_name'],
                    'quantity' => $quantity,
                    'unit_price' => $editableItem['unit_price'],
                    'line_subtotal' => number_format($lineSubtotalCents / 100, 2, '.', ''),
                ];
            }

            $people[] = [
                'person_index' => (int) $person['person_index'],
                'label' => 'Person '.(int) $person['person_index'],
                'total' => number_format($personTotalCents / 100, 2, '.', ''),
                'items' => $personItems,
            ];
        }

        if (count($people) < $splitCount) {
            for ($personIndex = count($people) + 1; $personIndex <= $splitCount; $personIndex++) {
                $people[] = [
                    'person_index' => $personIndex,
                    'label' => 'Person '.$personIndex,
                    'total' => '0.00',
                    'items' => [],
                ];
            }
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
                'line_subtotal' => number_format(((float) $item['unit_price']) * $remainingQuantity, 2, '.', ''),
            ];
        }

        return [$people, $remainingItems, count($remainingItems) === 0];
    }

    /**
     * @param Collection<int, Order> $orders
     * @return array<int, array{key:string,label:string,amount:string}>
     */
    private function equalBreakdown(Collection $orders, int $splitCount): array
    {
        $totalCents = (int) round($orders->sum(fn (Order $order) => (float) $order->total) * 100);
        $baseShareCents = intdiv($totalCents, $splitCount);
        $remainderCents = $totalCents % $splitCount;

        $shares = [];

        for ($index = 1; $index <= $splitCount; $index++) {
            $shareCents = $baseShareCents + ($index <= $remainderCents ? 1 : 0);

            $shares[] = [
                'key' => 'equal-'.$index,
                'label' => 'Person '.$index,
                'amount' => number_format($shareCents / 100, 2, '.', ''),
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
}
