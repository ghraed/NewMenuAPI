<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TableSession;
use App\Services\InvoiceSplitService;
use App\Support\Money;
use Illuminate\Support\Collection;
use Tests\TestCase;

class InvoiceSplitServiceTest extends TestCase
{
    public function test_equal_split_breakdown_totals_always_match_original_invoice_total_exactly(): void
    {
        $service = new InvoiceSplitService;

        foreach (range(1, 250) as $totalCents) {
            foreach ([2, 3, 4, 7] as $splitCount) {
                $order = new Order([
                    'total' => Money::formatCents($totalCents),
                ]);
                $order->setRelation('items', new Collection);

                $session = new TableSession([
                    'invoice_split_mode' => InvoiceSplitService::MODE_EQUAL,
                    'invoice_split_count' => $splitCount,
                ]);

                $payload = $service->buildPayload($session, collect([$order]), true);
                $splitTotalCents = array_sum(array_map(
                    fn (array $row): int => Money::toCents($row['amount']),
                    $payload['breakdown']
                ));

                $this->assertSame($totalCents, $splitTotalCents, "Failed for {$totalCents} cents / {$splitCount} people.");
            }
        }
    }

    public function test_selected_item_split_preserves_total_without_losing_or_creating_money(): void
    {
        $service = new InvoiceSplitService;

        $items = [
            $this->makeOrderItem(101, 'Item A', 2, '3.50', '7.00'),
            $this->makeOrderItem(102, 'Item B', 1, '4.25', '4.25'),
            $this->makeOrderItem(103, 'Item C', 3, '1.10', '3.30'),
        ];

        $order = new Order;
        $order->forceFill([
            'total' => '14.55',
        ]);
        $order->setRelation('items', collect($items));

        $session = new TableSession;
        $session->forceFill([
            'invoice_split_mode' => InvoiceSplitService::MODE_BY_PERSON_ORDER,
            'invoice_split_count' => 3,
            'invoice_split_allocations' => [
                [
                    'person_index' => 1,
                    'items' => [
                        ['order_item_id' => 101, 'quantity' => 1],
                    ],
                ],
                [
                    'person_index' => 2,
                    'items' => [
                        ['order_item_id' => 101, 'quantity' => 1],
                        ['order_item_id' => 102, 'quantity' => 1],
                    ],
                ],
                [
                    'person_index' => 3,
                    'items' => [
                        ['order_item_id' => 103, 'quantity' => 2],
                    ],
                ],
            ],
        ]);

        $payload = $service->buildPayload($session, collect([$order]), true);
        $allocatedCents = array_sum(array_map(
            fn (array $row): int => Money::toCents($row['amount']),
            $payload['breakdown']
        ));
        $remainingCents = array_sum(array_map(
            fn (array $row): int => Money::toCents($row['line_subtotal']),
            $payload['remaining_items']
        ));

        $this->assertSame('3.50', $payload['people'][0]['total']);
        $this->assertSame('7.75', $payload['people'][1]['total']);
        $this->assertSame('2.20', $payload['people'][2]['total']);
        $this->assertSame('1.10', $payload['remaining_items'][0]['line_subtotal']);
        $this->assertFalse($payload['is_complete']);
        $this->assertSame(Money::toCents('14.55'), $allocatedCents + $remainingCents);
    }

    public function test_selected_item_split_allocates_discount_service_charge_and_vat_exactly(): void
    {
        $service = new InvoiceSplitService;

        $items = [
            $this->makeOrderItem(201, 'Platter', 2, '10.00', '20.00'),
            $this->makeOrderItem(202, 'Drink', 1, '5.00', '5.00'),
        ];

        $order = new Order;
        $order->id = 77;
        $order->forceFill([
            'discount_amount' => '2.00',
            'service_charge_amount' => '2.30',
            'vat_amount' => '1.15',
            'total' => '26.45',
        ]);
        $order->setRelation('items', collect($items));

        $session = new TableSession;
        $session->forceFill([
            'invoice_split_mode' => InvoiceSplitService::MODE_BY_PERSON_ORDER,
            'invoice_split_count' => 2,
            'invoice_split_allocations' => [
                [
                    'person_index' => 1,
                    'items' => [
                        ['order_item_id' => 201, 'quantity' => 1],
                    ],
                ],
                [
                    'person_index' => 2,
                    'items' => [
                        ['order_item_id' => 201, 'quantity' => 1],
                        ['order_item_id' => 202, 'quantity' => 1],
                    ],
                ],
            ],
        ]);

        $payload = $service->buildPayload($session, collect([$order]), true);

        $this->assertTrue($payload['is_complete']);
        $this->assertSame('10.58', $payload['people'][0]['total']);
        $this->assertSame('15.87', $payload['people'][1]['total']);
        $this->assertSame([
            'subtotal' => '10.00',
            'discount_amount' => '0.80',
            'taxable_subtotal' => '9.20',
            'service_charge_amount' => '0.92',
            'vat_amount' => '0.46',
            'total' => '10.58',
        ], $payload['people'][0]['summary']);
        $this->assertSame([
            'subtotal' => '15.00',
            'discount_amount' => '1.20',
            'taxable_subtotal' => '13.80',
            'service_charge_amount' => '1.38',
            'vat_amount' => '0.69',
            'total' => '15.87',
        ], $payload['people'][1]['summary']);
        $this->assertSame([
            'subtotal' => '0.00',
            'discount_amount' => '0.00',
            'taxable_subtotal' => '0.00',
            'service_charge_amount' => '0.00',
            'vat_amount' => '0.00',
            'total' => '0.00',
        ], $payload['remaining_summary']);

        $allocatedSubtotalCents = array_sum(array_map(
            fn (array $person): int => Money::toCents($person['summary']['subtotal']),
            $payload['people']
        ));
        $allocatedDiscountCents = array_sum(array_map(
            fn (array $person): int => Money::toCents($person['summary']['discount_amount']),
            $payload['people']
        ));
        $allocatedTaxableCents = array_sum(array_map(
            fn (array $person): int => Money::toCents($person['summary']['taxable_subtotal']),
            $payload['people']
        ));
        $allocatedServiceCents = array_sum(array_map(
            fn (array $person): int => Money::toCents($person['summary']['service_charge_amount']),
            $payload['people']
        ));
        $allocatedVatCents = array_sum(array_map(
            fn (array $person): int => Money::toCents($person['summary']['vat_amount']),
            $payload['people']
        ));
        $allocatedTotalCents = array_sum(array_map(
            fn (array $person): int => Money::toCents($person['total']),
            $payload['people']
        ));

        $this->assertSame(Money::toCents('25.00'), $allocatedSubtotalCents);
        $this->assertSame(Money::toCents('2.00'), $allocatedDiscountCents);
        $this->assertSame(Money::toCents('23.00'), $allocatedTaxableCents);
        $this->assertSame(Money::toCents('2.30'), $allocatedServiceCents);
        $this->assertSame(Money::toCents('1.15'), $allocatedVatCents);
        $this->assertSame(Money::toCents('26.45'), $allocatedTotalCents);
    }

    private function makeOrderItem(int $id, string $dishName, int $quantity, string $unitPrice, string $lineSubtotal): OrderItem
    {
        $item = new OrderItem;
        $item->forceFill([
            'dish_name' => $dishName,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_subtotal' => $lineSubtotal,
        ]);
        $item->id = $id;

        return $item;
    }
}
