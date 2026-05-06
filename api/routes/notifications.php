<?php
function handle_notifications(string $method, array $segments): void {
    $user = require_auth();
    $rawSegment1 = $segments[1] ?? null;
    $action = $segments[2] ?? null;

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
        respond(['ok' => true, 'data' => $stmt->fetchAll(), 'meta' => ['unread' => $unread]]);
    }

    if ($method === 'POST' && $id && $action === 'read') {
        $stmt = db()->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $user['id']]);
        respond(['ok' => true]);
    }


    respond(['ok' => false, 'error' => 'Not Found'], 404);
}
