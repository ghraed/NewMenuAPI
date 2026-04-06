<?php

namespace App\Services;

class OrderInvoiceCalculator
{
    public function calculate(iterable $items, float $vatRate = 0, ?string $discountType = null, float $discountValue = 0): array
    {
        $subtotalCents = 0;

        foreach ($items as $item) {
            $unitPriceCents = $this->toCents($item['unit_price'] ?? 0);
            $quantity = max((int) ($item['quantity'] ?? 0), 0);

            $subtotalCents += $unitPriceCents * $quantity;
        }

        $discountAmountCents = $this->calculateDiscount(
            $subtotalCents,
            $discountType,
            $discountValue
        );

        $taxableSubtotalCents = max($subtotalCents - $discountAmountCents, 0);
        $vatAmountCents = (int) round($taxableSubtotalCents * max($vatRate, 0) / 100);
        $totalCents = $taxableSubtotalCents + $vatAmountCents;

        return [
            'vat_rate' => $this->formatDecimal(max($vatRate, 0)),
            'subtotal' => $this->formatDecimal($subtotalCents / 100),
            'discount_type' => $discountType,
            'discount_value' => $this->formatDecimal($this->normalizeDiscountValue($discountType, $discountValue)),
            'discount_amount' => $this->formatDecimal($discountAmountCents / 100),
            'taxable_subtotal' => $this->formatDecimal($taxableSubtotalCents / 100),
            'vat_amount' => $this->formatDecimal($vatAmountCents / 100),
            'total' => $this->formatDecimal($totalCents / 100),
        ];
    }

    private function calculateDiscount(int $subtotalCents, ?string $discountType, float $discountValue): int
    {
        $normalizedDiscountType = $discountType ?: null;
        $normalizedDiscountValue = $this->normalizeDiscountValue($normalizedDiscountType, $discountValue);

        if ($subtotalCents <= 0 || $normalizedDiscountType === null || $normalizedDiscountValue <= 0) {
            return 0;
        }

        $discountAmountCents = match ($normalizedDiscountType) {
            'percentage' => (int) round($subtotalCents * $normalizedDiscountValue / 100),
            'fixed' => $this->toCents($normalizedDiscountValue),
            default => 0,
        };

        return min($discountAmountCents, $subtotalCents);
    }

    private function normalizeDiscountValue(?string $discountType, float $discountValue): float
    {
        $normalizedDiscountValue = max($discountValue, 0);

        if ($discountType === 'percentage') {
            return min($normalizedDiscountValue, 100);
        }

        return $normalizedDiscountValue;
    }

    private function toCents(float|int|string $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    private function formatDecimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
