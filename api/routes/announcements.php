<?php
function handle_announcements(string $method, array $segments): void {
    $user = require_auth();
    $id = $segments[1] ?? null;

    if ($method === 'GET') {
        $entityId = (int)($_GET['entity_id'] ?? 0);
        if ($entityId <= 0) {
            respond(['ok' => false, 'error' => 'entity_id required'], 400);
        }
        ensure_entity_access($entityId, []);
        $stmt = db()->prepare('SELECT a.*, u.full_name AS creator_name FROM dashboard_announcements a JOIN users u ON a.created_by = u.id WHERE a.entity_id = ? ORDER BY a.created_at DESC LIMIT 20');
        $stmt->execute([$entityId]);
        respond(['ok' => true, 'data' => $stmt->fetchAll()]);
    }

    if ($method === 'POST' && !$id) {
        $data = read_json();
        $entityId = (int)($data['entity_id'] ?? 0);
        if ($entityId <= 0) {
            respond(['ok' => false, 'error' => 'entity_id required'], 400);
        }
        ensure_entity_access($entityId, ['communications', 'management']);
        $title = require_non_empty($data['title'] ?? '', 'title', 190);
        $message = require_non_empty($data['message'] ?? '', 'message', 2000);
        $stmt = db()->prepare('INSERT INTO dashboard_announcements (entity_id, title, message, created_by) VALUES (?, ?, ?, ?)');
        $stmt->execute([$entityId, $title, $message, $user['id']]);
        $announcementId = (int)db()->lastInsertId();
        log_activity($user['id'], 'announcement', $announcementId, 'created', 'Announcement created');
        emit_ws_event('announcement.created', ['id' => $announcementId]);
        respond(['ok' => true, 'data' => ['id' => $announcementId]]);
    }

    if ($method === 'DELETE' && $id) {
        $announcementId = (int)$id;
        $check = db()->prepare('SELECT * FROM dashboard_announcements WHERE id = ?');
        $check->execute([$announcementId]);
        $row = $check->fetch();
        if (!$row) {
            respond(['ok' => false, 'error' => 'Announcement not found'], 404);
        }
        ensure_entity_access((int)$row['entity_id'], []);
        if ($user['global_role'] !== 'admin' && (int)$row['created_by'] !== (int)$user['id']) {
            respond(['ok' => false, 'error' => 'Forbidden'], 403);
        }
        $del = db()->prepare('DELETE FROM dashboard_announcements WHERE id = ?');
        $del->execute([$announcementId]);
        log_activity($user['id'], 'announcement', $announcementId, 'deleted', 'Announcement deleted');
        emit_ws_event('announcement.deleted', ['id' => $announcementId]);
        respond(['ok' => true]);
    }

    respond(['ok' => false, 'error' => 'Not Found'], 404);
}
