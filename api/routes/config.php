<?php
function handle_config(string $method, array $segments): void {
    if ($method !== 'GET') {
        respond(['ok' => false, 'error' => 'Not Found'], 404);
    }
    $user = current_user();
    $includeToken = $user && in_array($user['global_role'], ['admin', 'board'], true);
    respond([
        'ok' => true,
        'data' => [
            'base_url' => base_url(),
            'ws_url' => env_value('WS_URL', ''),
            'ws_token' => $includeToken ? env_value('WS_TOKEN', '') : '',
            'poll_interval' => (int)env_value('POLL_INTERVAL', 8),
        ]
    ]);
}
