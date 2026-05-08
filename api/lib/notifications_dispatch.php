<?php

function portal_notification_preference_column_for_type(string $type): string {
    if (str_starts_with($type, 'volunteer')) {
        return 'volunteering_enabled';
    }
    if (str_starts_with($type, 'social')) {
        return 'social_enabled';
    }
    if (str_starts_with($type, 'calendar')) {
        return 'calendar_enabled';
    }
    if (str_contains($type, 'submission') || str_contains($type, 'approval') || str_contains($type, 'rejected')) {
        return 'approvals_enabled';
    }
    return 'platform_enabled';
}

function portal_notification_preferences_for_user(int $userId): array {
    $defaults = [
        'platform_enabled' => 1,
        'email_enabled' => 1,
        'push_enabled' => 1,
        'approvals_enabled' => 1,
        'volunteering_enabled' => 1,
        'social_enabled' => 1,
        'calendar_enabled' => 1,
    ];
    try {
        $stmt = db()->prepare('SELECT * FROM user_notification_preferences WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if ($row) {
            foreach ($defaults as $key => $default) {
                $defaults[$key] = isset($row[$key]) ? (int)$row[$key] : $default;
            }
        }
    } catch (PDOException $e) {
        return $defaults;
    }
    return $defaults;
}

function portal_notification_platform_enabled(int $userId, string $type): bool {
    $prefs = portal_notification_preferences_for_user($userId);
    if ((int)($prefs['platform_enabled'] ?? 1) === 0) {
        return false;
    }
    $column = portal_notification_preference_column_for_type($type);
    return (int)($prefs[$column] ?? 1) !== 0;
}

function portal_notification_push_enabled(int $userId, string $type): bool {
    $prefs = portal_notification_preferences_for_user($userId);
    if ((int)($prefs['push_enabled'] ?? 1) === 0 || (int)($prefs['platform_enabled'] ?? 1) === 0) {
        return false;
    }
    $column = portal_notification_preference_column_for_type($type);
    return (int)($prefs[$column] ?? 1) !== 0;
}

function create_platform_notification(int $userId, string $type, array $payload, bool $force = false): ?int {
    if ($userId <= 0) {
        return null;
    }
    if (!$force && !portal_notification_platform_enabled($userId, $type)) {
        return null;
    }

    $stmt = db()->prepare('INSERT INTO notifications (user_id, type, payload_json) VALUES (?, ?, ?)');
    $stmt->execute([$userId, $type, json_encode($payload)]);
    $notificationId = (int)db()->lastInsertId();
    queue_push_dispatch($notificationId);
    return $notificationId;
}

function queue_push_dispatch(int $notificationId): void {
    if ($notificationId <= 0) {
        return;
    }

    $cronScript = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . 'run.php';
    $phpBinary = PHP_BINARY ?: 'php';
    $command = PHP_OS_FAMILY === 'Windows'
        ? 'cmd /c start "" /B ' . escapeshellarg($phpBinary) . ' ' . escapeshellarg($cronScript) . ' push_notification_id=' . (int)$notificationId
        : 'nohup ' . escapeshellarg($phpBinary) . ' ' . escapeshellarg($cronScript) . ' push_notification_id=' . (int)$notificationId . ' >/dev/null 2>&1 < /dev/null &';

    $process = @proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, dirname($cronScript));

    if (is_resource($process)) {
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        return;
    }

    error_log('Failed to queue push dispatch for notification_id=' . $notificationId);
    register_shutdown_function(static function () use ($notificationId): void {
        dispatch_push_for_notification($notificationId);
    });
}

function push_provider_configured(): bool {
    $provider = strtolower(trim((string)env_value('PUSH_PROVIDER', '')));
    if ($provider === '' || $provider === 'none') {
        return false;
    }
    if ($provider === 'webhook') {
        return trim((string)env_value('PUSH_WEBHOOK_URL', '')) !== '';
    }
    if ($provider === 'fcm') {
        return trim((string)env_value('FCM_WEBHOOK_URL', '')) !== '';
    }
    return false;
}

