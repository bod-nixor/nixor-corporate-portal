<?php
function handle_announcements(string $method, array $segments): void {
    $user = require_auth();
    $id = $segments[1] ?? null;

    if ($method === 'GET') {
        $entityId = (int)($_GET['entity_id'] ?? 0);
        if ($entityId <= 0) {
            respond(['ok' => false, 'error' => 'entity_id required'], 400);
        }
        require_permission('entity.view', $entityId, $user);
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $stmt = db()->prepare('SELECT a.*, u.full_name AS creator_name FROM dashboard_announcements a JOIN users u ON a.created_by = u.id WHERE a.entity_id = ? ORDER BY a.created_at DESC LIMIT ? OFFSET ?');
        $stmt->bindValue(1, $entityId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $canManage = can_permission($user, 'entity.announce', $entityId);
        $rows = array_map(fn($row) => announcements_decorate_row($row, $canManage), $stmt->fetchAll());
        respond(['ok' => true, 'data' => $rows]);
    }

    if ($method === 'POST' && !$id) {
        $data = read_json();
        $entityId = (int)($data['entity_id'] ?? 0);
        if ($entityId <= 0) {
            respond(['ok' => false, 'error' => 'entity_id required'], 400);
        }
        require_permission('entity.announce', $entityId, $user);
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
        require_permission('entity.announce', (int)$row['entity_id'], $user);
        $del = db()->prepare('DELETE FROM dashboard_announcements WHERE id = ?');
        $del->execute([$announcementId]);
        log_activity($user['id'], 'announcement', $announcementId, 'deleted', 'Announcement deleted');
        emit_ws_event('announcement.deleted', ['id' => $announcementId]);
        respond(['ok' => true]);
    }

    if (($method === 'PUT' || $method === 'PATCH') && $id) {
        $announcementId = (int)$id;
        $data = read_json();
        $check = db()->prepare('SELECT * FROM dashboard_announcements WHERE id = ?');
        $check->execute([$announcementId]);
        $row = $check->fetch();
        if (!$row) {
            respond(['ok' => false, 'error' => 'Announcement not found'], 404);
        }
        require_permission('entity.announce', (int)$row['entity_id'], $user);
        $title = require_non_empty($data['title'] ?? $row['title'], 'title', 190);
        $message = require_non_empty($data['message'] ?? $row['message'], 'message', 2000);
        $stmt = db()->prepare('UPDATE dashboard_announcements SET title = ?, message = ? WHERE id = ?');
        $stmt->execute([$title, $message, $announcementId]);
        log_activity($user['id'], 'announcement', $announcementId, 'updated', 'Announcement updated');
        emit_ws_event('announcement.updated', ['id' => $announcementId]);
        $fresh = db()->prepare('SELECT a.*, u.full_name AS creator_name FROM dashboard_announcements a JOIN users u ON a.created_by = u.id WHERE a.id = ?');
        $fresh->execute([$announcementId]);
        $freshRow = $fresh->fetch();
        respond(['ok' => true, 'data' => $freshRow ? announcements_decorate_row($freshRow, true) : ['id' => $announcementId]]);
    }

    respond(['ok' => false, 'error' => 'Not Found'], 404);
}

function announcements_decorate_row(array $row, bool $canManage): array {
    $row['can_edit'] = $canManage;
    $row['can_delete'] = $canManage;
    return $row;
}
