<?php

$cachedConfig = dirname(__DIR__).'/bootstrap/cache/config.php';

if (is_file($cachedConfig) && ! unlink($cachedConfig)) {
    throw new RuntimeException('Unable to clear cached application configuration before running tests.');
}

require dirname(__DIR__).'/vendor/autoload.php';
