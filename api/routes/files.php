<?php
function handle_files(string $method, array $segments): void {
    if ($method !== 'GET') {
        respond(['ok' => false, 'error' => 'Not Found'], 404);
    }
    $action = $segments[1] ?? '';
    if ($action !== 'download') {
        respond(['ok' => false, 'error' => 'Not Found'], 404);
    }
    $type = $_GET['type'] ?? '';
    $id = (int)($_GET['id'] ?? 0);
    if (!$type || $id <= 0) {
        respond(['ok' => false, 'error' => 'type and id required'], 400);
    }

    if ($type === 'drive') {
        $user = require_auth();
        $stmt = db()->prepare('SELECT * FROM file_drive_items WHERE id = ? AND item_type = "file"');
        $stmt->execute([$id]);
        $item = $stmt->fetch();
        if (!$item) {
            respond(['ok' => false, 'error' => 'File not found'], 404);
        }
        drive_assert_can_view_item($user, $item);
        stream_download(resolve_upload_path($item['file_path']), $item['name']);
    } elseif ($type === 'endeavour_submission') {
        $user = require_auth();
        $stmt = db()->prepare(
            'SELECT es.*, e.entity_id, f.name, f.file_path, f.item_type, f.sharing_scope, f.created_by, f.entity_id AS file_entity_id
             FROM endeavour_submissions es
             JOIN endeavours e ON e.id = es.endeavour_id
             JOIN file_drive_items f ON f.id = es.file_drive_item_id
             WHERE es.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            respond(['ok' => false, 'error' => 'Submission not found'], 404);
        }
        $entityId = (int)$row['entity_id'];
        $allowed = can_permission($user, 'endeavour.view_confidential', $entityId)
            || can_permission($user, 'endeavour.submit_docs', $entityId)
            || can_permission($user, 'endeavour.approve_mob', $entityId)
            || can_permission($user, 'endeavour.approve_sa', $entityId);
        if (!$allowed) {
            respond(['ok' => false, 'error' => 'Forbidden'], 403);
        }
        stream_download(resolve_upload_path($row['file_path']), $row['name'] ?: 'submission');
    } elseif ($type === 'calendar_minutes') {
        $user = require_auth();
        $stmt = db()->prepare(
            'SELECT cmm.*, c.entity_id AS event_entity_id, f.name, f.file_path
             FROM calendar_meeting_minutes cmm
             JOIN calendar_events c ON c.id = cmm.event_id
             JOIN file_drive_items f ON f.id = cmm.file_drive_item_id
             WHERE cmm.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            respond(['ok' => false, 'error' => 'Meeting minutes not found'], 404);
        }
        $participantIds = [(int)$row['event_entity_id'], (int)$row['entity_id']];
        $participants = db()->prepare('SELECT entity_id FROM calendar_event_entities WHERE event_id = ?');
        $participants->execute([(int)$row['event_id']]);
        foreach ($participants->fetchAll(PDO::FETCH_COLUMN) as $entityId) {
            $participantIds[] = (int)$entityId;
        }
        $allowed = false;
        foreach (array_values(array_unique($participantIds)) as $entityId) {
            if (can_permission($user, 'calendar.minutes.view', $entityId) || can_permission($user, 'calendar.minutes.submit', $entityId)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            respond(['ok' => false, 'error' => 'Forbidden'], 403);
        }
        stream_download(resolve_upload_path($row['file_path']), $row['name'] ?: 'meeting-minutes');
    } elseif ($type === 'endeavour_document') {
        $stmt = db()->prepare('SELECT ed.*, e.entity_id FROM endeavour_documents ed JOIN endeavours e ON ed.endeavour_id = e.id WHERE ed.id = ?');
        $stmt->execute([$id]);
        $doc = $stmt->fetch();
        if (!$doc) {
            respond(['ok' => false, 'error' => 'Document not found'], 404);
        }
        $user = require_auth();
        if (!can_permission($user, 'endeavour.view_confidential', (int)$doc['entity_id']) && !can_permission($user, 'endeavour.submit_docs', (int)$doc['entity_id'])) {
            respond(['ok' => false, 'error' => 'Forbidden'], 403);
        }
        stream_download(resolve_upload_path($doc['file_path']), $doc['original_name'] ?: 'document');
    } else {
        respond(['ok' => false, 'error' => 'Not Found'], 404);
    }
}

function stream_download(string $path, string $filename): void {
    $resolvedFile = realpath($path);
    $uploadsBase = realpath(upload_base_path());
    $normalizedFile = $resolvedFile ? str_replace('\\', '/', $resolvedFile) : '';
    $normalizedBase = $uploadsBase ? rtrim(str_replace('\\', '/', $uploadsBase), '/') : '';
    if (!$resolvedFile || !$uploadsBase || !str_starts_with($normalizedFile, $normalizedBase . '/')) {
        respond(['ok' => false, 'error' => 'File not found'], 404);
    }
    if (!is_file($resolvedFile)) {
        respond(['ok' => false, 'error' => 'File not found'], 404);
    }
    $safeName = preg_replace('/[^\\w.\\-]/', '_', basename($filename));
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($resolvedFile));
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    readfile($resolvedFile);
    exit;
}
