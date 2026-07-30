<?php

namespace Tests\Unit;

use App\Services\OrderInvoiceCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderInvoiceCalculatorTest extends TestCase
{
    #[DataProvider('exactCalculationProvider')]
    public function test_calculator_returns_exact_decimal_totals(
        array $items,
        float $vatRate,
        ?string $discountType,
        float $discountValue,
        array $expected
    ): void {
        $calculator = new OrderInvoiceCalculator;

        $actual = $calculator->calculate($items, $vatRate, $discountType, $discountValue);

        $this->assertSame($expected, $actual);
    }

    public function test_calculator_caps_percentage_discount_and_never_creates_negative_totals(): void
    {
        $calculator = new OrderInvoiceCalculator;

        $actual = $calculator->calculate([
            ['unit_price' => '4.99', 'quantity' => 1],
        ], 5, 'percentage', 250);

        $this->assertSame([
            'vat_rate' => '5.00',
            'service_charge_rate' => '0.00',
            'subtotal' => '4.99',
            'discount_type' => 'percentage',
            'discount_value' => '100.00',
            'discount_amount' => '4.99',
            'taxable_subtotal' => '0.00',
            'service_charge_amount' => '0.00',
            'vat_amount' => '0.00',
            'total' => '0.00',
        ], $actual);
    }

    public function test_calculator_includes_service_charge_in_exact_cent_totals(): void
    {
        $calculator = new OrderInvoiceCalculator;

        $actual = $calculator->calculate([
            ['unit_price' => '10.00', 'quantity' => 2],
            ['unit_price' => '3.33', 'quantity' => 1],
        ], 10, 'percentage', 12.5, 5.5);

        $this->assertSame([
            'vat_rate' => '10.00',
            'service_charge_rate' => '5.50',
            'subtotal' => '23.33',
            'discount_type' => 'percentage',
            'discount_value' => '12.50',
            'discount_amount' => '2.92',
            'taxable_subtotal' => '20.41',
            'service_charge_amount' => '1.12',
            'vat_amount' => '2.04',
            'total' => '23.57',
        ], $actual);
    }

    public static function exactCalculationProvider(): array
    {
        return [
            'fixed discount with vat rounding' => [
                [
                    ['unit_price' => '10.00', 'quantity' => 2],
                ],
                8.25,
                'fixed',
                1.99,
                [
                    'vat_rate' => '8.25',
                    'service_charge_rate' => '0.00',
                    'subtotal' => '20.00',
                    'discount_type' => 'fixed',
                    'discount_value' => '1.99',
                    'discount_amount' => '1.99',
                    'taxable_subtotal' => '18.01',
                    'service_charge_amount' => '0.00',
                    'vat_amount' => '1.49',
                    'total' => '19.50',
                ],
            ],
            'percentage discount with mixed items' => [
                [
                    ['unit_price' => '3.33', 'quantity' => 3],
                    ['unit_price' => '2.50', 'quantity' => 1],
                ],
                7.5,
                'percentage',
                12.5,
                [
                    'vat_rate' => '7.50',
                    'service_charge_rate' => '0.00',
                    'subtotal' => '12.49',
                    'discount_type' => 'percentage',
                    'discount_value' => '12.50',
                    'discount_amount' => '1.56',
                    'taxable_subtotal' => '10.93',
                    'service_charge_amount' => '0.00',
                    'vat_amount' => '0.82',
                    'total' => '11.75',
                ],
            ],
        ];
    }
}
