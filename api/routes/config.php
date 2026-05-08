<?php
function handle_config(string $method, array $segments): void {
    if ($method !== 'GET') {
        respond(['ok' => false, 'error' => 'Not Found'], 404);
    }
    $user = current_user();
    $includeToken = $user && (can_permission($user, 'nav.admin') || can_permission($user, 'endeavour.approve_mob'));
    respond([
        'ok' => true,
        'data' => [
            'base_url' => base_url(),
            'public_base_url' => public_url_base(),
            'ws_url' => env_value('WS_URL', ''),
            'ws_token' => $includeToken ? env_value('WS_TOKEN', '') : '',
            'poll_interval' => (int)env_value('POLL_INTERVAL', 8),
            'open_app_banner' => [
                'enabled' => filter_var(env_value('SHOW_OPEN_APP_BANNER', 'false'), FILTER_VALIDATE_BOOLEAN),
                'deep_link_scheme' => trim((string)env_value('APP_DEEP_LINK_SCHEME', '')),
                'universal_link_base' => trim((string)env_value('APP_UNIVERSAL_LINK_BASE', '')),
                'ios_store_url' => trim((string)env_value('IOS_APP_STORE_URL', '')),
                'android_store_url' => trim((string)env_value('ANDROID_PLAY_STORE_URL', '')),
            ],
            'push' => [
                'registration_enabled' => filter_var(env_value('PUSH_REGISTRATION_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN),
                'provider_configured' => push_provider_configured(),
            ],
        ]
    ]);
}
