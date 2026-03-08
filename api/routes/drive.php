<?php
function handle_drive(string $method, array $segments): void {
    $user = require_auth();
    $action = $segments[1] ?? '';

    if ($method === 'GET' && $action === 'list') {
        $entityId = (int)($_GET['entity_id'] ?? 0);
        $parentId = isset($_GET['parent_id']) && $_GET['parent_id'] !== '' ? (int)$_GET['parent_id'] : null;
        if ($entityId <= 0) {
            respond(['ok' => false, 'error' => 'entity_id required'], 400);
        }

        drive_assert_entity_context_access($user, $entityId);
        if ($parentId) {
            $parent = drive_get_item_by_id($parentId);
            if (!$parent || (int)$parent['entity_id'] !== $entityId || $parent['item_type'] !== 'folder') {
                respond(['ok' => false, 'error' => 'Parent folder not found'], 404);
            }
            drive_assert_can_view_item($user, $parent);
        }

        $stmt = db()->prepare('SELECT * FROM file_drive_items WHERE entity_id = ? AND ((? IS NULL AND parent_id IS NULL) OR parent_id = ?) ORDER BY FIELD(item_type, "folder", "file", "link"), name');
        $stmt->execute([$entityId, $parentId, $parentId]);
        $rows = $stmt->fetchAll();
        $shareTargetsMap = drive_item_share_targets_for_items(array_column($rows, 'id'));

        $items = [];
        foreach ($rows as $item) {
            if (!drive_user_can_view_item($user, $item)) {
                continue;
            }
            $targets = $shareTargetsMap[(int)$item['id']] ?? ['departments' => [], 'users' => []];
            $item['shared_departments'] = $targets['departments'];
            $item['shared_users'] = $targets['users'];
            $item['can_manage'] = drive_user_can_manage_item($user, $item);
            $items[] = $item;
        }

        $breadcrumbs = [];
        if ($parentId) {
            $parent = drive_get_item_by_id($parentId);
            if ($parent) {
                $breadcrumbs = drive_build_parent_chain($user, $parent);
                $breadcrumbs[] = ['id' => (int)$parent['id'], 'name' => $parent['name'], 'item_type' => 'folder'];
            }
        }

        respond(['ok' => true, 'data' => $items, 'meta' => ['parent_id' => $parentId, 'breadcrumbs' => $breadcrumbs]]);
    }

    if ($method === 'GET' && $action === 'item') {
        $itemId = (int)($_GET['id'] ?? 0);
        if ($itemId <= 0) {
            respond(['ok' => false, 'error' => 'id required'], 400);
        }
        $item = drive_get_item_by_id($itemId);
        if (!$item) {
            respond(['ok' => false, 'error' => 'Item not found'], 404);
        }
        drive_assert_can_view_item($user, $item);
        $shares = drive_item_share_targets($itemId);
        $item['shared_departments'] = $shares['departments'];
        $item['shared_users'] = $shares['users'];
        $item['parent_chain'] = drive_build_parent_chain($user, $item);
        $item['can_manage'] = drive_user_can_manage_item($user, $item);
        respond(['ok' => true, 'data' => $item]);
    }

    if ($method === 'GET' && $action === 'preview') {
        $itemId = (int)($_GET['id'] ?? 0);
        if ($itemId <= 0) {
            respond(['ok' => false, 'error' => 'id required'], 400);
        }
        $item = drive_get_item_by_id($itemId);
        if (!$item) {
            respond(['ok' => false, 'error' => 'Item not found'], 404);
        }
        drive_assert_can_view_item($user, $item);
        $preview = drive_build_preview_payload($item);
        respond(['ok' => true, 'data' => $preview]);
    }

    if ($method === 'GET' && $action === 'content') {
        $itemId = (int)($_GET['id'] ?? 0);
        if ($itemId <= 0) {
            respond(['ok' => false, 'error' => 'id required'], 400);
        }
        $item = drive_get_item_by_id($itemId);
        if (!$item || $item['item_type'] !== 'file') {
            respond(['ok' => false, 'error' => 'File not found'], 404);
        }
        drive_assert_can_view_item($user, $item);
        $path = resolve_upload_path($item['file_path']);
        clearstatcache(true, $path);
        if (!file_exists($path) || !is_readable($path)) {
            respond(['ok' => false, 'error' => 'File not found'], 404);
        }
        header_remove('Content-Security-Policy');
        header('Content-Type: ' . ($item['mime_type'] ?: 'application/octet-stream'));
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: inline; filename="' . preg_replace('/[^\w.\-]/', '_', basename($item['name'])) . '"');
        readfile($path);
        exit;
    }

    if ($method === 'GET' && $action === 'share_targets') {
        $entityId = (int)($_GET['entity_id'] ?? 0);
        if ($entityId <= 0) {
            respond(['ok' => false, 'error' => 'entity_id required'], 400);
        }
        drive_assert_entity_context_access($user, $entityId);
        $stmt = db()->prepare('SELECT DISTINCT u.id, u.full_name, u.email FROM entity_memberships em JOIN users u ON u.id = em.user_id WHERE em.entity_id = ? AND u.status = "active" ORDER BY u.full_name');
        $stmt->execute([$entityId]);
        respond(['ok' => true, 'data' => ['departments' => drive_departments(), 'users' => $stmt->fetchAll()]]);
    }

    if ($method === 'POST' && in_array($action, ['folder', 'create_folder'], true)) {
        $data = read_json();
        $entityId = (int)($data['entity_id'] ?? 0);
        if ($entityId <= 0) {
            respond(['ok' => false, 'error' => 'entity_id required'], 400);
        }
        drive_assert_entity_context_access($user, $entityId);

        $name = drive_validate_name((string)($data['name'] ?? 'New Folder'));
        $parent = drive_assert_manageable_parent($user, isset($data['parent_id']) ? (int)$data['parent_id'] : null, $entityId);
        $parentId = $parent ? (int)$parent['id'] : null;
        $sharingScope = drive_validate_sharing_scope((string)($data['sharing_scope'] ?? 'entity'));

        $stmt = db()->prepare('INSERT INTO file_drive_items (entity_id, parent_id, item_type, name, tags, sharing_scope, created_by) VALUES (?, ?, "folder", ?, ?, ?, ?)');
        $stmt->execute([$entityId, $parentId, $name, $data['tags'] ?? '', $sharingScope, $user['id']]);
        $folderId = (int)db()->lastInsertId();
        drive_replace_shares($folderId, $sharingScope, $data['departments'] ?? [], $data['users'] ?? []);
        log_activity($user['id'], 'drive_item', $folderId, 'created', 'Drive folder created');
        respond(['ok' => true, 'data' => ['id' => $folderId]]);
    }

    if ($method === 'POST' && $action === 'upload') {
        $entityId = (int)($_POST['entity_id'] ?? 0);
        if ($entityId <= 0) {
            respond(['ok' => false, 'error' => 'Missing entity_id'], 400);
        }
        drive_assert_entity_context_access($user, $entityId);
        if (!isset($_FILES['file'])) {
            respond(['ok' => false, 'error' => 'File missing'], 400);
        }
        $maxSize = 10 * 1024 * 1024;
        if (($_FILES['file']['size'] ?? 0) > $maxSize) {
            respond(['ok' => false, 'error' => 'File too large'], 400);
        }

        $parent = drive_assert_manageable_parent($user, isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : null, $entityId);
        $parentId = $parent ? (int)$parent['id'] : null;
        $sharingScope = drive_validate_sharing_scope((string)($_POST['sharing_scope'] ?? 'entity'));

        $uploaded = save_drive_file((string)$entityId, $_FILES['file']);
        $stmt = db()->prepare('INSERT INTO file_drive_items (entity_id, parent_id, item_type, name, file_path, mime_type, size_bytes, tags, sharing_scope, created_by) VALUES (?, ?, "file", ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$entityId, $parentId, $uploaded['original'], $uploaded['path'], $uploaded['mime'] ?? 'application/octet-stream', $uploaded['size'], $_POST['tags'] ?? '', $sharingScope, $user['id']]);
        $fileId = (int)db()->lastInsertId();
        drive_replace_shares($fileId, $sharingScope, $_POST['departments'] ?? [], $_POST['users'] ?? []);
        log_activity($user['id'], 'drive_item', $fileId, 'uploaded', 'Drive file uploaded');
        respond(['ok' => true, 'data' => ['id' => $fileId]]);
    }

    if ($method === 'POST' && $action === 'link') {
        $data = read_json();
        $entityId = (int)($data['entity_id'] ?? 0);
        if ($entityId <= 0) {
            respond(['ok' => false, 'error' => 'entity_id required'], 400);
        }
        drive_assert_entity_context_access($user, $entityId);
        $name = drive_validate_name((string)($data['name'] ?? ''));
        $url = drive_validate_url((string)($data['url'] ?? ''));
        $parent = drive_assert_manageable_parent($user, isset($data['parent_id']) ? (int)$data['parent_id'] : null, $entityId);
        $parentId = $parent ? (int)$parent['id'] : null;
        $sharingScope = drive_validate_sharing_scope((string)($data['sharing_scope'] ?? 'entity'));
        $mimeType = drive_detect_link_mime($url);

        $stmt = db()->prepare('INSERT INTO file_drive_items (entity_id, parent_id, item_type, name, url, mime_type, sharing_scope, created_by) VALUES (?, ?, "link", ?, ?, ?, ?, ?)');
        $stmt->execute([$entityId, $parentId, $name, $url, $mimeType, $sharingScope, $user['id']]);
        $itemId = (int)db()->lastInsertId();
        drive_replace_shares($itemId, $sharingScope, $data['departments'] ?? [], $data['users'] ?? []);
        log_activity($user['id'], 'drive_item', $itemId, 'created', 'Drive link created');
        respond(['ok' => true, 'data' => ['id' => $itemId]]);
    }

    if ($method === 'POST' && $action === 'rename') {
        $data = read_json();
        $itemId = (int)($data['id'] ?? 0);
        $name = drive_validate_name((string)($data['name'] ?? ''));
        $item = drive_get_item_by_id($itemId);
        if (!$item) {
            respond(['ok' => false, 'error' => 'Item not found'], 404);
        }
        drive_assert_item_entity_access($user, $item);
        drive_assert_can_manage_item($user, $item);
        $stmt = db()->prepare('UPDATE file_drive_items SET name = ? WHERE id = ?');
        $stmt->execute([$name, $itemId]);
        respond(['ok' => true]);
    }

    if ($method === 'POST' && $action === 'delete') {
        $data = read_json();
        $itemId = (int)($data['id'] ?? 0);
        $item = drive_get_item_by_id($itemId);
        if (!$item) {
            respond(['ok' => false, 'error' => 'Item not found'], 404);
        }
        drive_assert_item_entity_access($user, $item);
        drive_assert_can_manage_item($user, $item);

        $idsToDelete = $item['item_type'] === 'folder' ? drive_collect_folder_tree_ids($itemId) : [$itemId];
        $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
        $stmt = db()->prepare("SELECT id, item_type, file_path FROM file_drive_items WHERE id IN ({$placeholders})");
        $stmt->execute($idsToDelete);
        $rows = $stmt->fetchAll();

        db()->beginTransaction();
        try {
            $delShares = db()->prepare("DELETE FROM drive_item_shares WHERE item_id IN ({$placeholders})");
            $delShares->execute($idsToDelete);
            $delItems = db()->prepare("DELETE FROM file_drive_items WHERE id IN ({$placeholders})");
            $delItems->execute($idsToDelete);
            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            throw $e;
        }

        foreach ($rows as $row) {
            if (($row['item_type'] ?? '') !== 'file' || empty($row['file_path'])) {
                continue;
            }
            $path = upload_base_path() . '/' . ltrim($row['file_path'], '/');
            $resolved = realpath($path);
            $base = realpath(upload_base_path());
            if ($resolved && $base && str_starts_with($resolved, $base . DIRECTORY_SEPARATOR) && is_file($resolved)) {
                @unlink($resolved);
            }
        }
        respond(['ok' => true, 'data' => ['deleted_ids' => $idsToDelete]]);
    }

    if ($method === 'POST' && $action === 'share') {
        $data = read_json();
        $itemId = (int)($data['id'] ?? 0);
        $sharingScope = drive_validate_sharing_scope((string)($data['sharing_scope'] ?? 'entity'));
        $item = drive_get_item_by_id($itemId);
        if (!$item) {
            respond(['ok' => false, 'error' => 'Item not found'], 404);
        }
        drive_assert_item_entity_access($user, $item);
        drive_assert_can_manage_item($user, $item);
        $stmt = db()->prepare('UPDATE file_drive_items SET sharing_scope = ? WHERE id = ?');
        $stmt->execute([$sharingScope, $itemId]);
        drive_replace_shares($itemId, $sharingScope, $data['departments'] ?? [], $data['users'] ?? []);
        respond(['ok' => true]);
    }

    respond(['ok' => false, 'error' => 'Not Found'], 404);
}

function drive_replace_shares(int $itemId, string $scope, $departments, $users): void {
    $deleteStmt = db()->prepare('DELETE FROM drive_item_shares WHERE item_id = ?');
    $deleteStmt->execute([$itemId]);

    if ($scope === 'department') {
        $allowedDepartments = drive_departments();
        $insert = db()->prepare('INSERT INTO drive_item_shares (item_id, share_type, department) VALUES (?, "department", ?)');
        $values = is_array($departments) ? $departments : explode(',', (string)$departments);
        foreach ($values as $department) {
            $department = trim((string)$department);
            if (!in_array($department, $allowedDepartments, true)) {
                continue;
            }
            $insert->execute([$itemId, $department]);
        }
    }

    if ($scope === 'users') {
        $insert = db()->prepare('INSERT INTO drive_item_shares (item_id, share_type, user_id, user_email) VALUES (?, "user", ?, ?)');
        $entries = is_array($users) ? $users : explode(',', (string)$users);
        foreach ($entries as $entry) {
            $userId = null;
            $email = null;
            if (is_array($entry)) {
                $userId = isset($entry['user_id']) ? (int)$entry['user_id'] : null;
                $email = isset($entry['email']) ? strtolower(trim((string)$entry['email'])) : null;
            } elseif (is_numeric($entry)) {
                $userId = (int)$entry;
            } else {
                $email = strtolower(trim((string)$entry));
            }

            if ($userId) {
                $check = db()->prepare('SELECT id, email FROM users WHERE id = ? AND status = "active"');
                $check->execute([$userId]);
                $row = $check->fetch();
                if (!$row) {
                    continue;
                }
                $insert->execute([$itemId, $userId, strtolower((string)$row['email'])]);
                continue;
            }

            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $check = db()->prepare('SELECT id, email FROM users WHERE LOWER(email) = ? AND status = "active"');
                $check->execute([$email]);
                $row = $check->fetch();
                if (!$row) {
                    continue;
                }
                $insert->execute([$itemId, (int)$row['id'], strtolower((string)$row['email'])]);
            }
        }
    }
}

