<?php
function handle_drive(string $method, array $segments): void {
    $user = require_auth();
    $action = $segments[1] ?? '';

    if ($method === 'GET' && $action === 'list') {
        $entityId = (int)($_GET['entity_id'] ?? 0);
        ensure_entity_access($entityId, []);
        $stmt = db()->prepare('SELECT * FROM file_drive_items WHERE entity_id = ? ORDER BY item_type DESC, name');
        $stmt->execute([$entityId]);
        respond(['ok' => true, 'data' => $stmt->fetchAll()]);
    }

    if ($method === 'POST' && $action === 'create_folder') {
        $data = read_json();
        ensure_entity_access((int)$data['entity_id'], []);
        $stmt = db()->prepare('INSERT INTO file_drive_items (entity_id, parent_id, item_type, name, tags, sharing_scope, created_by) VALUES (?, ?, "folder", ?, ?, ?, ?)');
        $stmt->execute([
            $data['entity_id'],
            $data['parent_id'] ?? null,
            $data['name'] ?? 'New Folder',
            $data['tags'] ?? '',
            $data['sharing_scope'] ?? 'entity',
            $user['id']
        ]);
        respond(['ok' => true, 'data' => ['id' => (int)db()->lastInsertId()]]);
    }

    if ($method === 'POST' && $action === 'upload') {
        $entityId = (int)($_POST['entity_id'] ?? 0);
        ensure_entity_access($entityId, []);
        if (!isset($_FILES['file'])) {
            respond(['ok' => false, 'error' => 'File missing'], 400);
        }
        $dir = dirname(__DIR__, 2) . '/uploads/drive/' . $entityId;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $_FILES['file']['name']);
        $path = $dir . '/' . $filename;
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $path)) {
            respond(['ok' => false, 'error' => 'Upload failed'], 500);
        }
        $relative = str_replace(dirname(__DIR__, 2), '', $path);
        $stmt = db()->prepare('INSERT INTO file_drive_items (entity_id, parent_id, item_type, name, file_path, size_bytes, tags, sharing_scope, created_by) VALUES (?, ?, "file", ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $entityId,
            $_POST['parent_id'] ?? null,
            $_FILES['file']['name'],
            $relative,
            $_FILES['file']['size'] ?? 0,
            $_POST['tags'] ?? '',
            $_POST['sharing_scope'] ?? 'entity',
            $user['id']
        ]);
        respond(['ok' => true, 'data' => ['id' => (int)db()->lastInsertId()]]);
    }

    respond(['ok' => false, 'error' => 'Not Found'], 404);
}
