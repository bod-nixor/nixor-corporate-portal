<?php

function drive_global_roles(): array {
    return ['admin', 'board', 'student_affairs'];
}

function drive_departments(): array {
    return ['operations', 'finance', 'hr', 'communications', 'management', 'other'];
}

function drive_user_membership(int $userId, int $entityId): ?array {
    $stmt = db()->prepare('SELECT * FROM entity_memberships WHERE user_id = ? AND entity_id = ? ORDER BY role = "manager" DESC, id ASC LIMIT 1');
    $stmt->execute([$userId, $entityId]);
    $membership = $stmt->fetch();
    return $membership ?: null;
}

function drive_is_global_role(array $user): bool {
    return in_array($user['global_role'] ?? '', drive_global_roles(), true);
}

function drive_is_entity_ceo(array $user, ?array $membership): bool {
    if (!$membership) {
        return false;
    }
    if (($membership['role'] ?? '') === 'manager' && ($membership['department'] ?? '') === 'management') {
        return true;
    }
    return ($user['global_role'] ?? '') === 'ceo';
}

function drive_get_item_by_id(int $itemId): ?array {
    $stmt = db()->prepare('SELECT * FROM file_drive_items WHERE id = ?');
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();
    return $item ?: null;
}

function drive_validate_name(string $name): string {
    $trimmed = trim($name);
    if ($trimmed === '' || mb_strlen($trimmed) > 190) {
        respond(['ok' => false, 'error' => 'Name must be between 1 and 190 characters'], 400);
    }
    if (preg_match('/[\\\\\/:*?"<>|]/', $trimmed)) {
        respond(['ok' => false, 'error' => 'Name contains forbidden characters'], 400);
    }
    return $trimmed;
}

function drive_validate_url(string $rawUrl): string {
    $url = trim($rawUrl);
    if ($url === '') {
        respond(['ok' => false, 'error' => 'url required'], 400);
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        respond(['ok' => false, 'error' => 'Invalid URL'], 400);
    }
    $parts = parse_url($url);
    $scheme = strtolower($parts['scheme'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true)) {
        respond(['ok' => false, 'error' => 'Only http/https URLs are allowed'], 400);
    }
    return $url;
}

function drive_validate_parent_id(?int $parentId, int $entityId): ?array {
    if (!$parentId) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM file_drive_items WHERE id = ? AND entity_id = ? AND item_type = "folder"');
    $stmt->execute([$parentId, $entityId]);
    $parent = $stmt->fetch();
    if (!$parent) {
        respond(['ok' => false, 'error' => 'Invalid parent_id'], 400);
    }
    return $parent;
}

function drive_validate_sharing_scope(string $scope): string {
    $allowed = ['private', 'entity', 'department', 'users'];
    if (!in_array($scope, $allowed, true)) {
        respond(['ok' => false, 'error' => 'Invalid sharing_scope'], 400);
    }
    return $scope;
}

function drive_item_share_targets(int $itemId): array {
    $stmt = db()->prepare('SELECT share_type, department, user_id, user_email FROM drive_item_shares WHERE item_id = ?');
    $stmt->execute([$itemId]);
    $departments = [];
    $users = [];
    foreach ($stmt->fetchAll() as $row) {
        if ($row['share_type'] === 'department' && $row['department']) {
            $departments[] = $row['department'];
        }
        if ($row['share_type'] === 'user') {
            $users[] = [
                'user_id' => $row['user_id'] ? (int)$row['user_id'] : null,
                'email' => $row['user_email']
            ];
        }
    }
    return [
        'departments' => array_values(array_unique($departments)),
        'users' => $users
    ];
}



function drive_item_share_targets_for_items(array $itemIds): array {
    $normalized = [];
    foreach ($itemIds as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $normalized[] = $id;
        }
    }
    $normalized = array_values(array_unique($normalized));
    if (!$normalized) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($normalized), '?'));
    $stmt = db()->prepare("SELECT item_id, share_type, department, user_id, user_email FROM drive_item_shares WHERE item_id IN ({$placeholders})");
    $stmt->execute($normalized);

    $map = [];
    foreach ($normalized as $itemId) {
        $map[$itemId] = ['departments' => [], 'users' => []];
    }

    foreach ($stmt->fetchAll() as $row) {
        $itemId = (int)$row['item_id'];
        if (!isset($map[$itemId])) {
            $map[$itemId] = ['departments' => [], 'users' => []];
        }
        if ($row['share_type'] === 'department' && $row['department']) {
            $map[$itemId]['departments'][] = $row['department'];
            continue;
        }
        if ($row['share_type'] === 'user') {
            $map[$itemId]['users'][] = [
                'user_id' => $row['user_id'] ? (int)$row['user_id'] : null,
                'email' => $row['user_email']
            ];
        }
    }

    foreach ($map as $itemId => $targets) {
        $map[$itemId]['departments'] = array_values(array_unique($targets['departments']));
    }

    return $map;
}

