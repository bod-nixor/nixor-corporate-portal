<?php
function handle_members(string $method, array $segments): void {
    require_role(['admin']);
    $id = $segments[1] ?? null;

    if ($method === 'GET' && !$id) {
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 50;
        $offset = ($page - 1) * $limit;
        $stmt = db()->prepare('SELECT em.*, u.full_name, u.email, e.name AS entity_name FROM entity_memberships em JOIN users u ON em.user_id = u.id JOIN entities e ON em.entity_id = e.id ORDER BY e.name LIMIT ? OFFSET ?');
        $stmt->execute([$limit, $offset]);
        $rows = $stmt->fetchAll();
        $countStmt = db()->query('SELECT COUNT(*) as total FROM entity_memberships');
        $total = $countStmt->fetch()['total'];
        respond(['ok' => true, 'data' => $rows, 'meta' => ['page' => $page, 'limit' => $limit, 'total' => (int)$total]]);
    }

    if ($method === 'POST') {
        $data = read_json();
        if (empty($data['entity_id']) || !is_numeric($data['entity_id'])) {
            respond(['ok' => false, 'error' => 'entity_id required'], 400);
        }
        if (empty($data['user_id']) || !is_numeric($data['user_id'])) {
            respond(['ok' => false, 'error' => 'user_id required'], 400);
        }
        $entityId = (int)$data['entity_id'];
        $userId = (int)$data['user_id'];
        $entityCheck = db()->prepare('SELECT id FROM entities WHERE id = ?');
        $entityCheck->execute([$entityId]);
        if (!$entityCheck->fetch()) {
            respond(['ok' => false, 'error' => 'Entity not found'], 404);
        }
        $userCheck = db()->prepare('SELECT id FROM users WHERE id = ?');
        $userCheck->execute([$userId]);
        if (!$userCheck->fetch()) {
            respond(['ok' => false, 'error' => 'User not found'], 404);
        }
        $department = $data['department'] ?? 'other';
        $allowedDepartments = ['operations', 'finance', 'hr', 'communications', 'management', 'other'];
        if (!in_array($department, $allowedDepartments, true)) {
            respond(['ok' => false, 'error' => 'Invalid department'], 400);
        }
        $role = $data['role'] ?? 'member';
        $allowedRoles = ['manager', 'executive', 'member', 'volunteer'];
        if (!in_array($role, $allowedRoles, true)) {
            respond(['ok' => false, 'error' => 'Invalid role'], 400);
        }
        $stmt = db()->prepare('SELECT id FROM entity_memberships WHERE entity_id = ? AND user_id = ? AND department = ?');
        $stmt->execute([$entityId, $userId, $department]);
        if ($stmt->fetch()) {
            respond(['ok' => false, 'error' => 'Membership already exists'], 409);
        }
        $stmt = db()->prepare('INSERT INTO entity_memberships (entity_id, user_id, department, role, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $entityId,
            $userId,
            $department,
            $role,
            $data['start_date'] ?? null,
            $data['end_date'] ?? null
        ]);
        $membershipId = (int)db()->lastInsertId();
        log_activity($user['id'], 'member', $membershipId, 'created', 'Membership created');
        respond(['ok' => true, 'data' => ['id' => $membershipId]]);
    }

    if ($method === 'DELETE' && $id) {
        $check = db()->prepare('SELECT id FROM entity_memberships WHERE id = ?');
        $check->execute([$id]);
        if (!$check->fetch()) {
            respond(['ok' => false, 'error' => 'Membership not found'], 404);
        }
        $stmt = db()->prepare('DELETE FROM entity_memberships WHERE id = ?');
        $stmt->execute([$id]);
        log_activity($user['id'], 'member', (int)$id, 'deleted', 'Membership deleted');
        respond(['ok' => true]);
    }

    respond(['ok' => false, 'error' => 'Not Found'], 404);
}
