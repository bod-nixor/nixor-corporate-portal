<?php
function handle_calendar(string $method, array $segments): void {
    $user = require_auth();
    $id = $segments[1] ?? null;
    $action = $segments[2] ?? '';

    if ($method === 'GET' && !$id) {
        $entityId = (int)($_GET['entity_id'] ?? 0);
        if ($entityId <= 0) {
            respond(['ok' => false, 'error' => 'entity_id required'], 400);
        }
        require_permission('calendar.view', $entityId, $user);
        $events = calendar_events_for_entity($entityId, $user);
        respond(['ok' => true, 'data' => $events]);
    }

    if ($method === 'GET' && $id && $action === '') {
        $eventId = (int)$id;
        $event = calendar_fetch_event($eventId);
        if (!$event) {
            respond(['ok' => false, 'error' => 'Event not found'], 404);
        }
        calendar_require_event_view($event, $user);
        respond(['ok' => true, 'data' => calendar_decorate_event($event, $user)]);
    }

    if ($method === 'POST' && !$id) {
        $data = read_json();
        $entityId = (int)($data['entity_id'] ?? 0);
        if ($entityId <= 0) {
            respond(['ok' => false, 'error' => 'entity_id required'], 400);
        }
        require_permission('calendar.create', $entityId, $user);
        $title = require_non_empty($data['title'] ?? '', 'title', 190);
        $dateTime = parse_calendar_datetime($data['event_date'] ?? '', 'event_date', true);
        $endDate = parse_calendar_datetime($data['end_date'] ?? ($data['event_end_at'] ?? null), 'end_date', false);
        if ($endDate && $endDate < $dateTime) {
            respond(['ok' => false, 'error' => 'end_date must not be before event_date'], 400);
        }
        $description = sanitize_text($data['description'] ?? '', 2000);
        $location = sanitize_text($data['location'] ?? '', 190);
        $participantIds = calendar_normalize_participant_ids($entityId, $data['participant_entity_ids'] ?? [$entityId]);

        $pdo = db();
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO calendar_events (entity_id, title, description, event_date, end_date, location, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $entityId,
                $title,
                $description,
                $dateTime->format('Y-m-d H:i:s'),
                $endDate ? $endDate->format('Y-m-d H:i:s') : null,
                $location,
                $user['id'],
            ]);
            $eventId = (int)$pdo->lastInsertId();
            calendar_sync_participants($eventId, $entityId, $participantIds, (int)$user['id']);
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        log_activity($user['id'], 'calendar_event', $eventId, 'created', 'Calendar event created');
        emit_ws_event('calendar.created', ['id' => $eventId]);
        respond(['ok' => true, 'data' => ['id' => $eventId]]);
    }

    if ($method === 'PUT' && $id && $action === '') {
        $eventId = (int)$id;
        $event = require_event_writable($eventId, $user);
        $data = read_json();

        $entityId = isset($data['entity_id']) ? (int)$data['entity_id'] : (int)$event['entity_id'];
        if ($entityId <= 0) {
            respond(['ok' => false, 'error' => 'Invalid entity_id'], 400);
        }
        if ($entityId !== (int)$event['entity_id']) {
            require_permission('calendar.create', $entityId, $user);
        }

        $title = require_non_empty($data['title'] ?? $event['title'], 'title', 190);
        $dateTime = parse_calendar_datetime($data['event_date'] ?? $event['event_date'], 'event_date', true);
        $endDateRaw = array_key_exists('end_date', $data) ? $data['end_date'] : $event['end_date'];
        $endDate = parse_calendar_datetime($endDateRaw, 'end_date', false);
        if ($endDate && $endDate < $dateTime) {
            respond(['ok' => false, 'error' => 'end_date must not be before event_date'], 400);
        }
        $description = sanitize_text($data['description'] ?? $event['description'], 2000);
        $location = sanitize_text($data['location'] ?? $event['location'], 190);
        $participantIds = array_key_exists('participant_entity_ids', $data)
            ? calendar_normalize_participant_ids($entityId, $data['participant_entity_ids'])
            : null;

        $pdo = db();
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('UPDATE calendar_events SET title = ?, description = ?, event_date = ?, end_date = ?, location = ?, entity_id = ? WHERE id = ?');
            $stmt->execute([
                $title,
                $description,
                $dateTime->format('Y-m-d H:i:s'),
                $endDate ? $endDate->format('Y-m-d H:i:s') : null,
                $location,
                $entityId,
                $eventId,
            ]);
            if ($participantIds !== null) {
                calendar_sync_participants($eventId, $entityId, $participantIds, (int)$user['id']);
            }
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        log_activity($user['id'], 'calendar_event', $eventId, 'updated', 'Calendar event updated');
        emit_ws_event('calendar.updated', ['id' => $eventId]);
        respond(['ok' => true]);
    }

    if ($method === 'DELETE' && $id && $action === '') {
        $eventId = (int)$id;
        require_event_writable($eventId, $user);
        $del = db()->prepare('DELETE FROM calendar_events WHERE id = ?');
        $del->execute([$eventId]);
        log_activity($user['id'], 'calendar_event', $eventId, 'deleted', 'Calendar event deleted');
        emit_ws_event('calendar.deleted', ['id' => $eventId]);
        respond(['ok' => true]);
    }

    if ($method === 'POST' && $id && $action === 'rsvp') {
        $eventId = (int)$id;
        $event = calendar_fetch_event($eventId);
        if (!$event) {
            respond(['ok' => false, 'error' => 'Event not found'], 404);
        }
        $data = read_json();
        $entityId = calendar_resolve_action_entity($eventId, (int)($data['entity_id'] ?? 0), $user, 'calendar.rsvp');
        $status = (string)($data['status'] ?? '');
        if (!in_array($status, ['attending', 'absent', 'tentative'], true)) {
            respond(['ok' => false, 'error' => 'Invalid RSVP status'], 400);
        }
        $comment = sanitize_text($data['absence_comment'] ?? '', 500);
        if ($status !== 'absent') {
            $comment = null;
        }
        $stmt = db()->prepare(
            'INSERT INTO calendar_rsvps (event_id, entity_id, user_id, status, absence_comment)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE entity_id = VALUES(entity_id), status = VALUES(status), absence_comment = VALUES(absence_comment)'
        );
        $stmt->execute([$eventId, $entityId, $user['id'], $status, $comment]);
        log_activity($user['id'], 'calendar_event', $eventId, 'rsvp', 'Calendar RSVP recorded');
        respond(['ok' => true]);
    }

    if ($method === 'POST' && $id && $action === 'minutes') {
        $eventId = (int)$id;
        $event = calendar_fetch_event($eventId);
        if (!$event) {
            respond(['ok' => false, 'error' => 'Event not found'], 404);
        }
        $data = read_json();
        $entityId = calendar_resolve_action_entity($eventId, (int)($data['entity_id'] ?? 0), $user, 'calendar.minutes.submit');
        $fileId = filter_var($data['file_drive_item_id'] ?? ($data['file_id'] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$fileId) {
            respond(['ok' => false, 'error' => 'file_drive_item_id required'], 400);
        }
        $file = drive_get_item_by_id((int)$fileId);
        if (!$file || ($file['item_type'] ?? '') !== 'file' || (int)$file['entity_id'] !== $entityId) {
            respond(['ok' => false, 'error' => 'File not found for entity'], 400);
        }
        drive_assert_can_view_item($user, $file);
        $dueAt = calendar_minutes_due_at($event);
        $submittedAt = new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
        $isOverdue = $dueAt !== null && $submittedAt > $dueAt;
        $stmt = db()->prepare(
            'INSERT INTO calendar_meeting_minutes (event_id, entity_id, file_drive_item_id, submitted_by, due_at, is_overdue)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE file_drive_item_id = VALUES(file_drive_item_id), submitted_by = VALUES(submitted_by), submitted_at = CURRENT_TIMESTAMP, due_at = VALUES(due_at), is_overdue = VALUES(is_overdue)'
        );
        $stmt->execute([
            $eventId,
            $entityId,
            $fileId,
            $user['id'],
            $dueAt ? $dueAt->format('Y-m-d H:i:s') : null,
            $isOverdue ? 1 : 0,
        ]);
        log_activity($user['id'], 'calendar_event', $eventId, 'minutes_submitted', 'Meeting minutes submitted');
        respond(['ok' => true, 'data' => ['is_overdue' => $isOverdue]]);
    }

    respond(['ok' => false, 'error' => 'Not Found'], 404);
}

