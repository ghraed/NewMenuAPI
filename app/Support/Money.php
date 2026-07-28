<?php

declare(strict_types=1);

namespace App\Support;

final class Money
{
    public static function toCents(float|int|string|null $value): int
    {
        return self::toScaledInt($value, 2);
    }

    public static function toScaledInt(float|int|string|null $value, int $scale): int
    {
        $scale = max($scale, 0);
        $normalized = self::normalizeNumericString($value);

        if ($normalized === null) {
            return 0;
        }

        $negative = str_starts_with($normalized, '-');
        if ($negative || str_starts_with($normalized, '+')) {
            $normalized = substr($normalized, 1);
        }

        [$wholePart, $fractionPart] = array_pad(explode('.', $normalized, 2), 2, '');
        $wholePart = ltrim($wholePart, '0');
        $wholePart = $wholePart === '' ? '0' : $wholePart;
        $fractionPart = preg_replace('/\D/', '', $fractionPart) ?? '';

        $digits = $fractionPart;
        while (strlen($digits) < $scale + 1) {
            $digits .= '0';
        }

        $keptDigits = $scale > 0
            ? substr($digits, 0, $scale)
            : '';
        $roundDigit = (int) ($digits[$scale] ?? '0');

        $scaled = ((int) $wholePart) * (10 ** $scale);
        if ($scale > 0) {
            $scaled += (int) str_pad($keptDigits, $scale, '0');
        }

        if ($roundDigit >= 5) {
            $scaled++;
        }

        return $negative ? -$scaled : $scaled;
    }

    public static function formatCents(int $cents): string
    {
        return self::formatScaledInt($cents, 2);
    }

    public static function formatScaledInt(int $value, int $scale): string
    {
        $scale = max($scale, 0);
        $negative = $value < 0;
        $absolute = abs($value);

        if ($scale === 0) {
            return ($negative ? '-' : '').(string) $absolute;
        }

        $divisor = 10 ** $scale;
        $whole = intdiv($absolute, $divisor);
        $fraction = str_pad((string) ($absolute % $divisor), $scale, '0', STR_PAD_LEFT);

        return sprintf('%s%d.%s', $negative ? '-' : '', $whole, $fraction);
    }

    public static function normalizeDecimal(float|int|string|null $value, int $scale): string
    {
        return self::formatScaledInt(self::toScaledInt($value, $scale), $scale);
    }

    public static function sumToCents(iterable $values): int
    {
        $sum = 0;

        foreach ($values as $value) {
            $sum += self::toCents($value);
        }

        return $sum;
    }

    public static function multiplyToCents(
        float|int|string|null $quantity,
        float|int|string|null $unitPrice,
        int $quantityScale = 3
    ): int {
        $normalizedQuantityScale = max($quantityScale, 0);
        $quantityInt = self::toScaledInt($quantity, $normalizedQuantityScale);
        $unitPriceCents = self::toCents($unitPrice);
        $product = $quantityInt * $unitPriceCents;
        $divisor = 10 ** $normalizedQuantityScale;

        if ($divisor === 1) {
            return $product;
        }

        return self::divideAndRoundHalfUp($product, $divisor);
    }

    public static function divideAndRoundHalfUp(int $numerator, int $denominator): int
    {
        if ($denominator === 0) {
            return 0;
        }

        $negative = ($numerator < 0) xor ($denominator < 0);
        $absoluteNumerator = abs($numerator);
        $absoluteDenominator = abs($denominator);
        $quotient = intdiv($absoluteNumerator, $absoluteDenominator);
        $remainder = $absoluteNumerator % $absoluteDenominator;

        if ($remainder * 2 >= $absoluteDenominator) {
            $quotient++;
        }

        return $negative ? -$quotient : $quotient;
    }

    private static function normalizeNumericString(float|int|string|null $value): ?string
    {
        if ($value === null) {
            return '0';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (is_infinite($value) || is_nan($value)) {
                return '0';
            }

            $formatted = sprintf('%.14F', $value);
            $formatted = rtrim(rtrim($formatted, '0'), '.');

            return $formatted === '' || $formatted === '-'
                ? '0'
                : $formatted;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return '0';
        }

        if (! preg_match('/^[+-]?\d+(?:\.\d+)?$/', $trimmed)) {
            return null;
        }

        return $trimmed;
    }
}
