<?php
require_once __DIR__ . '/lib/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api(?:/index\\.php)?#', '', $path);
$segments = array_values(array_filter(explode('/', $path)));

if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && !in_array($segments[0] ?? '', ['auth', 'public'], true)) {
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
        case 'announcements':
            require_once __DIR__ . '/routes/announcements.php';
            handle_announcements($method, $segments);
            break;
        case 'calendar':
            require_once __DIR__ . '/routes/calendar.php';
            handle_calendar($method, $segments);
            break;
        case 'social':
            require_once __DIR__ . '/routes/social.php';
            handle_social($method, $segments);
            break;
        case 'dashboard':
            require_once __DIR__ . '/routes/dashboard.php';
            handle_dashboard($method, $segments);
            break;
        case 'updates':
            require_once __DIR__ . '/routes/updates.php';
            handle_updates($method, $segments);
            break;
        case 'public':
            require_once __DIR__ . '/routes/public.php';
            handle_public($method, $segments);
            break;
        case 'users':
            require_once __DIR__ . '/routes/users.php';
            handle_users($method, $segments);
            break;
        case 'files':
            require_once __DIR__ . '/routes/files.php';
            handle_files($method, $segments);
            break;
        case 'admin':
            require_once __DIR__ . '/routes/admin.php';
            handle_admin($method, $segments);
            break;
        case 'config':
            require_once __DIR__ . '/routes/config.php';
            handle_config($method, $segments);
            break;
        default:
            respond(['ok' => false, 'error' => 'Not Found'], 404);
    }
} catch (Exception $e) {
    error_log($e->getMessage() . ' ' . $e->getTraceAsString());
    respond(['ok' => false, 'error' => 'Internal server error'], 500);
}
