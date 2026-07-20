<?php

require_once __DIR__ . '/../api/lib/env.php';
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/migrations.php';
require_once __DIR__ . '/../api/lib/public_ids.php';
require_once __DIR__ . '/../api/lib/rbac.php';
require_once __DIR__ . '/../api/lib/connect.php';

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This readiness check is CLI-only.\n");
    exit(2);
}

$checks = [];
$addCheck = static function (string $name, bool $ok, array $details = []) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'details' => $details];
};

try {
    $pdo = db();
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $serverVersion = (string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    $addCheck('database_engine', $driver === 'mysql', [
        'driver' => $driver,
        'server_family' => stripos($serverVersion, 'mariadb') !== false ? 'MariaDB' : 'MySQL',
    ]);

    $requiredTables = [
        'connect_google_identities',
        'connect_resource_mappings',
        'connect_matrix_id_mappings',
        'connect_entitlement_versions',
        'connect_entitlement_reconciliation_state',
        'connect_entitlement_outbox',
    ];
    $missingTables = array_values(array_filter($requiredTables, static fn(string $table): bool => !connect_table_exists($table)));
    $addCheck('connect_schema', !$missingTables, ['missing_tables' => $missingTables]);

    $targetMigration = '202607190001_connect_identity_delivery_hardening.sql';
    $migrationPath = __DIR__ . '/../sql/migrations/' . $targetMigration;
    $applied = applied_migrations($pdo);
    $expectedChecksum = hash_file('sha256', $migrationPath);
    $recordedChecksum = $applied[$targetMigration] ?? null;
    $addCheck('target_migration', is_string($expectedChecksum) && $recordedChecksum === $expectedChecksum, [
        'filename' => $targetMigration,
        'applied' => $recordedChecksum !== null,
        'checksum_matches' => $recordedChecksum !== null && $recordedChecksum === $expectedChecksum,
    ]);

    if (!$missingTables) {
        $outboxCounts = [];
        foreach ($pdo->query('SELECT status, COUNT(*) AS total FROM connect_entitlement_outbox GROUP BY status')->fetchAll() as $row) {
            $outboxCounts[(string)$row['status']] = (int)$row['total'];
        }
        $staleSending = (int)$pdo->query(
            'SELECT COUNT(*) FROM connect_entitlement_outbox
             WHERE status = "sending"
               AND (claimed_at IS NULL OR claimed_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 10 MINUTE))'
        )->fetchColumn();
        $deadLetters = (int)($outboxCounts['dead_letter'] ?? 0);
        $addCheck('entitlement_outbox', $staleSending === 0 && $deadLetters === 0, [
            'counts' => $outboxCounts,
            'stale_sending' => $staleSending,
            'dead_lettered' => $deadLetters,
        ]);

        $duplicateExplicitMatrixIds = (int)$pdo->query(
            'SELECT COUNT(*) FROM (
                SELECT LOWER(matrix_user_id)
                FROM connect_google_identities
                WHERE matrix_user_id IS NOT NULL AND matrix_user_id <> ""
                GROUP BY LOWER(matrix_user_id)
                HAVING COUNT(*) > 1
             ) duplicates'
        )->fetchColumn();
        $addCheck('legacy_matrix_id_uniqueness', $duplicateExplicitMatrixIds === 0, [
            'duplicate_groups' => $duplicateExplicitMatrixIds,
        ]);
    }
} catch (Throwable $e) {
    $addCheck('database_connection', false, ['error' => 'database_unavailable']);
}

$addCheck('ncp_api_shared_secret', connect_service_secret_is_configured(), [
    'configured' => connect_service_shared_secret() !== '',
    'minimum_length' => 32,
]);
$webhookError = connect_entitlement_webhook_configuration_error();
$addCheck('entitlement_webhook', $webhookError === null, [
    'configured' => connect_entitlement_webhook_url() !== '',
    'error' => $webhookError,
]);
$automatedEmailsDisabled = !filter_var(env_value('AUTOMATED_EMAILS_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
$addCheck('automated_bulk_email', $automatedEmailsDisabled, ['disabled' => $automatedEmailsDisabled]);

$ok = !array_filter($checks, static fn(array $check): bool => !$check['ok']);
echo json_encode(['ok' => $ok, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
