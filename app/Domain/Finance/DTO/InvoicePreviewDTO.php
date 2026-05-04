<?php

declare(strict_types=1);

namespace App\Domain\Finance\DTO;

final class InvoicePreviewDTO
{
    public function __construct(
        public readonly int $subtotalCents,
        public readonly string $discountType,
        public readonly float $discountValue,
        public readonly int $discountAmountCents,
        public readonly int $taxableSubtotalCents,
        public readonly float $vatRate,
        public readonly int $vatAmountCents,
        public readonly int $totalCents
    ) {}
}

