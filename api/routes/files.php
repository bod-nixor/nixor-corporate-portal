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