function drive_build_preview_payload(array $item): array {
    $type = $item['item_type'];
    if ($type === 'folder') {
        return ['kind' => 'folder', 'label' => 'Folder'];
    }

    if ($type === 'file') {
        $mime = strtolower((string)($item['mime_type'] ?? ''));
        if (str_starts_with($mime, 'application/pdf') || preg_match('/\.pdf$/i', (string)$item['name'])) {
            return [
                'kind' => 'pdf',
                'label' => 'PDF preview',
                'preview_url' => '/api/drive/content?id=' . urlencode((string)$item['id']),
                'open_url' => '/api/drive/content?id=' . urlencode((string)$item['id']),
                'download_url' => '/api/files/download?type=drive&id=' . urlencode((string)$item['id'])
            ];
        }
        return [
            'kind' => 'file',
            'label' => 'No inline preview',
            'open_url' => '/api/drive/content?id=' . urlencode((string)$item['id']),
            'download_url' => '/api/files/download?type=drive&id=' . urlencode((string)$item['id'])
        ];
    }

    $url = (string)($item['url'] ?? '');
    $youtubeEmbed = drive_youtube_embed_url($url);
    if ($youtubeEmbed) {
        return [
            'kind' => 'youtube',
            'label' => 'YouTube preview',
            'preview_url' => $youtubeEmbed,
            'open_url' => $url
        ];
    }

    if (preg_match('/\.pdf(?:$|\?)/i', $url)) {
        return [
            'kind' => 'pdf_link',
            'label' => 'PDF link',
            'preview_url' => $url,
            'open_url' => $url
        ];
    }

    return [
        'kind' => 'link',
        'label' => 'External link',
        'open_url' => $url,
        'host' => parse_url($url, PHP_URL_HOST)
    ];
}

function drive_youtube_embed_url(string $url): ?string {
    $parts = parse_url($url);
    if (!$parts) {
        return null;
    }
    $host = strtolower($parts['host'] ?? '');
    $path = $parts['path'] ?? '';
    parse_str($parts['query'] ?? '', $query);

    $videoId = null;
    if (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)) {
        if (str_starts_with($path, '/watch')) {
            $videoId = $query['v'] ?? null;
        } elseif (str_starts_with($path, '/embed/')) {
            $videoId = basename($path);
        }
    }
    if (in_array($host, ['youtu.be', 'www.youtu.be'], true)) {
        $videoId = ltrim($path, '/');
    }

    if (!$videoId || !preg_match('/^[a-zA-Z0-9_-]{6,20}$/', $videoId)) {
        return null;
    }
    return 'https://www.youtube-nocookie.com/embed/' . $videoId;
}

function drive_detect_link_mime(string $url): string {
    if (drive_youtube_embed_url($url)) {
        return 'video/youtube';
    }
    if (preg_match('/\.pdf(?:$|\?)/i', $url)) {
        return 'application/pdf';
    }
    return 'text/uri-list';
}
