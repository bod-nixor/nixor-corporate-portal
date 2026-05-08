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
                'enabled' => filter_var(env_value('SHOW_OPEN_APP_BANNER', 'true'), FILTER_VALIDATE_BOOLEAN),
                'deep_link_scheme' => trim((string)env_value('APP_DEEP_LINK_SCHEME', 'ncp')),
                'universal_link_base' => trim((string)env_value('APP_UNIVERSAL_LINK_BASE', public_url_base())),
                'ios_store_url' => trim((string)env_value('IOS_APP_STORE_URL', 'https://example.com')),
                'android_store_url' => trim((string)env_value('ANDROID_PLAY_STORE_URL', 'https://example.com')),
            ],
            'push' => [
                'registration_enabled' => filter_var(env_value('PUSH_REGISTRATION_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN),
                'provider_configured' => push_provider_configured(),
            ],
        ]
    ]);
}
