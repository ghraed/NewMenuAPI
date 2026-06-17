<?php

namespace App\Support;

final class DomainName
{
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));

        if ($normalized === '') {
            return null;
        }

        $candidate = preg_match('/^[a-z][a-z0-9+\-.]*:\/\//i', $normalized) === 1
            ? $normalized
            : 'http://'.$normalized;

        $parts = parse_url($candidate);

        if ($parts === false) {
            return null;
        }

        $host = strtolower(trim((string) ($parts['host'] ?? '')));

        if ($host === '' && isset($parts['path']) && is_string($parts['path'])) {
            $host = strtolower(trim($parts['path']));
        }

        $host = rtrim($host, '.');

        if (str_starts_with($host, 'www.')) {
            $withoutWww = substr($host, 4);
            if (is_string($withoutWww) && $withoutWww !== '' && str_contains($withoutWww, '.')) {
                $host = $withoutWww;
            }
        }

        return $host === '' ? null : $host;
    }

    public static function normalizeHost(mixed $value): string
    {
        return self::normalize($value) ?? '';
    }

    public static function stripWww(string $domain): string
    {
        $normalized = self::normalizeHost($domain);

        if (str_starts_with($normalized, 'www.')) {
            $withoutWww = substr($normalized, 4);

            return is_string($withoutWww) ? $withoutWww : $normalized;
        }

        return $normalized;
    }

    public static function isValidCustomDomain(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $normalized = self::normalize($value);

        if ($normalized === null || $normalized === '' || str_contains($normalized, '*')) {
            return false;
        }

        $candidate = preg_match('/^[a-z][a-z0-9+\-.]*:\/\//i', trim($value)) === 1
            ? trim($value)
            : 'http://'.trim($value);

        $parts = parse_url($candidate);

        if ($parts === false || isset($parts['port'])) {
            return false;
        }

        if (filter_var($normalized, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        if ($normalized === 'localhost' || str_ends_with($normalized, '.localhost')) {
            return false;
        }

        if (! str_contains($normalized, '.')) {
            return false;
        }

        return filter_var($normalized, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }
}
