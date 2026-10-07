<?php

$app = require __DIR__.'/bootstrap.php';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/api/__qa/environment') {
    header('Content-Type: application/json');
    require __DIR__.'/environment.php';

    return;
}
if (str_starts_with($path, '/storage/')) {
    $root = realpath(config('filesystems.disks.public.root'));
    $file = realpath($root.'/'.substr(rawurldecode($path), 9));
    if ($file && str_starts_with($file, $root.DIRECTORY_SEPARATOR) && is_file($file)) {
        header('Content-Type: '.mime_content_type($file));
        readfile($file);
    } else {
        http_response_code(404);
    }

    return;
}
$app->handleRequest(Illuminate\Http\Request::capture());
