<?php
require_once __DIR__ . '/../api/lib/env.php';
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/mail.php';
require_once __DIR__ . '/../api/lib/activity.php';
require_once __DIR__ . '/../api/lib/http.php';

$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    header('Content-Type: application/json');
}

$token = env_value('CRON_TOKEN', '');
if (!$isCli && $token && (($_GET['token'] ?? '') !== $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$results = [
    'deadline_reminders' => run_deadline_reminders(),
    'consent_reminders' => run_consent_reminders(),
    'daily_digest' => run_daily_digest(),
];

$payload = ['ok' => true, 'data' => $results];
if ($isCli) {
    echo json_encode($payload, JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    echo json_encode($payload);
}

function run_deadline_reminders(): array {
    $pendingStatuses = [
        'pending_board_approval',
        'ops_plan_pending_board_approval',
        'mou_pending_board_approval',
        'pre_financial_pending_board_approval',
        'volunteer_posting_pending_board_approval',
        'post_financial_pending_board_approval'
    ];
    $placeholders = implode(',', array_fill(0, count($pendingStatuses), '?'));
    $stmt = db()->prepare("SELECT e.id, e.name, e.status, e.start_date, en.name AS entity_name FROM endeavours e JOIN entities en ON e.entity_id = en.id WHERE e.status IN ({$placeholders}) AND e.start_date IS NOT NULL AND e.start_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
    $stmt->execute($pendingStatuses);
    $endeavours = $stmt->fetchAll();
    $recipients = role_emails(['admin', 'board']);
    $sent = 0;
    foreach ($endeavours as $endeavour) {
        foreach ($recipients as $email) {
            if (!should_send_notification('deadline_reminder', 'endeavour', (int)$endeavour['id'], $email)) {
                continue;
            }
            $subject = 'Endeavour deadline reminder: ' . $endeavour['name'];
            $body = sprintf(
                '<p>Reminder: %s (%s) is awaiting action. Start date: %s</p>',
                htmlspecialchars($endeavour['name'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($endeavour['entity_name'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($endeavour['start_date'], ENT_QUOTES, 'UTF-8')
            );
            if (send_email($email, $subject, $body, true)) {
                mark_notification_sent('deadline_reminder', 'endeavour', (int)$endeavour['id'], $email);
                $sent++;
            }
        }
    }
    return ['processed' => count($endeavours), 'sent' => $sent];
}

function run_consent_reminders(): array {
    $stmt = db()->query('SELECT id, parent_email FROM consents WHERE status = "pending" AND created_at <= DATE_SUB(NOW(), INTERVAL 2 DAY)');
    $consents = $stmt->fetchAll();
    $sent = 0;
    foreach ($consents as $consent) {
        $email = $consent['parent_email'];
        if (!should_send_notification('consent_reminder', 'consent', (int)$consent['id'], $email)) {
            continue;
        }
        $subject = 'Reminder: Parent consent pending';
        $body = '<p>This is a reminder to complete the parent consent form for the Nixor endeavour.</p>';
        if (send_email($email, $subject, $body, true)) {
            mark_notification_sent('consent_reminder', 'consent', (int)$consent['id'], $email);
            $sent++;
        }
    }
    return ['processed' => count($consents), 'sent' => $sent];
}

function run_daily_digest(): array {
    $recipients = role_emails(['admin']);
    if (!$recipients) {
        return ['processed' => 0, 'sent' => 0];
    }
    $pending = db()->query('SELECT COUNT(*) as total FROM endeavours WHERE status NOT IN ("completed", "rejected")')->fetch();
    $consents = db()->query('SELECT COUNT(*) as total FROM consents WHERE status = "pending"')->fetch();
    $sent = 0;
    foreach ($recipients as $email) {
        if (!should_send_notification('daily_digest', 'system', 0, $email)) {
            continue;
        }
        $subject = 'Nixor Portal daily digest';
        $body = sprintf(
            '<p>Pending endeavours: %d</p><p>Pending consents: %d</p>',
            (int)$pending['total'],
            (int)$consents['total']
        );
        if (send_email($email, $subject, $body, true)) {
            mark_notification_sent('daily_digest', 'system', 0, $email);
            $sent++;
        }
    }
    return ['processed' => count($recipients), 'sent' => $sent];
}

function should_send_notification(string $type, string $entityType, int $entityId, string $recipient): bool {
    $stmt = db()->prepare('SELECT id FROM reminder_notifications WHERE notification_type = ? AND entity_type = ? AND entity_id = ? AND recipient = ? AND sent_on = CURDATE()');
    $stmt->execute([$type, $entityType, $entityId, $recipient]);
    return !$stmt->fetch();
}

function mark_notification_sent(string $type, string $entityType, int $entityId, string $recipient): void {
    $stmt = db()->prepare('INSERT INTO reminder_notifications (notification_type, entity_type, entity_id, recipient, sent_on) VALUES (?, ?, ?, ?, CURDATE())');
    try {
        $stmt->execute([$type, $entityType, $entityId, $recipient]);
    } catch (PDOException $e) {
        error_log('Failed to log reminder notification: ' . $e->getMessage());
    }
}

function role_emails(array $roles): array {
    if (!$roles) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    $stmt = db()->prepare("SELECT email FROM users WHERE status = 'active' AND global_role IN ({$placeholders})");
    $stmt->execute($roles);
    $emails = [];
    foreach ($stmt->fetchAll() as $row) {
        if (!empty($row['email'])) {
            $emails[] = $row['email'];
        }
    }
    return array_values(array_unique($emails));
}