function calendar_events_for_entity(int $entityId, array $user): array {
    $stmt = db()->prepare(
        'SELECT DISTINCT c.*, u.full_name
         FROM calendar_events c
         JOIN users u ON c.created_by = u.id
         LEFT JOIN calendar_event_entities cee ON cee.event_id = c.id
         WHERE (c.entity_id = ? OR cee.entity_id = ?)
           AND c.event_date >= "2000-01-01 00:00:00"
           AND c.event_date < "2101-01-01 00:00:00"
         ORDER BY c.event_date ASC
         LIMIT 80'
    );
    $stmt->execute([$entityId, $entityId]);
    return array_map(fn($event) => calendar_decorate_event($event, $user), $stmt->fetchAll());
}

function calendar_fetch_event(int $eventId): ?array {
    $stmt = db()->prepare('SELECT c.*, u.full_name FROM calendar_events c JOIN users u ON c.created_by = u.id WHERE c.id = ?');
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();
    return $event ?: null;
}

function calendar_decorate_event(array $event, array $user): array {
    $eventId = (int)$event['id'];
    $event['can_manage'] = calendar_user_can_manage_event($user, $event);
    $event['participant_entities'] = calendar_participant_entities($eventId, (int)$event['entity_id']);
    $event['rsvps'] = calendar_rsvps($eventId);
    $event['minutes'] = calendar_minutes($eventId, $user);
    $event['minutes_due_at'] = ($due = calendar_minutes_due_at($event)) ? $due->format('Y-m-d H:i:s') : null;
    $event['can_rsvp'] = false;
    $event['can_submit_minutes'] = false;
    foreach ($event['participant_entities'] as $participant) {
        $participantEntityId = (int)$participant['id'];
        if (can_permission($user, 'calendar.rsvp', $participantEntityId)) {
            $event['can_rsvp'] = true;
        }
        if (can_permission($user, 'calendar.minutes.submit', $participantEntityId)) {
            $event['can_submit_minutes'] = true;
        }
    }
    return $event;
}

