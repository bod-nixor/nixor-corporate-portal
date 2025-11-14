<?php

declare(strict_types=1);

namespace App\Services;

use App\Lib\DB;
use App\Mail\SmtpMailer;
use PDO;

final class MailService
{
    private PDO $pdo;
    private SmtpMailer $mailer;

    public function __construct()
    {
        $this->pdo = DB::pdo();
        $this->mailer = new SmtpMailer();
    }

    public function send(string $template, string $to, array $context): void
    {
        $html = $this->renderTemplate($template, $context);
        $subject = $this->subjectFor($template, $context);
        $this->mailer->send($to, $subject, $html);
        $stmt = $this->pdo->prepare('INSERT INTO EmailLog (id, toEmail, template, contextJson, sentAt) VALUES (:id, :toEmail, :template, :context, NOW(3))');
        $stmt->execute([
            ':id' => $this->uuid(),
            ':toEmail' => $to,
            ':template' => $template,
            ':context' => json_encode($context, JSON_THROW_ON_ERROR),
        ]);
    }

    public function preview(string $template, array $context): string
    {
        return $this->renderTemplate($template, $context);
    }

    private function renderTemplate(string $template, array $context): string
    {
        $path = __DIR__ . '/../Mail/templates/' . $template . '.php';
        if (!file_exists($path)) {
            throw new \RuntimeException('Template not found');
        }
        ob_start();
        include $path;
        return ob_get_clean() ?: '';
    }

    private function subjectFor(string $template, array $context): string
    {
        return match ($template) {
            'ParentRegistrationNotice' => 'New Endeavour Registration',
            'VolunteerConsentReminder' => 'Consent Form Reminder',
            default => 'Nixor Endeavours Update',
        };
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
