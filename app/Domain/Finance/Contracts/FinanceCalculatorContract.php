<?php

declare(strict_types=1);

namespace App\Domain\Finance\Contracts;

use App\Domain\Finance\DTO\CashSettlementDTO;
use App\Domain\Finance\DTO\InvoicePreviewDTO;

interface FinanceCalculatorContract
{
    public function preview(
        float|int|string $subtotal,
        string $discountType,
        float|int|string $discountValue,
        float|int|string $vatRate
    ): InvoicePreviewDTO;

    public function settlement(
        float|int|string $total,
        float|int|string $amountReceived,
        string $paymentMethod
    ): CashSettlementDTO;
}

