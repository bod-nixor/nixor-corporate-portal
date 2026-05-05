<?php
function handle_users(string $method, array $segments): void {
    $user = require_permission('admin.manage_users');
    $allowedRoles = ['admin', 'board', 'ceo', 'staff', 'student_affairs', 'volunteer'];
    $id = $segments[1] ?? null;
    $action = $segments[2] ?? null;

    if ($id && $action === 'roles') {
        handle_user_role_assignments($method, (int)$id, $segments, $user);
        return;
    }

    if ($id && $action === 'force-password-reset') {
        handle_user_force_password_reset($method, (int)$id, $user);
        return;
    }

    if ($method === 'GET' && !$id) {
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 50;
        $offset = ($page - 1) * $limit;
        try {
            $stmt = db()->prepare(
                'SELECT id, email, full_name, global_role, status, force_password_reset, password_setup_required, password_changed_at, password_reset_forced_at, created_at
                 FROM users
                 ORDER BY created_at DESC
                 LIMIT ? OFFSET ?'
            );
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->bindValue(2, $offset, PDO::PARAM_INT);
            $stmt->execute();
            $count = db()->query('SELECT COUNT(*) as total FROM users')->fetch();
            respond(['ok' => true, 'data' => $stmt->fetchAll(), 'meta' => ['page' => $page, 'limit' => $limit, 'total' => (int)$count['total']]]);
        } catch (PDOException $e) {
            error_log('Failed to load users: ' . $e->getMessage());
            respond(['ok' => false, 'error' => 'Failed to load users'], 500);
        }
    }

    if ($method === 'POST' && !$id) {
        $data = read_json();
        $email = validate_email_address($data['email'] ?? '', 'email');
        $fullName = require_non_empty($data['full_name'] ?? '', 'full_name', 190);
        if (array_key_exists('password', $data) && trim((string)$data['password']) !== '') {
            respond(['ok' => false, 'error' => 'Admins cannot set initial passwords. Send a setup invite instead.'], 400);
        }
        $role = $data['global_role'] ?? 'volunteer';
        if (!in_array($role, $allowedRoles, true)) {
            respond(['ok' => false, 'error' => 'Invalid global_role'], 400);
        }
        $status = $data['status'] ?? 'active';
        if (!in_array($status, ['active', 'suspended'], true)) {
            respond(['ok' => false, 'error' => 'Invalid status'], 400);
        }
        $sendInvite = !array_key_exists('send_invite', $data) || filter_var($data['send_invite'], FILTER_VALIDATE_BOOLEAN);
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'INSERT INTO users (email, password_hash, full_name, global_role, status, force_password_reset, password_setup_required)
                 VALUES (?, NULL, ?, ?, ?, 1, 1)'
            );
            $stmt->execute([$email, $fullName, $role, $status]);
            $userId = (int)$pdo->lastInsertId();
            $token = auth_create_password_token($userId, 'password_setup', (int)$user['id']);
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getCode() === '23000') {
                respond(['ok' => false, 'error' => 'Email already exists'], 409);
            }
            throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $emailSent = false;
        if ($sendInvite && $status === 'active') {
            $emailSent = auth_send_password_token_email(
                ['email' => $email, 'full_name' => $fullName],
                'password_setup',
                $token['token'],
                $token['expires_at']
            );
        }
        log_activity($user['id'], 'user', $userId, 'created', 'User created with password setup required', ['invite_email_attempted' => $sendInvite && $status === 'active', 'invite_email_sent' => $emailSent]);
        respond(['ok' => true, 'data' => ['id' => $userId, 'password_setup_required' => true, 'invite_email_sent' => $emailSent]]);
    }

    if ($method === 'PUT' && $id) {
        $data = read_json();
        $userId = (int)$id;
        $status = $data['status'] ?? null;
        $role = $data['global_role'] ?? null;
        $fullName = array_key_exists('full_name', $data) ? require_non_empty($data['full_name'], 'full_name', 190) : null;
        $email = array_key_exists('email', $data) ? validate_email_address($data['email'], 'email') : null;
        if (!$status && !$role && $fullName === null && $email === null) {
            respond(['ok' => false, 'error' => 'At least one editable field is required'], 400);
        }
        if ($status && !in_array($status, ['active', 'suspended', 'deleted'], true)) {
            respond(['ok' => false, 'error' => 'Invalid status'], 400);
        }
        if ($role && !in_array($role, $allowedRoles, true)) {
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
        if ($fullName !== null) {
            $fields[] = 'full_name = ?';
            $values[] = $fullName;
        }
        if ($email !== null) {
            $fields[] = 'email = ?';
            $values[] = $email;
        }
        $exists = db()->prepare('SELECT id FROM users WHERE id = ?');
        $exists->execute([$userId]);
        if (!$exists->fetch()) {
            respond(['ok' => false, 'error' => 'User not found'], 404);
        }
        $values[] = $userId;
        $stmt = db()->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?');
        try {
            $stmt->execute($values);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                respond(['ok' => false, 'error' => 'Email already exists'], 409);
            }
            throw $e;
        }
        log_activity($user['id'], 'user', $userId, 'updated', 'User updated');
        respond(['ok' => true]);
    }

    if ($method === 'DELETE' && $id) {
        $userId = (int)$id;
        $stmt = db()->prepare('UPDATE users SET status = "deleted" WHERE id = ?');
        $stmt->execute([$userId]);
        if ($stmt->rowCount() === 0) {
            respond(['ok' => false, 'error' => 'User not found'], 404);
        }
        log_activity($user['id'], 'user', $userId, 'deleted', 'User soft-deleted');
        respond(['ok' => true]);
    }

    respond(['ok' => false, 'error' => 'Not Found'], 404);
}

