<?php

$rawOrigins = implode(',', [
    (string) env('CORS_ALLOWED_ORIGINS', ''),
    (string) env('FRONTEND_URL', ''),
    (string) env('APP_URL', ''),
]);
$configuredOrigins = array_values(array_unique(array_filter(array_map(
    static fn (string $origin): string => rtrim(trim($origin), '/'),
    explode(',', $rawOrigins)
), static fn (string $origin): bool => $origin !== '' && $origin !== '*')));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $configuredOrigins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'Origin', 'X-Requested-With', 'X-Locale', 'X-Idempotency-Key', 'X-Guest-Device-Id', 'X-Guest-Access-Token', 'X-Guest-Cache-Key', 'X-Rozer-Auth-Mode', 'X-Rozer-Expected-User', 'X-Rozer-Expected-Restaurant'],
    'exposed_headers' => [],
    'max_age' => 600,
    'supports_credentials' => true,
];
