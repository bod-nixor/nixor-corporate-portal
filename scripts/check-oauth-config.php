<?php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Forbidden: CLI only");
}

require_once dirname(__DIR__) . '/api/lib/env.php';
require_once dirname(__DIR__) . '/api/routes/auth.php'; // Includes google_oauth_redirect_uri

echo "OAuth Configuration Check\n";
echo "=======================\n\n";

$root = dirname(__DIR__);
$defaultPath = $root . '/.env';
$configPath = $root . '/config/.env';

$path = $_SERVER['ENV_FILE_PATH'] ?? $_ENV['ENV_FILE_PATH'] ?? getenv('ENV_FILE_PATH');
if (!$path) {
    $path = file_exists($configPath) ? $configPath : $defaultPath;
}
if ($path && !env_is_absolute_path($path)) {
    $path = $root . '/' . ltrim($path, '/\\');
}

if ($path && file_exists($path)) {
    echo "Loaded .env file: {$path}\n";
} else {
    echo "No .env file found at {$path}. Relying on server environment variables.\n";
}

echo "\nVariables:\n";
$keys = [
    'GOOGLE_CLIENT_ID',
    'GOOGLE_CLIENT_SECRET',
    'GOOGLE_REDIRECT_URI',
    'OAUTH_STATE_SECRET',
    'APP_KEY',
    'GOOGLE_ALLOWED_DOMAIN'
];

foreach ($keys as $key) {
    $val = env_value($key);
    $status = empty($val) ? 'MISSING' : 'PRESENT';
    echo " - {$key}: {$status}\n";
}

echo "\nRedirect URI Configuration:\n";
try {
    $redirectUri = google_oauth_redirect_uri();
    echo " - Effective Google Redirect URI: " . ($redirectUri ?: 'MISSING') . "\n";
} catch (Exception $e) {
    echo " - Effective Google Redirect URI: ERROR (" . $e->getMessage() . ")\n";
}

echo "\nAllowed Domains:\n";
try {
    $domains = allowed_google_domains();
    if (empty($domains)) {
        echo " - None (All Google accounts allowed)\n";
    } else {
        echo " - " . implode(', ', $domains) . "\n";
    }
} catch (Exception $e) {
    echo " - ERROR (" . $e->getMessage() . ")\n";
}

echo "\nStatus: ";
$clientId = env_value('GOOGLE_CLIENT_ID');
$clientSecret = env_value('GOOGLE_CLIENT_SECRET');
$redirectUri = google_oauth_redirect_uri();
$secret = env_value('OAUTH_STATE_SECRET') ?: env_value('APP_KEY') ?: env_value('GOOGLE_CLIENT_SECRET');

if (!$clientId || !$clientSecret || !$redirectUri || !$secret) {
    echo "INCOMPLETE (Google OAuth will fail)\n";
} else {
    echo "OK (Google OAuth seems configured)\n";
}
