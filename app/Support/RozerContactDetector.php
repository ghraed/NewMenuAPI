<?php

namespace App\Support;

class RozerContactDetector
{
    public function detect(string $message): array
    {
        $detected = [
            'email' => $this->detectEmail($message),
            'phone' => $this->detectPhone($message),
            'name' => $this->detectName($message),
            'business_type' => $this->detectBusinessType($message),
            'preferred_contact_method' => $this->detectPreferredContactMethod($message),
        ];

        return array_filter($detected, static fn ($value) => filled($value));
    }

    private function detectEmail(string $message): ?string
    {
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $message, $matches)) {
            return strtolower($matches[0]);
        }

        return null;
    }

    private function detectPhone(string $message): ?string
    {
        if (preg_match('/(?:\+?\d[\d\s().\-]{7,}\d)/', $message, $matches)) {
            $phone = preg_replace('/[^\d+]/', '', $matches[0]) ?? '';
            $digits = preg_replace('/\D/', '', $phone) ?? '';

            if ($digits !== '' && strlen($digits) >= 8) {
                return $phone;
            }
        }

        return null;
    }

    private function detectName(string $message): ?string
    {
        if (preg_match('/\b(?:my name is|i am|i\'m)\s+([a-z][a-z\s\'\-]{1,60})/i', $message, $matches)) {
            return $this->normalizeText($matches[1], true);
        }

        return null;
    }

    private function detectBusinessType(string $message): ?string
    {
        if (preg_match('/\b(?:we are|i have|it is|our)\s+(?:a|an)?\s*([a-z][a-z\s\-]{2,50}(?:restaurant|cafe|coffee shop|bakery|bar|food truck|hotel|bistro|pizzeria))/i', $message, $matches)) {
            return $this->normalizeText($matches[1], true);
        }

        return null;
    }

    private function detectPreferredContactMethod(string $message): ?string
    {
        $normalized = strtolower($message);

        return match (true) {
            str_contains($normalized, 'whatsapp') => 'whatsapp',
            str_contains($normalized, 'email') => 'email',
            str_contains($normalized, 'phone'),
            str_contains($normalized, 'call') => 'phone',
            default => null,
        };
    }

    private function normalizeText(string $value, bool $title = false): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        if ($title) {
            return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
        }

        return $value;
    }
}
