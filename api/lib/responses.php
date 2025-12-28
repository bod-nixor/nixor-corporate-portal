<?php
function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function read_json(): array {
    $input = file_get_contents('php://input');
    if (!$input) {
        return [];
    }
    $data = json_decode($input, true);
    return is_array($data) ? $data : [];
}
