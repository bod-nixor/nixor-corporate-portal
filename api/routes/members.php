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
        $stmt = db()->prepare('INSERT INTO entity_memberships (entity_id, user_id, department, role, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $data['entity_id'],
            $data['user_id'],
            $data['department'] ?? 'other',
            $data['role'] ?? 'member',
            $data['start_date'] ?? null,
            $data['end_date'] ?? null
        ]);
        respond(['ok' => true, 'data' => ['id' => (int)db()->lastInsertId()]]);
    }

    if ($method === 'DELETE' && $id) {
        $check = db()->prepare('SELECT id FROM entity_memberships WHERE id = ?');
        $check->execute([$id]);
        if (!$check->fetch()) {
            respond(['ok' => false, 'error' => 'Membership not found'], 404);
        }
        $stmt = db()->prepare('DELETE FROM entity_memberships WHERE id = ?');
        $stmt->execute([$id]);
        respond(['ok' => true]);
    }

    respond(['ok' => false, 'error' => 'Not Found'], 404);
}
