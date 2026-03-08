<?php
$repoRoot = __DIR__;
$publicRoot = $repoRoot . '/public';
$publicReal = realpath($publicRoot) ?: $publicRoot;
$publicPrefix = rtrim($publicReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
$requestPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

if ($requestPath === '/api' || str_starts_with($requestPath, '/api/')) {
    require $repoRoot . '/api/index.php';
    return true;
}

if ($requestPath === '/') {
    $requestPath = '/index.html';
}

$candidate = realpath($publicRoot . $requestPath);
if ($candidate && is_file($candidate) && str_starts_with($candidate, $publicPrefix)) {
    $extension = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
    $mimeTypes = [
        'css' => 'text/css; charset=UTF-8',
        'gif' => 'image/gif',
        'html' => 'text/html; charset=UTF-8',
        'ico' => 'image/x-icon',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'js' => 'application/javascript; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];
    header('Content-Type: ' . ($mimeTypes[$extension] ?? 'application/octet-stream'));
    readfile($candidate);
    return true;
}

http_response_code(404);
header('Content-Type: text/plain; charset=UTF-8');
echo 'Not Found';
