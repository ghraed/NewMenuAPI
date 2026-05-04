<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Finance\Contracts\FinanceCalculatorContract;
use App\Domain\Finance\DTO\CashSettlementDTO;
use App\Domain\Finance\DTO\InvoicePreviewDTO;

/**
 * Mirrors frontend finance math exactly for parity stability.
 */
final class FinanceCalculator implements FinanceCalculatorContract
{
    public function preview(
        float|int|string $subtotal,
        string $discountType,
        float|int|string $discountValue,
        float|int|string $vatRate
    ): InvoicePreviewDTO {
        $subtotalCents = $this->clampNonNegativeInt($this->toCents($subtotal));
        $rawDiscountValue = $this->clampNonNegativeFloat($this->parseFiniteNumber($discountValue));

        $normalizedDiscountType = in_array($discountType, ['', 'fixed', 'percentage'], true)
            ? $discountType
            : '';
        $normalizedDiscountValue = $normalizedDiscountType === 'percentage'
            ? min($rawDiscountValue, 100.0)
            : $rawDiscountValue;

        $discountAmountCents = 0;
        if ($normalizedDiscountType === 'percentage' && $normalizedDiscountValue > 0) {
            $discountAmountCents = (int) round($subtotalCents * $normalizedDiscountValue / 100);
        } elseif ($normalizedDiscountType === 'fixed' && $normalizedDiscountValue > 0) {
            $discountAmountCents = $this->clampNonNegativeInt($this->toCents($normalizedDiscountValue));
        }

        $discountAmountCents = min($discountAmountCents, $subtotalCents);
        $taxableSubtotalCents = max($subtotalCents - $discountAmountCents, 0);

        $normalizedVatRate = $this->clampNonNegativeFloat($this->parseFiniteNumber($vatRate));
        $vatAmountCents = (int) round($taxableSubtotalCents * $normalizedVatRate / 100);
        $totalCents = $taxableSubtotalCents + $vatAmountCents;

        return new InvoicePreviewDTO(
            subtotalCents: $subtotalCents,
            discountType: $normalizedDiscountType,
            discountValue: $normalizedDiscountValue,
            discountAmountCents: $discountAmountCents,
            taxableSubtotalCents: $taxableSubtotalCents,
            vatRate: $normalizedVatRate,
            vatAmountCents: $vatAmountCents,
            totalCents: $totalCents
        );
    }

    public function settlement(
        float|int|string $total,
        float|int|string $amountReceived,
        string $paymentMethod
    ): CashSettlementDTO {
        $totalAmount = $this->clampNonNegativeFloat($this->parseFiniteNumber($total));
        $receivedAmount = $this->clampNonNegativeFloat($this->parseFiniteNumber($amountReceived));

        if ($paymentMethod !== 'cash') {
            return new CashSettlementDTO(
                receivedAmount: $receivedAmount,
                changeDue: 0.0,
                remainingDue: 0.0
            );
        }

        return new CashSettlementDTO(
            receivedAmount: $receivedAmount,
            changeDue: max($receivedAmount - $totalAmount, 0.0),
            remainingDue: max($totalAmount - $receivedAmount, 0.0)
        );
    }

    private function parseFiniteNumber(float|int|string $value): float
    {
        if (!is_numeric($value)) {
            return 0.0;
        }

        $parsed = (float) $value;
        if (is_infinite($parsed) || is_nan($parsed)) {
            return 0.0;
        }

        return $parsed;
    }

    private function toCents(float|int|string $value): int
    {
        return (int) round($this->parseFiniteNumber($value) * 100);
    }

    private function clampNonNegativeInt(int $value): int
    {
        return max($value, 0);
    }

    private function clampNonNegativeFloat(float $value): float
    {
        return max($value, 0.0);
    }
}