function calendar_require_event_view(array $event, array $user): void {
    foreach (calendar_participant_entity_ids((int)$event['id'], (int)$event['entity_id']) as $entityId) {
        if (can_permission($user, 'calendar.view', $entityId)) {
            return;
        }
    }
    respond(['ok' => false, 'error' => 'Forbidden'], 403);
}

function calendar_normalize_participant_ids(int $primaryEntityId, $rawEntityIds): array {
    $ids = [$primaryEntityId];
    if (is_array($rawEntityIds)) {
        foreach ($rawEntityIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
    }
    $ids = array_values(array_unique($ids));
    if (!$ids) {
        $ids = [$primaryEntityId];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $check = db()->prepare("SELECT id FROM entities WHERE id IN ({$placeholders})");
    $check->execute($ids);
    $validIds = array_map('intval', $check->fetchAll(PDO::FETCH_COLUMN));
    if (count($validIds) !== count($ids)) {
        respond(['ok' => false, 'error' => 'One or more participant entities are invalid'], 400);
    }
    return $ids;
}

function calendar_sync_participants(int $eventId, int $primaryEntityId, $rawEntityIds, int $actorId): void {
    $ids = calendar_normalize_participant_ids($primaryEntityId, $rawEntityIds);
    db()->prepare('DELETE FROM calendar_event_entities WHERE event_id = ?')->execute([$eventId]);
    $insert = db()->prepare('INSERT IGNORE INTO calendar_event_entities (event_id, entity_id, added_by) VALUES (?, ?, ?)');
    foreach ($ids as $entityId) {
        $insert->execute([$eventId, $entityId, $actorId]);
    }
}

function calendar_participant_entity_ids(int $eventId, int $primaryEntityId): array {
    $ids = [$primaryEntityId];
    $stmt = db()->prepare('SELECT entity_id FROM calendar_event_entities WHERE event_id = ?');
    $stmt->execute([$eventId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $ids[] = (int)$id;
    }
    return array_values(array_unique(array_filter($ids)));
}

function calendar_participant_entities(int $eventId, int $primaryEntityId): array {
    $ids = calendar_participant_entity_ids($eventId, $primaryEntityId);
    if (!$ids) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT id, name FROM entities WHERE id IN ({$placeholders}) ORDER BY name");
    $stmt->execute($ids);
    return array_map(static function ($entity) {
        return ['id' => (int)$entity['id'], 'name' => $entity['name']];
    }, $stmt->fetchAll());
}

function calendar_rsvps(int $eventId): array {
    $stmt = db()->prepare(
        'SELECT cr.*, u.full_name, e.name AS entity_name
         FROM calendar_rsvps cr
         JOIN users u ON u.id = cr.user_id
         JOIN entities e ON e.id = cr.entity_id
         WHERE cr.event_id = ?
         ORDER BY e.name, u.full_name'
    );
    $stmt->execute([$eventId]);
    return $stmt->fetchAll();
}

function calendar_minutes(int $eventId, array $user): array {
    $stmt = db()->prepare(
        'SELECT cmm.*, e.name AS entity_name, f.name AS file_name, u.full_name AS submitted_by_name
         FROM calendar_meeting_minutes cmm
         JOIN entities e ON e.id = cmm.entity_id
         JOIN file_drive_items f ON f.id = cmm.file_drive_item_id
         JOIN users u ON u.id = cmm.submitted_by
         WHERE cmm.event_id = ?
         ORDER BY e.name'
    );
    $stmt->execute([$eventId]);
    $minutes = [];
    foreach ($stmt->fetchAll() as $row) {
        $entityId = (int)$row['entity_id'];
        if (!can_permission($user, 'calendar.minutes.view', $entityId) && !can_permission($user, 'calendar.minutes.submit', $entityId)) {
            continue;
        }
        $row['download_url'] = '/api/files/download?type=calendar_minutes&id=' . urlencode((string)$row['id']);
        $minutes[] = $row;
    }
    return $minutes;
}

function calendar_resolve_action_entity(int $eventId, int $requestedEntityId, array $user, string $permission): int {
    $event = calendar_fetch_event($eventId);
    if (!$event) {
        respond(['ok' => false, 'error' => 'Event not found'], 404);
    }
    $participantIds = calendar_participant_entity_ids($eventId, (int)$event['entity_id']);
    if ($requestedEntityId > 0) {
        if (!in_array($requestedEntityId, $participantIds, true)) {
            respond(['ok' => false, 'error' => 'Entity is not part of this meeting'], 403);
        }
        require_permission($permission, $requestedEntityId, $user);
        return $requestedEntityId;
    }
    foreach ($participantIds as $entityId) {
        if (can_permission($user, $permission, $entityId)) {
            return $entityId;
        }
    }
    respond(['ok' => false, 'error' => 'Forbidden'], 403);
}

function calendar_minutes_due_at(array $event): ?DateTimeImmutable {
    $endRaw = $event['end_date'] ?: $event['event_date'];
    if (!$endRaw) {
        return null;
    }
    $tz = new DateTimeZone(date_default_timezone_get());
    $end = new DateTimeImmutable(str_replace('T', ' ', (string)$endRaw), $tz);
    return $end->modify('+36 hours');
}

function require_event_writable(int $eventId, array $user): array {
    $event = calendar_fetch_event($eventId);
    if (!$event) {
        respond(['ok' => false, 'error' => 'Event not found'], 404);
    }
    if (!calendar_user_can_manage_event($user, $event)) {
        respond(['ok' => false, 'error' => 'Forbidden'], 403);
    }
    return $event;
}

function calendar_user_can_manage_event(array $user, array $event): bool {
    $entityId = (int)($event['entity_id'] ?? 0);
    if ($entityId <= 0) {
        return false;
    }
    if (can_permission($user, 'calendar.manage', $entityId)) {
        return true;
    }
    return (int)($event['created_by'] ?? 0) === (int)$user['id'] && can_permission($user, 'calendar.create', $entityId);
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