function push_safe_payload(array $notification): array {
    $payload = [];
    if (!empty($notification['payload_json'])) {
        $decoded = json_decode((string)$notification['payload_json'], true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    $title = trim((string)($payload['title'] ?? 'Nixor Portal'));
    $message = trim((string)($payload['message'] ?? 'You have a new portal notification.'));
    $target = trim((string)($payload['target_url'] ?? ''));
    if ($target === '' && !empty($payload['post_public_id'])) {
        $target = public_relative_url('social.html', [
            'feed' => ($payload['feed_scope'] ?? '') === 'entity' ? 'entity' : 'global',
            'e' => $payload['entity_public_id'] ?? null,
            'p' => $payload['post_public_id'],
            'c' => $payload['comment_public_id'] ?? null,
        ]);
    }

    return [
        'title' => mb_substr($title, 0, 80, 'UTF-8'),
        'body' => mb_substr($message, 0, 140, 'UTF-8'),
        'data' => [
            'notification_id' => (string)$notification['id'],
            'type' => (string)$notification['type'],
            'target_url' => str_starts_with($target, '/') && !str_starts_with($target, '//') ? $target : '',
        ],
    ];
}

function dispatch_push_for_notification(int $notificationId): void {
    if ($notificationId <= 0) {
        return;
    }
    try {
        $stmt = db()->prepare('SELECT * FROM notifications WHERE id = ? LIMIT 1');
        $stmt->execute([$notificationId]);
        $notification = $stmt->fetch();
        if (!$notification) {
            return;
        }
        $userId = (int)$notification['user_id'];
        $type = (string)$notification['type'];
        if (!portal_notification_push_enabled($userId, $type)) {
            return;
        }
        if (!push_provider_configured()) {
            error_log('push not configured; platform notification created id=' . $notificationId);
            return;
        }

        $tokens = db()->prepare('SELECT * FROM push_device_tokens WHERE user_id = ? AND enabled = 1');
        $tokens->execute([$userId]);
        $payload = push_safe_payload($notification);
        foreach ($tokens->fetchAll() as $token) {
            $deliveryId = portal_queue_push_delivery($notificationId, (int)$token['id']);
            if (!$deliveryId) {
                continue;
            }
            try {
                portal_send_push_payload($token, $payload);
                portal_mark_push_delivery($deliveryId, 'sent', null);
            } catch (Throwable $e) {
                portal_mark_push_delivery($deliveryId, 'failed', $e->getMessage());
                error_log('push delivery failed notification_id=' . $notificationId . ' token_id=' . (int)$token['id']);
            }
        }
    } catch (Throwable $e) {
        error_log('push dispatch failed notification_id=' . $notificationId);
    }
}

function portal_queue_push_delivery(int $notificationId, int $tokenId): ?int {
    try {
        $stmt = db()->prepare('INSERT INTO push_notification_deliveries (notification_id, device_token_id, status) VALUES (?, ?, "queued")');
        $stmt->execute([$notificationId, $tokenId]);
        return (int)db()->lastInsertId();
    } catch (PDOException $e) {
        $driverCode = (int)($e->errorInfo[1] ?? 0);
        if ($driverCode === 1062) {
            return null;
        }
        throw $e;
    }
}

function portal_mark_push_delivery(int $deliveryId, string $status, ?string $error): void {
    $safeStatus = in_array($status, ['queued', 'sent', 'failed', 'skipped'], true) ? $status : 'failed';
    $message = $error ? mb_substr(preg_replace('/\s+/', ' ', $error) ?? $error, 0, 255, 'UTF-8') : null;
    $stmt = db()->prepare('UPDATE push_notification_deliveries SET status = ?, error_message = ? WHERE id = ?');
    $stmt->execute([$safeStatus, $message, $deliveryId]);
}

function portal_send_push_payload(array $token, array $payload): void {
    $provider = strtolower(trim((string)env_value('PUSH_PROVIDER', '')));
    if ($provider === 'webhook') {
        portal_send_push_webhook($token, $payload);
        return;
    }
    if ($provider === 'fcm' && trim((string)env_value('FCM_WEBHOOK_URL', '')) !== '') {
        portal_send_push_webhook($token, $payload, 'FCM_WEBHOOK_URL');
        return;
    }
    throw new RuntimeException('Push provider is not configured for direct delivery.');
}

function portal_send_push_webhook(array $token, array $payload, string $urlKey = 'PUSH_WEBHOOK_URL'): void {
    $url = trim((string)env_value($urlKey, ''));
    if ($url === '') {
        throw new RuntimeException('Push webhook URL missing.');
    }
    $body = json_encode([
        'platform' => $token['platform'],
        'token' => $token['token'],
        'payload' => $payload,
    ]);
    $headers = "Content-Type: application/json\r\n";
    $secret = trim((string)env_value('PUSH_WEBHOOK_SECRET', ''));
    if ($secret !== '') {
        $headers .= 'X-NCP-Push-Signature: sha256=' . hash_hmac('sha256', (string)$body, $secret) . "\r\n";
    }
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => $headers,
            'content' => $body,
            'timeout' => 5,
            'ignore_errors' => true,
        ],
    ]);
    $result = @file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    if (!preg_match('/\s2\d\d\s/', $statusLine)) {
        throw new RuntimeException('Push webhook request failed.');
    }
}
