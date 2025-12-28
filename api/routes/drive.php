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
        if (empty($data['entity_id'])) {
            respond(['ok' => false, 'error' => 'entity_id required'], 400);
        }
        $name = trim($data['name'] ?? 'New Folder');
        if ($name === '' || strlen($name) > 255) {
            respond(['ok' => false, 'error' => 'Invalid folder name'], 400);
        }
        ensure_entity_access((int)$data['entity_id'], []);
        $stmt = db()->prepare('INSERT INTO file_drive_items (entity_id, parent_id, item_type, name, tags, sharing_scope, created_by) VALUES (?, ?, "folder", ?, ?, ?, ?)');
        $stmt->execute([
            $data['entity_id'],
            $data['parent_id'] ?? null,
            $name,
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
        $maxSize = 10 * 1024 * 1024;
        if (($_FILES['file']['size'] ?? 0) > $maxSize) {
            respond(['ok' => false, 'error' => 'File too large'], 400);
        }
        $uploaded = save_drive_file((string)$entityId, $_FILES['file']);
        $stmt = db()->prepare('INSERT INTO file_drive_items (entity_id, parent_id, item_type, name, file_path, size_bytes, tags, sharing_scope, created_by) VALUES (?, ?, "file", ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $entityId,
            $_POST['parent_id'] ?? null,
            $uploaded['original'],
            $uploaded['path'],
            $uploaded['size'],
            $_POST['tags'] ?? '',
            $_POST['sharing_scope'] ?? 'entity',
            $user['id']
        ]);
        respond(['ok' => true, 'data' => ['id' => (int)db()->lastInsertId()]]);
    }

    respond(['ok' => false, 'error' => 'Not Found'], 404);
}
