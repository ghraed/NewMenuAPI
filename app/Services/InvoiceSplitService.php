<?php

namespace App\Services;

use App\Models\Order;
use App\Models\TableSession;
use Illuminate\Support\Collection;

class InvoiceSplitService
{
    public const MODE_BY_EACH_ORDER = 'by_each_order';
    public const MODE_EQUAL = 'equal';

    public function normalizeMode(?string $mode): string
    {
        return in_array($mode, [self::MODE_BY_EACH_ORDER, self::MODE_EQUAL], true)
            ? $mode
            : self::MODE_BY_EACH_ORDER;
    }

    /**
     * @param Collection<int, Order> $orders
     * @return array<string, mixed>
     */
    public function buildPayload(TableSession $session, Collection $orders, bool $enabled): array
    {
        if (! $enabled) {
            return [
                'enabled' => false,
                'mode' => null,
                'split_count' => null,
                'breakdown' => [],
            ];
        }

        $mode = $this->normalizeMode($session->invoice_split_mode);
        $splitCount = is_numeric($session->invoice_split_count)
            ? (int) $session->invoice_split_count
            : null;

        if ($mode === self::MODE_EQUAL) {
            return [
                'enabled' => true,
                'mode' => $mode,
                'split_count' => $splitCount,
                'breakdown' => $splitCount !== null && $splitCount >= 2
                    ? $this->equalBreakdown($orders, $splitCount)
                    : [],
            ];
        }

        return [
            'enabled' => true,
            'mode' => self::MODE_BY_EACH_ORDER,
            'split_count' => null,
            'breakdown' => $this->byOrderBreakdown($orders),
        ];
    }

    /**
     * @param Collection<int, Order> $orders
     * @return array<int, array{key:string,label:string,amount:string}>
     */
    private function byOrderBreakdown(Collection $orders): array
    {
        return $orders
            ->values()
            ->map(fn (Order $order, int $index): array => [
                'key' => 'order-'.$order->id,
                'label' => $order->order_number ?: 'Order #'.($index + 1),
                'amount' => number_format((float) $order->total, 2, '.', ''),
            ])
            ->all();
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
}
