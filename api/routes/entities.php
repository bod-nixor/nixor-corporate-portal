<?php
function handle_entities(string $method, array $segments): void {
    $user = require_permission('admin.manage_entities');
    $id = $segments[1] ?? null;

    if ($method === 'GET' && !$id) {
        $rows = db()->query('SELECT * FROM entities ORDER BY name')->fetchAll();
        respond(['ok' => true, 'data' => array_map('decorate_entity_row', $rows)]);
    }

    if ($method === 'GET' && $id) {
        $entityId = resolve_public_or_internal_id('entities', $id);
        if (!$entityId) {
            respond(['ok' => false, 'error' => 'Entity not found'], 404);
        }
        $stmt = db()->prepare('SELECT * FROM entities WHERE id = ?');
        $stmt->execute([$entityId]);
        $entity = $stmt->fetch();
        if (!$entity) {
            respond(['ok' => false, 'error' => 'Entity not found'], 404);
        }
        respond(['ok' => true, 'data' => decorate_entity_row($entity)]);
    }

    if ($method === 'POST' && !$id) {
        $data = entity_request_data();
        $name = require_non_empty($data['name'] ?? '', 'name', 190);
        $description = sanitize_text($data['description'] ?? '', 2000);
        $publicId = generate_public_id('ent');
        $stmt = db()->prepare('INSERT INTO entities (public_id, name, description) VALUES (?, ?, ?)');
        try {
            $stmt->execute([$publicId, $name, $description]);
        } catch (PDOException $e) {
            if (is_duplicate_entity_name_error($e)) {
                respond(['ok' => false, 'error' => 'Entity already exists'], 409);
            }
            throw $e;
        }
        $entityId = (int)db()->lastInsertId();
        entity_save_avatar_if_present($entityId, $publicId);
        log_activity($user['id'], 'entity', $entityId, 'created', 'Entity created');
        respond(['ok' => true, 'data' => ['id' => $entityId, 'public_id' => $publicId]]);
    }

    if (($method === 'PUT' && $id) || ($method === 'POST' && $id && ($segments[2] ?? '') === 'update')) {
        $entityId = resolve_public_or_internal_id('entities', $id);
        if (!$entityId) {
            respond(['ok' => false, 'error' => 'Entity not found'], 404);
        }
        $data = entity_request_data();
        $name = require_non_empty($data['name'] ?? '', 'name', 190);
        $description = sanitize_text($data['description'] ?? '', 2000);
        $stmt = db()->prepare('UPDATE entities SET name = ?, description = ? WHERE id = ?');
        try {
            $stmt->execute([$name, $description, $entityId]);
        } catch (PDOException $e) {
            if (is_duplicate_entity_name_error($e)) {
                respond(['ok' => false, 'error' => 'Entity already exists'], 409);
            }
            throw $e;
        }
        $publicId = public_id_for_row('entities', $entityId) ?: generate_public_id('ent');
        entity_save_avatar_if_present($entityId, $publicId);
        log_activity($user['id'], 'entity', $entityId, 'updated', 'Entity updated');
        respond(['ok' => true, 'data' => ['id' => $entityId, 'public_id' => public_id_for_row('entities', $entityId)]]);
    }

    if ($method === 'DELETE' && $id) {
        $entityId = resolve_public_or_internal_id('entities', $id);
        if (!$entityId) {
            respond(['ok' => false, 'error' => 'Entity not found'], 404);
        }
        $stmt = db()->prepare('SELECT avatar_path FROM entities WHERE id = ?');
        $stmt->execute([$entityId]);
        $avatarPath = trim((string)$stmt->fetchColumn());
        if ($avatarPath !== '') {
            $absoluteAvatarPath = resolve_upload_path($avatarPath);
            if (is_file($absoluteAvatarPath)) {
                @unlink($absoluteAvatarPath);
            }
        }
        $stmt = db()->prepare('DELETE FROM entities WHERE id = ?');
        $stmt->execute([$entityId]);
        log_activity($user['id'], 'entity', $entityId, 'deleted', 'Entity deleted');
        respond(['ok' => true]);
    }

    respond(['ok' => false, 'error' => 'Not Found'], 404);
}

function entity_request_data(): array {
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
    if (!str_contains($contentType, 'multipart/form-data')) {
        return read_json();
    }
    return $_POST;
}

function decorate_entity_row(array $row): array {
    $publicId = $row['public_id'] ?: public_id_for_row('entities', (int)$row['id']);
    $row['public_id'] = $publicId;
    $row['avatar_url'] = (!empty($row['avatar_path']) && $publicId)
        ? '/api/files/download?type=entity_avatar&id=' . rawurlencode($publicId)
        : null;
    return $row;
}

function entity_save_avatar_if_present(int $entityId, string $entityPublicId): void {
    if (empty($_FILES['avatar']) || ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return;
    }
    $current = db()->prepare('SELECT avatar_path FROM entities WHERE id = ?');
    $current->execute([$entityId]);
    $oldPath = $current->fetchColumn() ?: null;
    $uploaded = save_entity_avatar_file($entityPublicId, $_FILES['avatar']);
    db()->prepare('UPDATE entities SET avatar_path = ?, avatar_mime_type = ?, avatar_original_name = ? WHERE id = ?')
        ->execute([$uploaded['path'], $uploaded['mime'], $uploaded['original'], $entityId]);
    if ($oldPath) {
        delete_uploaded_relative_path((string)$oldPath);
    }
}

function is_duplicate_entity_name_error(PDOException $e): bool {
    $driverCode = (int)($e->errorInfo[1] ?? 0);
    $message = strtolower($e->getMessage());
    return $e->getCode() === '23000' && ($driverCode === 1062 || str_contains($message, 'duplicate'));
}
