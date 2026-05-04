<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Domain\Finance\Contracts\FinanceCalculatorContract;
use App\Domain\Finance\FinanceCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Finance parity regression test skeleton.
 *
 * Goal:
 * - Keep backend invoice math aligned with locked frontend fixtures.
 * - Fail fast when rounding/discount/VAT behavior drifts.
 *
 * Setup:
 * 1) Copy this file to your Laravel backend repo:
 *    tests/Feature/Finance/FinanceParityTest.php
 * 2) Copy fixture JSON from frontend repo:
 *    tests/unit/fixtures/financeBackendParity.json
 *    into backend path:
 *    tests/Fixtures/financeBackendParity.json
 * 3) Wire `calculateWithBackendService(...)` to your real domain service.
 */
final class FinanceParityTest extends TestCase
{
    private const FIXTURE_PATH = __DIR__ . '/../../Fixtures/financeBackendParity.json';

    #[DataProvider('fixtureProvider')]
    public function test_backend_finance_math_matches_locked_fixtures(array $fixture): void
    {
        $input = $fixture['input'];
        $expected = $fixture['expected'];

        // TODO: Replace with your real service call.
        // Must return array shape:
        // [
        //   'invoice' => [
        //      'discountAmountCents' => int,
        //      'discountType' => string,
        //      'discountValue' => float|int,
        //      'subtotalCents' => int,
        //      'taxableSubtotalCents' => int,
        //      'totalCents' => int,
        //      'vatAmountCents' => int,
        //      'vatRate' => float|int,
        //   ],
        //   'settlement' => [
        //      'changeDue' => float|int,
        //      'receivedAmount' => float|int,
        //      'remainingDue' => float|int,
        //   ],
        // ]
        $actual = $this->calculateWithBackendService($input);

        // 1) Hard correctness: cents fields must match exactly.
        $this->assertSame(
            $expected['invoice']['subtotalCents'],
            $actual['invoice']['subtotalCents'],
            "Subtotal cents mismatch for fixture [{$fixture['id']}]"
        );
        $this->assertSame(
            $expected['invoice']['discountAmountCents'],
            $actual['invoice']['discountAmountCents'],
            "Discount cents mismatch for fixture [{$fixture['id']}]"
        );
        $this->assertSame(
            $expected['invoice']['taxableSubtotalCents'],
            $actual['invoice']['taxableSubtotalCents'],
            "Taxable subtotal cents mismatch for fixture [{$fixture['id']}]"
        );
        $this->assertSame(
            $expected['invoice']['vatAmountCents'],
            $actual['invoice']['vatAmountCents'],
            "VAT cents mismatch for fixture [{$fixture['id']}]"
        );
        $this->assertSame(
            $expected['invoice']['totalCents'],
            $actual['invoice']['totalCents'],
            "Total cents mismatch for fixture [{$fixture['id']}]"
        );

        // 2) Categorical fields.
        $this->assertSame(
            $expected['invoice']['discountType'],
            $actual['invoice']['discountType'],
            "Discount type mismatch for fixture [{$fixture['id']}]"
        );
        $this->assertEqualsWithDelta(
            (float) $expected['invoice']['discountValue'],
            (float) $actual['invoice']['discountValue'],
            0.0000001,
            "Discount value mismatch for fixture [{$fixture['id']}]"
        );
        $this->assertEqualsWithDelta(
            (float) $expected['invoice']['vatRate'],
            (float) $actual['invoice']['vatRate'],
            0.0000001,
            "VAT rate mismatch for fixture [{$fixture['id']}]"
        );

        // 3) Settlement comparisons:
        // compare in cents to avoid float artifacts and keep money-safe checks.
        $this->assertSame(
            $this->toCents($expected['settlement']['receivedAmount']),
            $this->toCents($actual['settlement']['receivedAmount']),
            "Settlement received amount mismatch for fixture [{$fixture['id']}]"
        );
        $this->assertSame(
            $this->toCents($expected['settlement']['changeDue']),
            $this->toCents($actual['settlement']['changeDue']),
            "Settlement change due mismatch for fixture [{$fixture['id']}]"
        );
        $this->assertSame(
            $this->toCents($expected['settlement']['remainingDue']),
            $this->toCents($actual['settlement']['remainingDue']),
            "Settlement remaining due mismatch for fixture [{$fixture['id']}]"
        );

        // 4) Invariants (must always hold).
        $this->assertSame(
            $actual['invoice']['subtotalCents'] - $actual['invoice']['discountAmountCents'],
            $actual['invoice']['taxableSubtotalCents'],
            "Invariant failed: taxable = subtotal - discount [{$fixture['id']}]"
        );
        $this->assertSame(
            $actual['invoice']['taxableSubtotalCents'] + $actual['invoice']['vatAmountCents'],
            $actual['invoice']['totalCents'],
            "Invariant failed: total = taxable + vat [{$fixture['id']}]"
        );
    }

    public static function fixtureProvider(): array
    {
        $raw = file_get_contents(self::FIXTURE_PATH);
        if ($raw === false) {
            throw new \RuntimeException('Unable to read fixture file: ' . self::FIXTURE_PATH);
        }

        $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($payload) || !isset($payload['fixtures']) || !is_array($payload['fixtures'])) {
            throw new \RuntimeException('Invalid fixture payload in ' . self::FIXTURE_PATH);
        }

        $rows = [];
        foreach ($payload['fixtures'] as $fixture) {
            $id = (string) ($fixture['id'] ?? 'unknown-fixture');
            $rows[$id] = [$fixture];
        }

        return $rows;
    }

    /**
     * Replace this with your backend domain service call.
     */
    private function calculateWithBackendService(array $input): array
    {
        $service = app()->bound(FinanceCalculatorContract::class)
            ? app(FinanceCalculatorContract::class)
            : new FinanceCalculator();

        $preview = $service->preview(
            subtotal: $input['subtotal'],
            discountType: (string) $input['discountType'],
            discountValue: $input['discountValue'],
            vatRate: $input['vatRate']
        );

        // Keep behavior aligned with frontend snapshot:
        // settlement takes decimal total, not cents.
        $totalDecimal = $preview->totalCents / 100;
        $settlement = $service->settlement(
            total: $totalDecimal,
            amountReceived: $input['amountReceived'],
            paymentMethod: (string) $input['paymentMethod']
        );

        return [
            'invoice' => [
                'discountAmountCents' => $preview->discountAmountCents,
                'discountType' => $preview->discountType,
                'discountValue' => $preview->discountValue,
                'subtotalCents' => $preview->subtotalCents,
                'taxableSubtotalCents' => $preview->taxableSubtotalCents,
                'totalCents' => $preview->totalCents,
                'vatAmountCents' => $preview->vatAmountCents,
                'vatRate' => $preview->vatRate,
            ],
            'settlement' => [
                'changeDue' => $settlement->changeDue,
                'receivedAmount' => $settlement->receivedAmount,
                'remainingDue' => $settlement->remainingDue,
            ],
        ];
    }

    private function toCents(float|int|string $value): int
    {
        return (int) round(((float) $value) * 100);
    }
}
