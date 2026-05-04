<?php

declare(strict_types=1);

namespace App\Domain\Finance\DTO;

final class CashSettlementDTO
{
    public function __construct(
        public readonly float $receivedAmount,
        public readonly float $changeDue,
        public readonly float $remainingDue
    ) {}
}

