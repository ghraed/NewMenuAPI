<?php

namespace App\Services;

use App\Support\Money;

class OrderInvoiceCalculator
{
    public function calculate(
        iterable $items,
        float $vatRate = 0,
        ?string $discountType = null,
        float $discountValue = 0,
        float $serviceChargeRate = 0
    ): array
    {
        $subtotalCents = 0;

        foreach ($items as $item) {
            $unitPriceCents = Money::toCents($item['unit_price'] ?? 0);
            $quantity = max((int) ($item['quantity'] ?? 0), 0);

            $subtotalCents += $unitPriceCents * $quantity;
        }

        return $this->calculateFromSubtotalCents(
            $subtotalCents,
            $vatRate,
            $discountType,
            $discountValue,
            $serviceChargeRate
        );
    }

    public function calculateFromSubtotalCents(
        int $subtotalCents,
        float $vatRate = 0,
        ?string $discountType = null,
        float $discountValue = 0,
        float $serviceChargeRate = 0
    ): array {
        $subtotalCents = max($subtotalCents, 0);

        $discountAmountCents = $this->calculateDiscount(
            $subtotalCents,
            $discountType,
            $discountValue
        );

        $taxableSubtotalCents = max($subtotalCents - $discountAmountCents, 0);
        $normalizedServiceChargeRate = max(Money::toScaledInt($serviceChargeRate, 2), 0);
        $serviceChargeAmountCents = Money::divideAndRoundHalfUp(
            $taxableSubtotalCents * $normalizedServiceChargeRate,
            10000
        );
        $normalizedVatRate = max(Money::toScaledInt($vatRate, 2), 0);
        $vatAmountCents = Money::divideAndRoundHalfUp($taxableSubtotalCents * $normalizedVatRate, 10000);
        $totalCents = $taxableSubtotalCents + $serviceChargeAmountCents + $vatAmountCents;

        return [
            'vat_rate' => Money::formatScaledInt($normalizedVatRate, 2),
            'service_charge_rate' => Money::formatScaledInt($normalizedServiceChargeRate, 2),
            'subtotal' => Money::formatCents($subtotalCents),
            'discount_type' => $discountType,
            'discount_value' => $this->formatDiscountValue($discountType, $discountValue),
            'discount_amount' => Money::formatCents($discountAmountCents),
            'taxable_subtotal' => Money::formatCents($taxableSubtotalCents),
            'service_charge_amount' => Money::formatCents($serviceChargeAmountCents),
            'vat_amount' => Money::formatCents($vatAmountCents),
            'total' => Money::formatCents($totalCents),
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
            'percentage' => Money::divideAndRoundHalfUp(
                $subtotalCents * min(Money::toScaledInt($normalizedDiscountValue, 2), 10000),
                10000
            ),
            'fixed' => Money::toCents($normalizedDiscountValue),
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

    private function formatDiscountValue(?string $discountType, float $discountValue): string
    {
        return Money::formatScaledInt(
            Money::toScaledInt($this->normalizeDiscountValue($discountType, $discountValue), 2),
            2
        );
    }
}
