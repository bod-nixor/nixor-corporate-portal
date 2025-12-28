<?php
require_once __DIR__ . '/lib/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api(?:/index\\.php)?#', '', $path);
$segments = array_values(array_filter(explode('/', $path)));

if ($method === 'POST' && !in_array($segments[0] ?? '', ['auth'], true)) {
    require_csrf();
}

try {
    if (empty($segments)) {
        respond(['ok' => true, 'data' => ['service' => 'nixor-portal']]);
    }

    switch ($segments[0]) {
        case 'auth':
            require_once __DIR__ . '/routes/auth.php';
            handle_auth($method, $segments);
            break;
        case 'entities':
            require_once __DIR__ . '/routes/entities.php';
            handle_entities($method, $segments);
            break;
        case 'members':
            require_once __DIR__ . '/routes/members.php';
            handle_members($method, $segments);
            break;
        case 'endeavours':
            require_once __DIR__ . '/routes/endeavours.php';
            handle_endeavours($method, $segments);
            break;
        case 'drive':
            require_once __DIR__ . '/routes/drive.php';
            handle_drive($method, $segments);
            break;
        default:
            respond(['ok' => false, 'error' => 'Not Found'], 404);
    }
} catch (Exception $e) {
    error_log($e->getMessage() . ' ' . $e->getTraceAsString());
    respond(['ok' => false, 'error' => 'Internal server error'], 500);
}
