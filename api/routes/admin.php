<?php
function handle_admin(string $method, array $segments): void {
    $action = $segments[1] ?? '';

    if ($action === 'setup' && $method === 'POST') {
        handle_setup();
    }

    if ($action === 'summary' && $method === 'GET') {
        require_permission('admin.manage_users');
        $missingStmt = db()->prepare("SELECT COUNT(*) as total FROM endeavours WHERE status IN ('ops_plan_pending_board_approval', 'mou_pending_board_approval', 'pre_financial_pending_board_approval', 'post_financial_pending_board_approval')");
        $missingStmt->execute();
        $missingDocs = $missingStmt->fetch();
        $unpaidStmt = db()->prepare('SELECT COUNT(*) as total FROM payments WHERE paid_flag = 0');
        $unpaidStmt->execute();
        $unpaid = $unpaidStmt->fetch();
        $consentStmt = db()->prepare("SELECT COUNT(*) as total FROM consents WHERE status = 'pending'");
        $consentStmt->execute();
        $consents = $consentStmt->fetch();
        respond(['ok' => true, 'data' => ['missing_docs' => (int)$missingDocs['total'], 'unpaid' => (int)$unpaid['total'], 'consents_pending' => (int)$consents['total']]]);
    }

    if ($action === 'entities') {
        require_permission('admin.manage_entities');
        require_once __DIR__ . '/entities.php';
        handle_entities($method, array_merge(['entities'], array_slice($segments, 2)));
        return;
    }

    if ($action === 'users') {
        require_permission('admin.manage_users');
        require_once __DIR__ . '/users.php';
        handle_users($method, array_merge(['users'], array_slice($segments, 2)));
        return;
    }

    if ($action === 'members') {
        require_permission('admin.assign_roles');
        require_once __DIR__ . '/members.php';
        handle_members($method, array_merge(['members'], array_slice($segments, 2)));
        return;
    }

    if ($action === 'roles') {
        handle_admin_roles($method, array_merge(['roles'], array_slice($segments, 2)));
        return;
    }

    if ($action === 'connect-entitlements') {
        handle_admin_connect_entitlements($method, array_merge(['connect-entitlements'], array_slice($segments, 2)));
        return;
    }

    respond(['ok' => false, 'error' => 'Not Found'], 404);
}

