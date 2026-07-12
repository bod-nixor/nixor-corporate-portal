<?php
const MAIL_PURPOSE_AUTOMATED = 'automated';
const MAIL_PURPOSE_TRANSACTIONAL = 'transactional';

function mail_log_path(): string {
    $logBase = env_value('LOG_PATH', dirname(__DIR__, 2) . '/logs');
    if (!is_dir($logBase)) {
        mkdir($logBase, 0775, true);
    }
    return rtrim($logBase, '/') . '/mail.log';
}

function fake_mail_path(): string {
    $logBase = env_value('LOG_PATH', dirname(__DIR__, 2) . '/logs');
    if (!is_dir($logBase)) {
        mkdir($logBase, 0775, true);
    }
    return rtrim($logBase, '/') . '/fake-mail.jsonl';
}

function mail_log_event(string $event, array $context = []): void {
    $safe = [];
    foreach ($context as $key => $value) {
        if (is_scalar($value) || $value === null) {
            $safe[$key] = $value;
        }
    }
    $suffix = $safe ? ' ' . json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : '';
    file_put_contents(mail_log_path(), '[' . date('c') . '] ' . $event . $suffix . "\n", FILE_APPEND);
}

function mail_recipient_hash(string $email): string {
    $email = strtolower(trim($email));
    return $email === '' ? '' : substr(hash('sha256', $email), 0, 16);
}

function smtp_configured(): bool {
    return (bool)env_value('SMTP_HOST') && (bool)env_value('SMTP_PORT') && (bool)env_value('SMTP_USER');
}

function mail_transport(): string {
    $transport = strtolower(trim((string)env_value('MAIL_TRANSPORT', 'smtp')));
    return in_array($transport, ['smtp', 'fake'], true) ? $transport : 'smtp';
}

function automated_emails_enabled(): bool {
    $appEnv = strtolower(trim((string)env_value('APP_ENV', 'production')));
    if ($appEnv === 'production') {
        return false;
    }
    return filter_var(env_value('AUTOMATED_EMAILS_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
}

function mail_purpose(string $purpose): string {
    return $purpose === MAIL_PURPOSE_TRANSACTIONAL ? MAIL_PURPOSE_TRANSACTIONAL : MAIL_PURPOSE_AUTOMATED;
}

function send_transactional_email(string $to, string $subject, string $body, bool $isHtml = true, ?string $replyTo = null): bool {
    return send_email($to, $subject, $body, $isHtml, $replyTo, MAIL_PURPOSE_TRANSACTIONAL);
}

function send_automated_email(string $to, string $subject, string $body, bool $isHtml = true, ?string $replyTo = null): bool {
    return send_email($to, $subject, $body, $isHtml, $replyTo, MAIL_PURPOSE_AUTOMATED);
}

function fake_mail_record(string $to, string $subject, bool $isHtml, ?string $replyTo, string $purpose): void {
    $record = [
        'timestamp' => date('c'),
        'purpose' => $purpose,
        'recipient_hash' => mail_recipient_hash($to),
        'subject' => $subject,
        'is_html' => $isHtml,
        'reply_to_present' => $replyTo !== null && trim($replyTo) !== '',
    ];
    file_put_contents(fake_mail_path(), json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", FILE_APPEND);
}

function send_email(string $to, string $subject, string $body, bool $isHtml = true, ?string $replyTo = null, string $purpose = MAIL_PURPOSE_AUTOMATED): bool {
    $purpose = mail_purpose($purpose);

    $to = trim($to);
    if ($to === '') {
        mail_log_event('send_email rejected: empty recipient', ['purpose' => $purpose]);
        return false;
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        mail_log_event('send_email rejected: invalid recipient', [
            'purpose' => $purpose,
            'recipient_hash' => mail_recipient_hash($to),
        ]);
        return false;
    }

    if ($purpose !== MAIL_PURPOSE_TRANSACTIONAL && !automated_emails_enabled()) {
        mail_log_event('Automated email blocked; no email sent.', [
            'purpose' => $purpose,
            'recipient_hash' => mail_recipient_hash($to),
        ]);
        return false;
    }

    if (mail_transport() === 'fake') {
        fake_mail_record($to, $subject, $isHtml, $replyTo, $purpose);
        mail_log_event('send_email recorded by fake transport', [
            'purpose' => $purpose,
            'recipient_hash' => mail_recipient_hash($to),
        ]);
        return true;
    }

    if (!smtp_configured()) {
        mail_log_event('send_email skipped: SMTP not configured', [
            'purpose' => $purpose,
            'recipient_hash' => mail_recipient_hash($to),
        ]);
        return false;
    }

    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        mail_log_event('send_email skipped: PHPMailer autoload missing', [
            'purpose' => $purpose,
            'recipient_hash' => mail_recipient_hash($to),
        ]);
        error_log('PHPMailer missing; cannot send mail.');
        return false;
    }
    require_once $autoload;

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Timeout = 10;
        $mail->SMTPKeepAlive = false;
        $mail->Host = env_value('SMTP_HOST');
        $mail->Port = (int)env_value('SMTP_PORT', 587);
        $mail->SMTPAuth = true;
        $mail->Username = env_value('SMTP_USER');
        $mail->Password = env_value('SMTP_PASS');
        $secure = env_value('SMTP_SECURE', 'tls');
        if (!in_array($secure, ['tls', 'ssl', ''], true)) {
            error_log("Invalid SMTP_SECURE value: {$secure}, defaulting to tls");
            $secure = 'tls';
        }
        $mail->SMTPSecure = $secure;
        $from = env_value('SMTP_FROM', env_value('SMTP_USER'));
        $mail->setFrom($from, env_value('SMTP_FROM_NAME', 'Nixor Portal'));
        if ($replyTo) {
            $mail->addReplyTo($replyTo);
        }
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->isHTML($isHtml);
        
        mail_log_event('SMTP send attempt', [
            'purpose' => $purpose,
            'recipient_hash' => mail_recipient_hash($to),
            'host_configured' => env_value('SMTP_HOST') ? true : false,
            'port' => env_value('SMTP_PORT'),
            'secure' => env_value('SMTP_SECURE'),
        ]);
        
        if (in_array(env_value('SMTP_HOST'), ['localhost', '127.0.0.1', '::1'], true)) {
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ];
        }
        
        $mail->send();
        
        mail_log_event('SMTP send success', [
            'purpose' => $purpose,
            'recipient_hash' => mail_recipient_hash($to),
        ]);
        
        return true;
    } catch (Throwable $e) {
        error_log('Mail send failed.');
        mail_log_event('Mail send failed', [
            'purpose' => $purpose,
            'recipient_hash' => mail_recipient_hash($to),
            'error_class' => get_class($e),
        ]);
        return false;
    }
}
