<?php
function handle_config(string $method, array $segments): void {
    if ($method !== 'GET') {
        respond(['ok' => false, 'error' => 'Not Found'], 404);
    }
    respond([
        'ok' => true,
        'data' => [
            'base_url' => base_url(),
            'ws_url' => env_value('WS_URL', ''),
            'ws_token' => env_value('WS_TOKEN', ''),
            'poll_interval' => (int)env_value('POLL_INTERVAL', 8),
        ]
    ]);
}
