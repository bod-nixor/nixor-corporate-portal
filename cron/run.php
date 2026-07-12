<?php
require_once __DIR__ . '/../api/lib/env.php';
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/mail.php';
require_once __DIR__ . '/../api/lib/activity.php';
require_once __DIR__ . '/../api/lib/http.php';
require_once __DIR__ . '/../api/lib/public_ids.php';
require_once __DIR__ . '/../api/lib/rbac.php';
require_once __DIR__ . '/../api/lib/connect.php';
require_once __DIR__ . '/../api/lib/notifications_dispatch.php';

$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    header('Content-Type: application/json');
}

$token = env_value('CRON_TOKEN', '');
if (!$isCli && $token && !hash_equals($token, (string)($_GET['token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$command = $isCli ? cron_parse_cli_args($_SERVER['argv'] ?? []) : ['command' => 'full'];
if (!empty($command['error'])) {
    $payload = ['ok' => false, 'error' => $command['error']];
    if ($isCli) {
        echo json_encode($payload, JSON_PRETTY_PRINT) . PHP_EOL;
        exit(2);
    }
    http_response_code(400);
    echo json_encode($payload);
    exit;
}

if ($command['command'] === 'push_notification') {
    dispatch_push_for_notification((int)$command['push_notification_id']);
    cron_emit(['ok' => true, 'data' => ['push_notification_id' => (int)$command['push_notification_id']]], $isCli);
    exit;
}

if ($command['command'] === 'connect_entitlement_outbox') {
    cron_emit(['ok' => true, 'data' => ['connect_entitlement_outbox' => dispatch_connect_entitlement_outbox()]], $isCli);
    exit;
}

$results = run_full_cron();

$payload = ['ok' => true, 'data' => $results];
cron_emit($payload, $isCli);

function run_deadline_reminders(): array {
    return disabled_automated_email_job_result('deadline_reminders');
}

function run_consent_reminders(): array {
    return disabled_automated_email_job_result('consent_reminders');
}

function run_daily_digest(): array {
    return disabled_automated_email_job_result('daily_digest');
}

function run_full_cron(): array {
    return [
        'deadline_reminders' => run_deadline_reminders(),
        'consent_reminders' => run_consent_reminders(),
        'daily_digest' => run_daily_digest(),
        'connect_entitlement_outbox' => dispatch_connect_entitlement_outbox(),
    ];
}

function disabled_automated_email_job_result(string $job): array {
    error_log('Automated email job disabled; no emails sent.');
    return [
        'processed' => 0,
        'sent' => 0,
        'disabled' => true,
        'message' => 'Automated email job disabled; no emails sent.',
        'job' => $job,
    ];
}

function cron_parse_cli_args(array $argv): array {
    $args = array_values(array_slice($argv, 1));
    if (!$args) {
        return ['command' => 'full'];
    }

    $command = null;
    $pushNotificationId = 0;
    foreach ($args as $arg) {
        $arg = trim((string)$arg);
        if ($arg === '') {
            continue;
        }

        if (in_array($arg, ['--connect_entitlement_outbox', 'connect_entitlement_outbox', 'connect_entitlement_outbox=1', 'connect_entitlement_outbox=true', '--connect-entitlement-outbox'], true)) {
            if ($command !== null && $command !== 'connect_entitlement_outbox') {
                return ['error' => 'Conflicting cron arguments supplied.'];
            }
            $command = 'connect_entitlement_outbox';
            continue;
        }

        foreach (['push_notification_id=', '--push_notification_id=', '--push-notification-id='] as $prefix) {
            if (str_starts_with($arg, $prefix)) {
                $value = trim(substr($arg, strlen($prefix)));
                if (!ctype_digit($value) || (int)$value <= 0) {
                    return ['error' => 'Malformed push_notification_id argument.'];
                }
                if ($command !== null && $command !== 'push_notification') {
                    return ['error' => 'Conflicting cron arguments supplied.'];
                }
                $command = 'push_notification';
                $pushNotificationId = (int)$value;
                continue 2;
            }
        }

        return ['error' => 'Unknown cron argument: ' . $arg];
    }

    if ($command === 'push_notification') {
        return ['command' => 'push_notification', 'push_notification_id' => $pushNotificationId];
    }
    if ($command === 'connect_entitlement_outbox') {
        return ['command' => 'connect_entitlement_outbox'];
    }
    return ['command' => 'full'];
}

function cron_emit(array $payload, bool $isCli): void {
    if ($isCli) {
        echo json_encode($payload, JSON_PRETTY_PRINT) . PHP_EOL;
        return;
    }
    echo json_encode($payload);
}
