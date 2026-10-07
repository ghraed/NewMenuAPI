<?php

$cachedConfig = getenv('APP_CONFIG_CACHE') ?: dirname(__DIR__).'/bootstrap/cache/config.php';

if (is_file($cachedConfig) && ! unlink($cachedConfig)) {
    throw new RuntimeException('Unable to clear cached application configuration before running tests.');
}

$cachedRoutePaths = getenv('APP_ROUTES_CACHE')
    ? [getenv('APP_ROUTES_CACHE')]
    : (glob(dirname(__DIR__).'/bootstrap/cache/routes-*.php') ?: []);
foreach ($cachedRoutePaths as $cachedRoutes) {
    if (! is_file($cachedRoutes)) {
        continue;
    }
    if (! unlink($cachedRoutes)) {
        throw new RuntimeException('Unable to clear cached application routes before running tests.');
    }
}

require dirname(__DIR__).'/vendor/autoload.php';
