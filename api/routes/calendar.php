<?php
function handle_calendar(string $method, array $segments): void {
    $user = require_auth();
    $id = $segments[1] ?? null;

    if ($method === 'GET' && !$id) {
        $entityId = (int)($_GET['entity_id'] ?? 0);
        if ($entityId <= 0) {
            respond(['ok' => false, 'error' => 'entity_id required'], 400);
        }
        ensure_entity_access($entityId, []);
        $stmt = db()->prepare('SELECT c.*, u.full_name FROM calendar_events c JOIN users u ON c.created_by = u.id WHERE c.entity_id = ? ORDER BY c.event_date ASC LIMIT 50');
        $stmt->execute([$entityId]);
        respond(['ok' => true, 'data' => $stmt->fetchAll()]);
    }

    if ($method === 'POST' && !$id) {
        $data = read_json();
        $entityId = (int)($data['entity_id'] ?? 0);
        if ($entityId <= 0) {
            respond(['ok' => false, 'error' => 'entity_id required'], 400);
        }
        ensure_entity_access($entityId, []);
        $title = require_non_empty($data['title'] ?? '', 'title', 190);
        $eventDate = $data['event_date'] ?? '';
        if ($eventDate === '') {
            respond(['ok' => false, 'error' => 'event_date required'], 400);
        }
        $description = sanitize_text($data['description'] ?? '', 2000);
        $location = sanitize_text($data['location'] ?? '', 190);
        $stmt = db()->prepare('INSERT INTO calendar_events (entity_id, title, description, event_date, location, created_by) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$entityId, $title, $description, $eventDate, $location, $user['id']]);
        $eventId = (int)db()->lastInsertId();
        log_activity($user['id'], 'calendar_event', $eventId, 'created', 'Calendar event created');
        emit_ws_event('calendar.created', ['id' => $eventId]);
        respond(['ok' => true, 'data' => ['id' => $eventId]]);
    }

    if ($method === 'DELETE' && $id) {
        $eventId = (int)$id;
        $check = db()->prepare('SELECT * FROM calendar_events WHERE id = ?');
        $check->execute([$eventId]);
        $event = $check->fetch();
        if (!$event) {
            respond(['ok' => false, 'error' => 'Event not found'], 404);
        }
        ensure_entity_access((int)$event['entity_id'], []);
        if ($user['global_role'] !== 'admin' && (int)$event['created_by'] !== (int)$user['id']) {
            respond(['ok' => false, 'error' => 'Forbidden'], 403);
        }
        $del = db()->prepare('DELETE FROM calendar_events WHERE id = ?');
        $del->execute([$eventId]);
        log_activity($user['id'], 'calendar_event', $eventId, 'deleted', 'Calendar event deleted');
        emit_ws_event('calendar.deleted', ['id' => $eventId]);
        respond(['ok' => true]);
    }

    respond(['ok' => false, 'error' => 'Not Found'], 404);
}
