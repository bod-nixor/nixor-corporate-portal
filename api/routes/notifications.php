<?php
function handle_notifications(string $method, array $segments): void {
    $user = require_auth();
    $rawSegment1 = $segments[1] ?? null;
    $action = $segments[2] ?? null;

    if (($method === 'POST' || $method === 'DELETE') && $rawSegment1 === 'push-token') {
        handle_notification_push_token($method, $user);
        return;
    }

    // Match "read-all" before casting segment 1 to int
    if ($method === 'POST' && $rawSegment1 === 'read-all') {
        $stmt = db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$user['id']]);
        respond(['ok' => true]);
    }

    $id = $rawSegment1 !== null ? (int)$rawSegment1 : null;

    if ($method === 'GET' && !$id) {
        $limit = isset($_GET['limit']) ? min(50, max(1, (int)$_GET['limit'])) : 20;
        $stmt = db()->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?');
        $stmt->bindValue(1, $user['id'], PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $countStmt = db()->prepare('SELECT COUNT(*) as total FROM notifications WHERE user_id = ? AND is_read = 0');
        $countStmt->execute([$user['id']]);
        $unread = (int)$countStmt->fetch()['total'];
        $rows = array_map('notifications_decorate_row', $stmt->fetchAll());
        respond(['ok' => true, 'data' => $rows, 'meta' => ['unread' => $unread]]);
    }

    if ($method === 'POST' && $id && $action === 'read') {
        $stmt = db()->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $user['id']]);
        respond(['ok' => true]);
    }


    respond(['ok' => false, 'error' => 'Not Found'], 404);
}

function handle_notification_push_token(string $method, array $user): void {
    $data = read_json();
    $token = trim((string)($data['token'] ?? ''));
    $deviceId = trim((string)($data['device_id'] ?? ''));
    $platform = strtolower(trim((string)($data['platform'] ?? 'web')));
    if (!in_array($platform, ['ios', 'android', 'web'], true)) {
        respond(['ok' => false, 'error' => 'Invalid platform'], 400);
    }
    if ($token === '' || strlen($token) > 512) {
        respond(['ok' => false, 'error' => 'Valid token required'], 400);
    }
    if (strlen($deviceId) > 190) {
        respond(['ok' => false, 'error' => 'Invalid device_id'], 400);
    }

    if ($method === 'POST') {
        $stmt = db()->prepare(
            'INSERT INTO push_device_tokens (user_id, platform, token, device_id, enabled, last_seen_at)
             VALUES (?, ?, ?, ?, 1, NOW())
             ON DUPLICATE KEY UPDATE
               user_id = VALUES(user_id),
               platform = VALUES(platform),
               device_id = VALUES(device_id),
               enabled = 1,
               last_seen_at = NOW()'
        );
        $stmt->execute([(int)$user['id'], $platform, $token, $deviceId !== '' ? $deviceId : null]);
        respond(['ok' => true, 'data' => ['registered' => true, 'push_configured' => push_provider_configured()]]);
    }

    if ($method === 'DELETE') {
        $stmt = db()->prepare('UPDATE push_device_tokens SET enabled = 0, last_seen_at = NOW() WHERE user_id = ? AND token = ?');
        $stmt->execute([(int)$user['id'], $token]);
        respond(['ok' => true, 'data' => ['registered' => false]]);
    }

    respond(['ok' => false, 'error' => 'Not Found'], 404);
}

