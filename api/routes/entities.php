<?php
function handle_entities(string $method, array $segments): void {
    $user = require_role(['admin']);
    $id = $segments[1] ?? null;

    if ($method === 'GET' && !$id) {
        $rows = db()->query('SELECT * FROM entities ORDER BY name')->fetchAll();
        respond(['ok' => true, 'data' => $rows]);
    }

    if ($method === 'GET' && $id) {
        $stmt = db()->prepare('SELECT * FROM entities WHERE id = ?');
        $stmt->execute([$id]);
        $entity = $stmt->fetch();
        if (!$entity) {
            respond(['ok' => false, 'error' => 'Entity not found'], 404);
        }
        respond(['ok' => true, 'data' => $entity]);
    }

    if ($method === 'POST' && !$id) {
        $data = read_json();
        $stmt = db()->prepare('INSERT INTO entities (name, description) VALUES (?, ?)');
        $stmt->execute([$data['name'] ?? '', $data['description'] ?? '']);
        $entityId = (int)db()->lastInsertId();
        log_activity($user['id'], 'entity', $entityId, 'created', 'Entity created');
        respond(['ok' => true, 'data' => ['id' => $entityId]]);
    }

    if ($method === 'PUT' && $id) {
        $data = read_json();
        $stmt = db()->prepare('UPDATE entities SET name = ?, description = ? WHERE id = ?');
        $stmt->execute([$data['name'] ?? '', $data['description'] ?? '', $id]);
        log_activity($user['id'], 'entity', (int)$id, 'updated', 'Entity updated');
        respond(['ok' => true, 'data' => ['id' => (int)$id]]);
    }

    if ($method === 'DELETE' && $id) {
        $stmt = db()->prepare('DELETE FROM entities WHERE id = ?');
        $stmt->execute([$id]);
        log_activity($user['id'], 'entity', (int)$id, 'deleted', 'Entity deleted');
        respond(['ok' => true]);
    }

    respond(['ok' => false, 'error' => 'Not Found'], 404);
}
