<?php
function respond(array $payload, int $status = 200): void {
    header('Content-Type: application/json');
    http_response_code($status);
    $json = json_encode($payload);
    if ($json === false) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'Failed to encode response',
            'meta' => ['code' => json_last_error()]
        ]);
    } else {
        echo $json;
    }
    exit;
}

function read_json(): array {
    $input = file_get_contents('php://input');
    if (!$input) {
        return [];
    }
    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        respond(['ok' => false, 'error' => 'Invalid JSON', 'meta' => ['message' => json_last_error_msg()]], 400);
    }
    return is_array($data) ? $data : [];
}

function require_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    if (!$token || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        respond(['ok' => false, 'error' => 'Invalid CSRF token'], 403);
    }
}