function notifications_decorate_row(array $row): array {
    $payload = [];
    if (!empty($row['payload_json'])) {
        $decoded = json_decode((string)$row['payload_json'], true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    $context = notifications_context($payload);
    $type = (string)($row['type'] ?? '');
    $docLabel = notifications_doc_label((string)($payload['doc_type'] ?? ''));
    $endeavourName = $context['endeavour_name'] ?? ($payload['title'] ?? null);
    $entityName = $context['entity_name'] ?? null;
    $fallbackType = notifications_type_label($type);

    $title = $fallbackType;
    $message = '';

    if ($type === 'submission_pending_mob') {
        $title = 'Document approval needed';
        $subject = $endeavourName ? "{$docLabel} for {$endeavourName}" : $docLabel;
        $message = "{$subject} is waiting for Member of Board approval.";
    } elseif ($type === 'submission_rejected') {
        $title = "{$docLabel} needs changes";
        $subject = $endeavourName ? "{$docLabel} for {$endeavourName}" : $docLabel;
        $comment = trim((string)($payload['comment'] ?? ''));
        $message = "{$subject} was rejected.";
        if ($comment !== '') {
            $message .= ' ' . notifications_truncate("Comment: {$comment}", 180);
        }
    } elseif ($type === 'volunteer_shortlisted') {
        $title = 'Volunteer shortlist update';
        $subject = $endeavourName ?: 'this endeavour';
        $message = "You have been shortlisted for {$subject}.";
    }

    if ($message === '') {
        $payloadMessage = trim((string)($payload['message'] ?? ''));
        $message = $payloadMessage !== ''
            ? notifications_truncate($payloadMessage, 220)
            : "New {$fallbackType} update.";
    }

    if ($entityName && !str_contains($message, $entityName)) {
        $message .= " Entity: {$entityName}.";
    }

    $row['payload'] = $payload;
    $row['title'] = notifications_truncate((string)($payload['title'] ?? $title), 120);
    $row['message'] = notifications_truncate($message, 240);
    $row['type_label'] = $fallbackType;
    $row['entity_name'] = $entityName;
    $row['endeavour_name'] = $context['endeavour_name'] ?? null;
    $row['target_url'] = notifications_target_url($payload, $context);

    return $row;
}

function notifications_context(array $payload): array {
    $context = [];
    $endeavourId = (int)($payload['endeavour_id'] ?? 0);
    if ($endeavourId > 0) {
        $stmt = db()->prepare('SELECT e.id, e.name AS endeavour_name, e.entity_id, en.name AS entity_name FROM endeavours e JOIN entities en ON en.id = e.entity_id WHERE e.id = ?');
        $stmt->execute([$endeavourId]);
        $row = $stmt->fetch();
        if ($row) {
            $context['endeavour_id'] = (int)$row['id'];
            $context['endeavour_name'] = $row['endeavour_name'];
            $context['entity_id'] = (int)$row['entity_id'];
            $context['entity_name'] = $row['entity_name'];
            return $context;
        }
    }

    $entityId = (int)($payload['entity_id'] ?? 0);
    if ($entityId > 0) {
        $stmt = db()->prepare('SELECT id, name FROM entities WHERE id = ?');
        $stmt->execute([$entityId]);
        $row = $stmt->fetch();
        if ($row) {
            $context['entity_id'] = (int)$row['id'];
            $context['entity_name'] = $row['name'];
        }
    }
    return $context;
}

function notifications_doc_label(string $docType): string {
    $labels = [
        'operational_plan' => 'Operational plan',
        'ops_plan' => 'Operational plan',
        'budget_plan' => 'Budget plan',
        'pre_financial' => 'Pre-financial report',
        'post_financial' => 'Post-financial report',
        'epilogue' => 'Epilogue',
        'mou' => 'MOU',
    ];
    return $labels[$docType] ?? notifications_type_label($docType ?: 'document');
}

function notifications_type_label(string $type): string {
    $type = trim($type);
    if ($type === '') {
        return 'Notification';
    }
    $label = str_replace(['_', '-'], ' ', $type);
    $label = preg_replace('/\s+/', ' ', $label) ?: $label;
    return ucwords($label);
}

function notifications_target_url(array $payload, array $context): ?string {
    foreach (['target_url', 'url', 'href'] as $key) {
        $url = trim((string)($payload[$key] ?? ''));
        if ($url !== '' && str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return $url;
        }
    }

    if (!empty($payload['post_public_id'])) {
        return public_relative_url('social.html', [
            'feed' => ($payload['feed_scope'] ?? '') === 'entity' ? 'entity' : 'global',
            'e' => $payload['entity_public_id'] ?? null,
            'p' => $payload['post_public_id'],
            'c' => $payload['comment_public_id'] ?? null,
        ]);
    }

    if (!empty($payload['endeavour_public_id'])) {
        return '/endeavour_view.html?e=' . rawurlencode((string)$payload['endeavour_public_id']);
    }
    if (!empty($context['endeavour_id'])) {
        $publicId = public_id_for_row('endeavours', (int)$context['endeavour_id']);
        return '/endeavour_view.html?' . ($publicId ? 'e=' . rawurlencode($publicId) : 'id=' . (int)$context['endeavour_id']);
    }
    if (!empty($payload['entity_public_id'])) {
        return '/dashboard.html?e=' . rawurlencode((string)$payload['entity_public_id']);
    }
    if (!empty($context['entity_id'])) {
        $publicId = public_id_for_row('entities', (int)$context['entity_id']);
        return '/dashboard.html?' . ($publicId ? 'e=' . rawurlencode($publicId) : 'entity_id=' . (int)$context['entity_id']);
    }
    return null;
}

function notifications_truncate(string $value, int $maxLength): string {
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    if (mb_strlen($value, 'UTF-8') <= $maxLength) {
        return $value;
    }
    return rtrim(mb_substr($value, 0, max(0, $maxLength - 1), 'UTF-8')) . '...';
}