function drive_user_is_explicitly_shared(array $user, int $itemId): bool {
    $stmt = db()->prepare('SELECT 1 FROM drive_item_shares WHERE item_id = ? AND share_type = "user" AND (user_id = ? OR user_email = ?) LIMIT 1');
    $stmt->execute([$itemId, (int)$user['id'], strtolower((string)$user['email'])]);
    return (bool)$stmt->fetchColumn();
}

function drive_user_can_view_item(array $user, array $item): bool {
    if (drive_is_global_role($user)) {
        return true;
    }

    $membership = drive_user_membership((int)$user['id'], (int)$item['entity_id']);
    if (drive_is_entity_ceo($user, $membership)) {
        return true;
    }

    if (drive_user_is_explicitly_shared($user, (int)$item['id'])) {
        return true;
    }

    if (!$membership) {
        return false;
    }

    if ((int)$item['created_by'] === (int)$user['id']) {
        return true;
    }

    $scope = $item['sharing_scope'] ?? 'entity';
    if ($scope === 'entity') {
        return true;
    }
    if ($scope === 'private') {
        return false;
    }
    if ($scope === 'department') {
        $stmt = db()->prepare('SELECT 1 FROM drive_item_shares WHERE item_id = ? AND share_type = "department" AND department = ? LIMIT 1');
        $stmt->execute([(int)$item['id'], $membership['department']]);
        return (bool)$stmt->fetchColumn();
    }
    if ($scope === 'users') {
        return false;
    }
    return false;
}

function drive_user_can_manage_item(array $user, array $item): bool {
    if (drive_is_global_role($user)) {
        return true;
    }
    $membership = drive_user_membership((int)$user['id'], (int)$item['entity_id']);
    if (drive_is_entity_ceo($user, $membership)) {
        return true;
    }
    if (!$membership) {
        return false;
    }
    return (int)$item['created_by'] === (int)$user['id'];
}

function drive_manage_access_map(array $user, array $items): array {
    $map = [];
    if (!$items) {
        return $map;
    }

    if (drive_is_global_role($user)) {
        foreach ($items as $item) {
            $map[(int)$item['id']] = true;
        }
        return $map;
    }

    $membershipCache = [];
    foreach ($items as $item) {
        $entityId = (int)$item['entity_id'];
        if (!array_key_exists($entityId, $membershipCache)) {
            $membershipCache[$entityId] = drive_user_membership((int)$user['id'], $entityId);
        }
        $membership = $membershipCache[$entityId];
        $map[(int)$item['id']] = drive_is_entity_ceo($user, $membership)
            || ($membership && (int)$item['created_by'] === (int)$user['id']);
    }

    return $map;
}

function drive_assert_can_view_item(array $user, array $item): void {
    if (!drive_user_can_view_item($user, $item)) {
        respond(['ok' => false, 'error' => 'Forbidden'], 403);
    }
}

function drive_assert_can_manage_item(array $user, array $item, string $message = 'Forbidden'): void {
    if (!drive_user_can_manage_item($user, $item)) {
        respond(['ok' => false, 'error' => $message], 403);
    }
}

function drive_assert_manageable_parent(array $user, ?int $parentId, int $entityId, string $message = 'You cannot add items to this folder'): ?array {
    $parent = drive_validate_parent_id($parentId, $entityId);
    if ($parent) {
        drive_assert_can_manage_item($user, $parent, $message);
    }
    return $parent;
}

function drive_assert_entity_context_access(array $user, int $entityId): void {
    if (drive_is_global_role($user)) {
        return;
    }
    $membership = drive_user_membership((int)$user['id'], $entityId);
    if (!$membership) {
        respond(['ok' => false, 'error' => 'Entity access denied'], 403);
    }
}

function drive_assert_item_entity_access(array $user, array $item): void {
    drive_assert_entity_context_access($user, (int)$item['entity_id']);
}

function drive_build_parent_chain(array $user, array $item): array {
    $chain = [];
    $cursor = $item;
    while (!empty($cursor['parent_id'])) {
        $parent = drive_get_item_by_id((int)$cursor['parent_id']);
        if (!$parent) {
            break;
        }
        if (!drive_user_can_view_item($user, $parent)) {
            array_unshift($chain, [
                'id' => (int)$parent['id'],
                'name' => '[hidden]',
                'item_type' => null
            ]);
            break;
        }
        array_unshift($chain, [
            'id' => (int)$parent['id'],
            'name' => $parent['name'],
            'item_type' => $parent['item_type']
        ]);
        $cursor = $parent;
    }
    return $chain;
}

function drive_collect_folder_tree_ids(int $folderId): array {
    $ids = [$folderId];
    $queue = [$folderId];
    $index = 0;
    while ($index < count($queue)) {
        $current = $queue[$index++];
        $stmt = db()->prepare('SELECT id FROM file_drive_items WHERE parent_id = ?');
        $stmt->execute([$current]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $childId) {
            $childId = (int)$childId;
            $ids[] = $childId;
            $queue[] = $childId;
        }
    }
    return array_values(array_unique($ids));
}
