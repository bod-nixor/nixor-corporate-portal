<?php
function handle_admin(string $method, array $segments): void {
    $action = $segments[1] ?? '';

    if ($action === 'setup' && $method === 'POST') {
        handle_setup();
    }

    if ($action === 'summary' && $method === 'GET') {
        require_role(['admin']);
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

    respond(['ok' => false, 'error' => 'Not Found'], 404);
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
    if (strlen($password) < 12) {
        respond(['ok' => false, 'error' => 'Password must be at least 12 characters'], 400);
    }
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
            $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, full_name, global_role) VALUES (?, ?, ?, 'admin')");
            $stmt->execute([$email, $hash, $fullName]);
            $adminId = (int)$pdo->lastInsertId();
            log_activity($adminId, 'user', $adminId, 'created', 'Initial admin created');
        }

        if (!is_dir(dirname($lockPath))) {
            mkdir(dirname($lockPath), 0775, true);
        }
        if (file_put_contents($lockPath, 'setup completed ' . date('c')) === false) {
            throw new RuntimeException('Failed to create setup lock file');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        @unlink($lockPath);
        error_log('Setup failed: ' . $e->getMessage() . ' ' . $e->getTraceAsString());
        respond(['ok' => false, 'error' => 'Setup failed'], 500);
    }

    respond(['ok' => true, 'data' => ['message' => 'Setup completed']]);
}
