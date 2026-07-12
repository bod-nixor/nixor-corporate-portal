<?php

function handle_connect(string $method, array $segments): void {
    $area = $segments[1] ?? '';
    $action = $segments[2] ?? '';

    if ($method === 'POST' && $area === 'identity' && $action === 'resolve-google' && count($segments) === 3) {
        $data = read_json();
        if (!connect_request_has_valid_service_token()) {
            connect_log_resolution_denied((string)($data['email'] ?? ''), 'unauthorized');
            respond(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        $result = connect_resolve_google_payload($data, true);
        respond($result['payload'], $result['status']);
    }

    if ($method === 'POST' && $area === 'entitlements' && $action === 'resolve' && count($segments) === 3) {
        $data = read_json();
        if (!connect_request_has_valid_service_token()) {
            connect_log_resolution_denied((string)($data['email'] ?? ($data['ncp_user_id'] ?? '')), 'unauthorized');
            respond(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        $result = connect_resolve_current_entitlements_payload($data);
        respond($result['payload'], $result['status']);
    }

    respond(['ok' => false, 'error' => 'Not Found'], 404);
}
