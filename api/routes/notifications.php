<?php
function handle_notifications(string $method, array $segments): void {
    $user = require_auth();
    $id = isset($segments[1]) ? (int)$segments[1] : null;
    $action = $segments[2] ?? null;

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

    if ($method === 'POST' && !$id && $action === 'read-all') {
        $stmt = db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$user['id']]);
        respond(['ok' => true]);
    }

    respond(['ok' => false, 'error' => 'Not Found'], 404);
}
