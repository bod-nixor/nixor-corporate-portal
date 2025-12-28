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
    $data = read_json();
    $email = validate_email_address($data['email'] ?? '', 'email');
    $fullName = require_non_empty($data['full_name'] ?? '', 'full_name', 190);
    $password = $data['password'] ?? '';
    if (strlen($password) < 12) {
        respond(['ok' => false, 'error' => 'Password must be at least 12 characters'], 400);
    }

    $schemaFile = dirname(__DIR__, 2) . '/sql/schema.sql';
    if (!file_exists($schemaFile)) {
        respond(['ok' => false, 'error' => 'Schema file missing'], 500);
    }

    $schemaSql = file_get_contents($schemaFile);
    if ($schemaSql === false) {
        respond(['ok' => false, 'error' => 'Failed to read schema'], 500);
    }

    // Note: Statement splitting assumes simple schema files without stored procedures/triggers.
    $statements = array_filter(array_map('trim', explode(';', $schemaSql)));
    foreach ($statements as $statement) {
        try {
            db()->exec($statement);
        } catch (PDOException $e) {
            // Allow CREATE DATABASE to fail (may already exist).
            if (stripos($statement, 'create database') !== false) {
                error_log('CREATE DATABASE step failed (may be expected): ' . $e->getMessage());
            } else {
                error_log('Schema step failed: ' . $e->getMessage());
                respond(['ok' => false, 'error' => 'Schema execution failed'], 500);
            }
        }
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $existing = $pdo->query("SELECT id FROM users WHERE global_role = 'admin' LIMIT 1")->fetch();
        if (!$existing) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, full_name, global_role) VALUES (?, ?, ?, 'admin')");
            $stmt->execute([$email, $hash, $fullName]);
            $adminId = (int)$pdo->lastInsertId();
            log_activity($adminId, 'user', $adminId, 'created', 'Initial admin created');
        }

        if (!is_dir(dirname($lockPath))) {
            mkdir(dirname($lockPath), 0775, true);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Setup failed: ' . $e->getMessage() . ' ' . $e->getTraceAsString());
        respond(['ok' => false, 'error' => 'Setup failed'], 500);
    }

    if (file_put_contents($lockPath, 'setup completed ' . date('c')) === false) {
        error_log('Failed to create setup lock file');
        respond(['ok' => false, 'error' => 'Setup failed'], 500);
    }

    respond(['ok' => true, 'data' => ['message' => 'Setup completed']]);
}
