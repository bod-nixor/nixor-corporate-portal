<?php
function handle_settings(string $method, array $segments): void {
    $user = require_auth();
    $action = $segments[1] ?? '';

    if ($action === 'notifications' && $method === 'GET') {
        require_permission('notifications.manage_preferences', null, $user);
        respond(['ok' => true, 'data' => settings_notification_preferences((int)$user['id'])]);
    }

    if ($action === 'notifications' && $method === 'PUT') {
        require_permission('notifications.manage_preferences', null, $user);
        $data = read_json();
        $allowed = [
            'platform_enabled',
            'email_enabled',
            'push_enabled',
            'approvals_enabled',
            'volunteering_enabled',
            'social_enabled',
            'calendar_enabled',
        ];
        $values = settings_notification_preferences((int)$user['id']);
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $values[$field] = filter_var($data[$field], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($values[$field] === null) {
                    respond(['ok' => false, 'error' => "Invalid {$field}"], 400);
                }
                $values[$field] = $values[$field] ? 1 : 0;
            }
        }
        $stmt = db()->prepare(
            'INSERT INTO user_notification_preferences
                (user_id, platform_enabled, email_enabled, push_enabled, approvals_enabled, volunteering_enabled, social_enabled, calendar_enabled)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                platform_enabled = VALUES(platform_enabled),
                email_enabled = VALUES(email_enabled),
                push_enabled = VALUES(push_enabled),
                approvals_enabled = VALUES(approvals_enabled),
                volunteering_enabled = VALUES(volunteering_enabled),
                social_enabled = VALUES(social_enabled),
                calendar_enabled = VALUES(calendar_enabled)'
        );
        $stmt->execute([
            $user['id'],
            $values['platform_enabled'],
            $values['email_enabled'],
            $values['push_enabled'],
            $values['approvals_enabled'],
            $values['volunteering_enabled'],
            $values['social_enabled'],
            $values['calendar_enabled'],
        ]);
        log_activity($user['id'], 'user', (int)$user['id'], 'notification_preferences_updated', 'Notification preferences updated');
        respond(['ok' => true, 'data' => settings_notification_preferences((int)$user['id'])]);
    }

    respond(['ok' => false, 'error' => 'Not Found'], 404);
}

function settings_notification_preferences(int $userId): array {
    $defaults = [
        'platform_enabled' => 1,
        'email_enabled' => 1,
        'push_enabled' => 1,
        'approvals_enabled' => 1,
        'volunteering_enabled' => 1,
        'social_enabled' => 1,
        'calendar_enabled' => 1,
    ];
    $stmt = db()->prepare('SELECT * FROM user_notification_preferences WHERE user_id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return $defaults;
    }
    foreach ($defaults as $key => $default) {
        $defaults[$key] = isset($row[$key]) ? (int)$row[$key] : $default;
    }
    return $defaults;
}
