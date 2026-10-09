<?php

return [
    // No wildcard/range trust: configure the actual TLS-terminating ingress IPs.
    'proxies' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))),
        static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false
    )),
];