function handle_admin_connect_entitlements(string $method, array $segments): void {
    $user = require_permission('admin.manage_connect');
    $id = $segments[1] ?? null;

    if ($id === 'test-resolve' && $method === 'POST') {
        $data = read_json();
        $email = strtolower(trim((string)($data['email'] ?? '')));
        $result = connect_resolve_google_payload([
            'email' => $email,
            'email_verified' => true,
            'google_sub' => '',
            'name' => $data['name'] ?? '',
            'picture' => $data['picture'] ?? '',
        ], false);
        respond(['ok' => true, 'data' => ['status' => $result['status'], 'response' => $result['payload']]]);
    }

    if ($method === 'GET' && !$id) {
        $query = strtolower(trim((string)($_GET['q'] ?? '')));
        $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 100;
        $sql = connect_select_identity_sql();
        $params = [];
        if ($query !== '') {
            $sql .= ' WHERE LOWER(cgi.email) LIKE ? OR LOWER(cgi.display_name) LIKE ?';
            $like = '%' . $query . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $sql .= ' ORDER BY cgi.updated_at DESC, cgi.email LIMIT ?';
        $stmt = db()->prepare($sql);
        $paramIndex = 1;
        foreach ($params as $param) {
            $stmt->bindValue($paramIndex++, $param);
        }
        $stmt->bindValue($paramIndex, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $entitlements = array_map('connect_identity_for_admin', $stmt->fetchAll());
        respond([
            'ok' => true,
            'data' => [
                'entitlements' => $entitlements,
                'membership_roles' => connect_membership_roles(),
                'developer_permissions' => connect_developer_permissions(),
            ],
        ]);
    }

    if ($method === 'GET' && $id) {
        $identity = connect_fetch_identity_by_id((int)$id);
        if (!$identity) {
            respond(['ok' => false, 'error' => 'Connect identity not found'], 404);
        }
        respond(['ok' => true, 'data' => ['entitlement' => connect_identity_for_admin($identity)]]);
    }

    if ($method === 'POST' && !$id) {
        $identity = connect_create_identity(read_json(), $user);
        respond(['ok' => true, 'data' => ['entitlement' => $identity]]);
    }

    if ($method === 'PUT' && $id) {
        $identity = connect_update_identity((int)$id, read_json(), $user);
        respond(['ok' => true, 'data' => ['entitlement' => $identity]]);
    }

    if ($method === 'DELETE' && $id) {
        connect_delete_identity((int)$id, $user);
        respond(['ok' => true]);
    }

    respond(['ok' => false, 'error' => 'Not Found'], 404);
}

function handle_admin_roles(string $method, array $segments): void {
    $user = require_permission('admin.manage_roles');
    $id = $segments[1] ?? null;

    if (!rbac_tables_ready()) {
        respond(['ok' => false, 'error' => 'RBAC tables are not available. Run migrations.'], 500);
    }

    if ($method === 'GET' && !$id) {
        $roles = db()->query('SELECT * FROM rbac_roles ORDER BY is_system DESC, name')->fetchAll();
        $permissions = db()->query('SELECT * FROM rbac_permissions ORDER BY code')->fetchAll();
        $roleIds = array_map(fn($role) => (int)$role['id'], $roles);
        $grants = [];
        if ($roleIds) {
            $in = implode(',', array_fill(0, count($roleIds), '?'));
            $stmt = db()->prepare("SELECT rp.role_id, p.code FROM rbac_role_permissions rp JOIN rbac_permissions p ON p.id = rp.permission_id WHERE rp.role_id IN ({$in}) ORDER BY p.code");
            $stmt->execute($roleIds);
            foreach ($stmt->fetchAll() as $row) {
                $grants[(int)$row['role_id']][] = $row['code'];
            }
        }
        foreach ($roles as &$role) {
            $role['permissions'] = $grants[(int)$role['id']] ?? [];
        }
        unset($role);
        respond(['ok' => true, 'data' => ['roles' => $roles, 'permissions' => $permissions]]);
    }

    if ($method === 'POST' && !$id) {
        $data = read_json();
        $code = preg_replace('/[^a-z0-9_.-]/', '_', strtolower(trim((string)($data['code'] ?? ''))));
        $name = require_non_empty($data['name'] ?? '', 'name', 190);
        $scope = $data['scope'] ?? 'entity';
        $permissionCodes = normalize_role_permission_codes($data['permissions'] ?? []);
        if ($code === '' || strlen($code) > 120) {
            respond(['ok' => false, 'error' => 'Invalid role code'], 400);
        }
        if (!in_array($scope, ['global', 'entity', 'both'], true)) {
            respond(['ok' => false, 'error' => 'Invalid role scope'], 400);
        }
        $pdo = db();
        $stmt = $pdo->prepare('INSERT INTO rbac_roles (code, name, scope, description, is_system) VALUES (?, ?, ?, ?, 0)');
        try {
            $pdo->beginTransaction();
            $stmt->execute([$code, $name, $scope, sanitize_text($data['description'] ?? '', 1000)]);
            $roleId = (int)$pdo->lastInsertId();
            sync_role_permissions($roleId, $permissionCodes);
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getCode() === '23000') {
                respond(['ok' => false, 'error' => 'Role already exists'], 409);
            }
            throw $e;
        }
        log_activity($user['id'], 'role', $roleId, 'created', 'RBAC role created');
        respond(['ok' => true, 'data' => ['id' => $roleId]]);
    }

    if ($method === 'PUT' && $id) {
        $roleId = (int)$id;
        $data = read_json();
        $role = fetch_rbac_role($roleId);
        if (!$role) {
            respond(['ok' => false, 'error' => 'Role not found'], 404);
        }
        $name = require_non_empty($data['name'] ?? $role['name'], 'name', 190);
        $scope = $data['scope'] ?? $role['scope'];
        if (!in_array($scope, ['global', 'entity', 'both'], true)) {
            respond(['ok' => false, 'error' => 'Invalid role scope'], 400);
        }
        $syncPermissions = array_key_exists('permissions', $data);
        $permissionCodes = $syncPermissions ? normalize_role_permission_codes($data['permissions']) : [];
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('UPDATE rbac_roles SET name = ?, scope = ?, description = ? WHERE id = ?');
            $stmt->execute([$name, $scope, sanitize_text($data['description'] ?? ($role['description'] ?? ''), 1000), $roleId]);
            if ($syncPermissions) {
                sync_role_permissions($roleId, $permissionCodes);
            }
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        log_activity($user['id'], 'role', $roleId, 'updated', 'RBAC role updated');
        respond(['ok' => true]);
    }

    respond(['ok' => false, 'error' => 'Not Found'], 404);
}

function fetch_rbac_role(int $roleId): ?array {
    $stmt = db()->prepare('SELECT * FROM rbac_roles WHERE id = ?');
    $stmt->execute([$roleId]);
    $role = $stmt->fetch();
    return $role ?: null;
}

function normalize_role_permission_codes($permissionCodes): array {
    if (!is_array($permissionCodes)) {
        respond(['ok' => false, 'error' => 'permissions must be an array'], 400);
    }
    $codes = array_values(array_unique(array_filter(array_map(static fn($code) => trim((string)$code), $permissionCodes))));
    if (!$codes) {
        return [];
    }
    $in = implode(',', array_fill(0, count($codes), '?'));
    $stmt = db()->prepare("SELECT code FROM rbac_permissions WHERE code IN ({$in})");
    $stmt->execute($codes);
    $found = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (count($found) !== count($codes)) {
        respond(['ok' => false, 'error' => 'Unknown permission supplied'], 400);
    }
    return $codes;
}

function sync_role_permissions(int $roleId, $permissionCodes): void {
    if (!is_array($permissionCodes)) {
        respond(['ok' => false, 'error' => 'permissions must be an array'], 400);
    }
    $codes = array_values(array_unique(array_filter(array_map(static fn($code) => trim((string)$code), $permissionCodes))));
    db()->prepare('DELETE FROM rbac_role_permissions WHERE role_id = ?')->execute([$roleId]);
    if (!$codes) {
        return;
    }
    $in = implode(',', array_fill(0, count($codes), '?'));
    $stmt = db()->prepare("SELECT id, code FROM rbac_permissions WHERE code IN ({$in})");
    $stmt->execute($codes);
    $permissionIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (count($permissionIds) !== count($codes)) {
        respond(['ok' => false, 'error' => 'Unknown permission supplied'], 400);
    }
    $insert = db()->prepare('INSERT INTO rbac_role_permissions (role_id, permission_id) VALUES (?, ?)');
    foreach ($permissionIds as $permissionId) {
        $insert->execute([$roleId, $permissionId]);
    }
}

function handle_setup(): void {
    $lockPath = dirname(__DIR__, 2) . '/config/setup.lock';
    if (file_exists($lockPath)) {
        respond(['ok' => false, 'error' => 'Setup already completed'], 403);
    }
    if (!rate_limit('setup', 5, 900)) {
        respond(['ok' => false, 'error' => 'Too many attempts'], 429);
    }
    $pdo = db();
    $data = read_json();
    $email = validate_email_address($data['email'] ?? '', 'email');
    $fullName = require_non_empty($data['full_name'] ?? '', 'full_name', 190);
    $password = $data['password'] ?? '';
    require_strong_password((string)$password, (string)($data['password_confirmation'] ?? ''), $email, $fullName);
    $appEnv = env_value('APP_ENV', 'production');
    if ($appEnv === 'production') {
        $setupToken = env_value('SETUP_TOKEN', '');
        if (!$setupToken) {
            respond(['ok' => false, 'error' => 'Setup disabled in production'], 403);
        }
        $providedToken = $data['setup_token'] ?? ($_SERVER['HTTP_X_SETUP_TOKEN'] ?? '');
        if (!$providedToken || !hash_equals($setupToken, $providedToken)) {
            respond(['ok' => false, 'error' => 'Invalid setup token'], 403);
        }
    }

    require_once __DIR__ . '/../lib/migrations.php';
    $migrationDir = dirname(__DIR__, 2) . '/sql/migrations';
    try {
        apply_migrations($pdo, $migrationDir);
    } catch (Throwable $e) {
        error_log('Setup migration failed: ' . $e->getMessage());
        respond(['ok' => false, 'error' => 'Schema migration failed'], 500);
    }

    $existingAdmin = $pdo->query("SELECT id FROM users WHERE global_role = 'admin' LIMIT 1")->fetch();
    if ($existingAdmin) {
        require_role(['admin']);
    }

    $pdo->beginTransaction();
    try {
        if (!$existingAdmin) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (public_id, email, password_hash, full_name, global_role) VALUES (?, ?, ?, ?, 'admin')");
            $stmt->execute([generate_public_id('usr'), $email, $hash, $fullName]);
            $adminId = (int)$pdo->lastInsertId();
            log_activity($adminId, 'user', $adminId, 'created', 'Initial admin created');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Setup failed: ' . $e->getMessage() . ' ' . $e->getTraceAsString());
        respond(['ok' => false, 'error' => 'Setup failed'], 500);
    }

    if (!is_dir(dirname($lockPath))) {
        mkdir(dirname($lockPath), 0775, true);
    }
    $lockHandle = fopen($lockPath, 'x');
    if ($lockHandle === false) {
        respond(['ok' => false, 'error' => 'Setup failed'], 500);
    }
    $lockWritten = fwrite($lockHandle, 'setup completed ' . date('c'));
    fclose($lockHandle);
    if ($lockWritten === false) {
        @unlink($lockPath);
        respond(['ok' => false, 'error' => 'Setup failed'], 500);
    }

    respond(['ok' => true, 'data' => ['message' => 'Setup completed']]);
}