function handle_user_force_password_reset(string $method, int $userId, array $actor): void {
    if ($method !== 'POST') {
        respond(['ok' => false, 'error' => 'Not Found'], 404);
    }
    require_permission('admin.manage_users', null, $actor);
    $data = read_json();
    $sendEmail = filter_var($data['send_email'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND status <> "deleted"');
    $stmt->execute([$userId]);
    $target = $stmt->fetch();
    if (!$target) {
        respond(['ok' => false, 'error' => 'User not found'], 404);
    }

    $update = db()->prepare(
        'UPDATE users
         SET force_password_reset = 1,
             password_setup_required = IF(password_hash IS NULL OR password_hash = "", 1, password_setup_required),
             password_reset_forced_at = UTC_TIMESTAMP(),
             password_reset_forced_by = ?
         WHERE id = ?'
    );
    $update->execute([(int)$actor['id'], $userId]);

    $emailSent = false;
    if ($sendEmail && ($target['status'] ?? '') === 'active') {
        $token = auth_create_password_token($userId, 'password_setup', (int)$actor['id']);
        $emailSent = auth_send_password_token_email($target, 'password_setup', $token['token'], $token['expires_at']);
    }

    log_activity($actor['id'], 'user', $userId, 'password_reset_forced', 'Admin forced password reset', ['email_requested' => $sendEmail, 'email_sent' => $emailSent]);
    respond(['ok' => true, 'data' => ['force_password_reset' => true, 'email_sent' => $emailSent]]);
}

function handle_user_role_assignments(string $method, int $userId, array $segments, array $actor): void {
    require_permission('admin.assign_roles', null, $actor);
    if (!rbac_tables_ready()) {
        respond(['ok' => false, 'error' => 'RBAC tables are not available. Run migrations.'], 500);
    }

    $assignmentId = isset($segments[3]) ? (int)$segments[3] : null;
    $userCheck = db()->prepare('SELECT id FROM users WHERE id = ?');
    $userCheck->execute([$userId]);
    if (!$userCheck->fetch()) {
        respond(['ok' => false, 'error' => 'User not found'], 404);
    }

    if ($method === 'GET' && !$assignmentId) {
        $stmt = db()->prepare(
            'SELECT ur.*, r.code, r.name, r.scope, e.name AS entity_name
             FROM rbac_user_roles ur
             JOIN rbac_roles r ON r.id = ur.role_id
             LEFT JOIN entities e ON e.id = ur.entity_id
             WHERE ur.user_id = ?
             ORDER BY r.scope, r.name, e.name'
        );
        $stmt->execute([$userId]);
        respond(['ok' => true, 'data' => $stmt->fetchAll()]);
    }

    if ($method === 'POST' && !$assignmentId) {
        $data = read_json();
        $roleId = (int)($data['role_id'] ?? 0);
        if ($roleId <= 0 && !empty($data['role_code'])) {
            $roleStmt = db()->prepare('SELECT id FROM rbac_roles WHERE code = ?');
            $roleStmt->execute([$data['role_code']]);
            $roleId = (int)$roleStmt->fetchColumn();
        }
        if ($roleId <= 0) {
            respond(['ok' => false, 'error' => 'role_id or role_code required'], 400);
        }
        $role = db()->prepare('SELECT * FROM rbac_roles WHERE id = ?');
        $role->execute([$roleId]);
        $roleRow = $role->fetch();
        if (!$roleRow) {
            respond(['ok' => false, 'error' => 'Role not found'], 404);
        }
        $entityId = array_key_exists('entity_id', $data) && $data['entity_id'] !== null && $data['entity_id'] !== '' ? (int)$data['entity_id'] : null;
        if ($roleRow['scope'] === 'entity' && !$entityId) {
            respond(['ok' => false, 'error' => 'entity_id is required for entity roles'], 400);
        }
        if ($entityId) {
            $entityCheck = db()->prepare('SELECT id FROM entities WHERE id = ?');
            $entityCheck->execute([$entityId]);
            if (!$entityCheck->fetch()) {
                respond(['ok' => false, 'error' => 'Entity not found'], 404);
            }
        }
        $stmt = db()->prepare('INSERT INTO rbac_user_roles (user_id, role_id, entity_id, assigned_by) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $roleId, $entityId, $actor['id']]);
        $newId = (int)db()->lastInsertId();
        log_activity($actor['id'], 'user', $userId, 'role_assigned', 'RBAC role assigned', ['assignment_id' => $newId, 'role_id' => $roleId, 'entity_id' => $entityId]);
        respond(['ok' => true, 'data' => ['id' => $newId]]);
    }

    if ($method === 'DELETE' && $assignmentId) {
        $stmt = db()->prepare('DELETE FROM rbac_user_roles WHERE id = ? AND user_id = ?');
        $stmt->execute([$assignmentId, $userId]);
        if ($stmt->rowCount() === 0) {
            respond(['ok' => false, 'error' => 'Assignment not found'], 404);
        }
        log_activity($actor['id'], 'user', $userId, 'role_removed', 'RBAC role assignment removed', ['assignment_id' => $assignmentId]);
        respond(['ok' => true]);
    }

    respond(['ok' => false, 'error' => 'Not Found'], 404);
}
