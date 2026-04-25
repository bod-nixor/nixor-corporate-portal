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
        $stmt = db()->prepare('SELECT c.*, u.full_name FROM calendar_events c JOIN users u ON c.created_by = u.id WHERE c.entity_id = ? AND c.event_date >= "2000-01-01 00:00:00" AND c.event_date < "2101-01-01 00:00:00" ORDER BY c.event_date ASC LIMIT 50');
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
        $dateTime = parse_calendar_datetime($eventDate, 'event_date', true);
        $endDate = parse_calendar_datetime($data['end_date'] ?? ($data['event_end_at'] ?? null), 'end_date', false);
        if ($endDate && $endDate < $dateTime) {
            respond(['ok' => false, 'error' => 'end_date must not be before event_date'], 400);
        }
        $normalizedDate = $dateTime->format('Y-m-d H:i:s');
        $normalizedEndDate = $endDate ? $endDate->format('Y-m-d H:i:s') : null;
        $description = sanitize_text($data['description'] ?? '', 2000);
        $location = sanitize_text($data['location'] ?? '', 190);
        $stmt = db()->prepare('INSERT INTO calendar_events (entity_id, title, description, event_date, end_date, location, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$entityId, $title, $description, $normalizedDate, $normalizedEndDate, $location, $user['id']]);
        $eventId = (int)db()->lastInsertId();
        log_activity($user['id'], 'calendar_event', $eventId, 'created', 'Calendar event created');
        emit_ws_event('calendar.created', ['id' => $eventId]);
        respond(['ok' => true, 'data' => ['id' => $eventId]]);
    }

    if ($method === 'PUT' && $id) {
        $eventId = (int)$id;
        $event = require_event_writable($eventId, $user);
        $data = read_json();
        
        $entityId = isset($data['entity_id']) ? (int)$data['entity_id'] : (int)$event['entity_id'];
        if ($entityId <= 0) {
            respond(['ok' => false, 'error' => 'Invalid entity_id'], 400);
        }
        if ($entityId !== (int)$event['entity_id']) {
            ensure_entity_access($entityId, []);
        }

        $title = require_non_empty($data['title'] ?? $event['title'], 'title', 190);
        $eventDate = $data['event_date'] ?? $event['event_date'];
        $dateTime = parse_calendar_datetime($eventDate, 'event_date', true);
        $endDateRaw = array_key_exists('end_date', $data) ? $data['end_date'] : $event['end_date'];
        $endDate = parse_calendar_datetime($endDateRaw, 'end_date', false);
        if ($endDate && $endDate < $dateTime) {
            respond(['ok' => false, 'error' => 'end_date must not be before event_date'], 400);
        }
        $normalizedDate = $dateTime->format('Y-m-d H:i:s');
        $normalizedEndDate = $endDate ? $endDate->format('Y-m-d H:i:s') : null;
        $description = sanitize_text($data['description'] ?? $event['description'], 2000);
        $location = sanitize_text($data['location'] ?? $event['location'], 190);
        
        $stmt = db()->prepare('UPDATE calendar_events SET title = ?, description = ?, event_date = ?, end_date = ?, location = ?, entity_id = ? WHERE id = ?');
        $stmt->execute([$title, $description, $normalizedDate, $normalizedEndDate, $location, $entityId, $eventId]);
        log_activity($user['id'], 'calendar_event', $eventId, 'updated', 'Calendar event updated');
        emit_ws_event('calendar.updated', ['id' => $eventId]);
        respond(['ok' => true]);
    }

    if ($method === 'DELETE' && $id) {
        $eventId = (int)$id;
        require_event_writable($eventId, $user);
        $del = db()->prepare('DELETE FROM calendar_events WHERE id = ?');
        $del->execute([$eventId]);
        log_activity($user['id'], 'calendar_event', $eventId, 'deleted', 'Calendar event deleted');
        emit_ws_event('calendar.deleted', ['id' => $eventId]);
        respond(['ok' => true]);
    }

    respond(['ok' => false, 'error' => 'Not Found'], 404);
}

function require_event_writable($eventId, $user) {
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
    return $event;
}

function parse_calendar_datetime($value, string $field, bool $required): ?DateTime {
    if ($value === null || $value === '') {
        if ($required) {
            respond(['ok' => false, 'error' => "{$field} required"], 400);
        }
        return null;
    }

    $value = trim((string)$value);
    $formats = ['Y-m-d\\TH:i', DateTime::ATOM, 'Y-m-d H:i:s'];
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat('!' . $format, $value);
        $errors = DateTime::getLastErrors();
        $isValid = $dt
            && ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0))
            && $dt->format($format) === $value;
        if (!$isValid) {
            continue;
        }
        $year = (int)$dt->format('Y');
        if ($year < 2000 || $year > 2100) {
            respond(['ok' => false, 'error' => "{$field} must be between years 2000 and 2100"], 400);
        }
        return $dt;
    }

    respond(['ok' => false, 'error' => "Invalid {$field} format"], 400);
}

