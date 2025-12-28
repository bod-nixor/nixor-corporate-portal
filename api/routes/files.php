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
        $stmt = db()->prepare('SELECT f.*, e.id as entity_id FROM file_drive_items f JOIN entities e ON f.entity_id = e.id WHERE f.id = ? AND f.item_type = "file"');
        $stmt->execute([$id]);
        $item = $stmt->fetch();
        if (!$item) {
            respond(['ok' => false, 'error' => 'File not found'], 404);
        }
        ensure_entity_access((int)$item['entity_id'], []);
        stream_download(resolve_upload_path($item['file_path']), $item['name']);
    } elseif ($type === 'endeavour_document') {
        $stmt = db()->prepare('SELECT ed.*, e.entity_id FROM endeavour_documents ed JOIN endeavours e ON ed.endeavour_id = e.id WHERE ed.id = ?');
        $stmt->execute([$id]);
        $doc = $stmt->fetch();
        if (!$doc) {
            respond(['ok' => false, 'error' => 'Document not found'], 404);
        }
        ensure_entity_access((int)$doc['entity_id'], []);
        stream_download(resolve_upload_path($doc['file_path']), $doc['original_name'] ?: 'document');
    } else {
        respond(['ok' => false, 'error' => 'Not Found'], 404);
    }
}

function stream_download(string $path, string $filename): void {
    if (!is_file($path)) {
        respond(['ok' => false, 'error' => 'File not found'], 404);
    }
    $safeName = basename($filename);
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    readfile($path);
    exit;
}
