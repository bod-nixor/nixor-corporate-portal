<?php
function handle_users(string $method, array $segments): void {
    $user = require_role(['admin']);
    $id = $segments[1] ?? null;

    if ($method === 'GET' && !$id) {
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 50;
        $offset = ($page - 1) * $limit;
        $stmt = db()->prepare('SELECT id, email, full_name, global_role, status, created_at FROM users ORDER BY created_at DESC LIMIT ? OFFSET ?');
        $stmt->execute([$limit, $offset]);
        $count = db()->query('SELECT COUNT(*) as total FROM users')->fetch();
        respond(['ok' => true, 'data' => $stmt->fetchAll(), 'meta' => ['page' => $page, 'limit' => $limit, 'total' => (int)$count['total']]]);
    }

    if ($method === 'POST' && !$id) {
        $data = read_json();
        $email = validate_email_address($data['email'] ?? '', 'email');
        $fullName = require_non_empty($data['full_name'] ?? '', 'full_name', 190);
        $password = $data['password'] ?? '';
        if (strlen($password) < 8) {
            respond(['ok' => false, 'error' => 'Password must be at least 8 characters'], 400);
        }
        $role = $data['global_role'] ?? 'volunteer';
        $allowedRoles = ['admin', 'board', 'ceo', 'staff', 'volunteer'];
        if (!in_array($role, $allowedRoles, true)) {
            respond(['ok' => false, 'error' => 'Invalid global_role'], 400);
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = db()->prepare('INSERT INTO users (email, password_hash, full_name, global_role) VALUES (?, ?, ?, ?)');
        try {
            $stmt->execute([$email, $hash, $fullName, $role]);
        } catch (PDOException $e) {
            if ((int)$e->getCode() === 23000) {
                respond(['ok' => false, 'error' => 'Email already exists'], 409);
            }
            throw $e;
        }
        $userId = (int)db()->lastInsertId();
        log_activity($user['id'], 'user', $userId, 'created', 'User created');
        respond(['ok' => true, 'data' => ['id' => $userId]]);
    }

    if ($method === 'PUT' && $id) {
        $data = read_json();
        $userId = (int)$id;
        $status = $data['status'] ?? null;
        $role = $data['global_role'] ?? null;
        if (!$status && !$role) {
            respond(['ok' => false, 'error' => 'status or global_role required'], 400);
        }
        if ($status && !in_array($status, ['active', 'suspended', 'deleted'], true)) {
            respond(['ok' => false, 'error' => 'Invalid status'], 400);
        }
        if ($role && !in_array($role, ['admin', 'board', 'ceo', 'staff', 'volunteer'], true)) {
            respond(['ok' => false, 'error' => 'Invalid global_role'], 400);
        }
        $fields = [];
        $values = [];
        if ($status) {
            $fields[] = 'status = ?';
            $values[] = $status;
        }
        if ($role) {
            $fields[] = 'global_role = ?';
            $values[] = $role;
        }
        $values[] = $userId;
        $stmt = db()->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->execute($values);
        log_activity($user['id'], 'user', $userId, 'updated', 'User updated');
        respond(['ok' => true]);
    }

    respond(['ok' => false, 'error' => 'Not Found'], 404);
}
