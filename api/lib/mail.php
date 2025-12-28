<?php
function mail_log_path(): string {
    $logBase = env_value('LOG_PATH', dirname(__DIR__, 2) . '/logs');
    if (!is_dir($logBase)) {
        mkdir($logBase, 0775, true);
    }
    return rtrim($logBase, '/') . '/mail.log';
}

function smtp_configured(): bool {
    return (bool)env_value('SMTP_HOST') && (bool)env_value('SMTP_PORT') && (bool)env_value('SMTP_USER');
}

function send_email(string $to, string $subject, string $body, bool $isHtml = true): bool {
    $to = trim($to);
    if ($to === '') {
        return false;
    }
    if (!smtp_configured()) {
        $logLine = sprintf(
            "[%s] SMTP not configured. To: %s | Subject: %s | Body: [omitted]\n",
            date('c'),
            $to,
            $subject
        );
        file_put_contents(mail_log_path(), $logLine, FILE_APPEND);
        return false;
    }

    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        error_log('PHPMailer missing; cannot send mail.');
        return false;
    }
    require_once $autoload;

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
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
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->isHTML($isHtml);
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('Mail send failed: ' . $e->getMessage());
        $logLine = sprintf(
            "[%s] Mail send failed. To: %s | Subject: %s | Error: %s\n",
            date('c'),
            $to,
            $subject,
            $e->getMessage()
        );
        file_put_contents(mail_log_path(), $logLine, FILE_APPEND);
        return false;
    }
}
