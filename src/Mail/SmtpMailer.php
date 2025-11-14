<?php

declare(strict_types=1);

namespace App\Mail;

use App\Lib\Env;
use RuntimeException;

final class SmtpMailer
{
    private string $host;
    private int $port;
    private string $user;
    private string $pass;
    private string $from;

    public function __construct()
    {
        $this->host = Env::get('SMTP_HOST', 'localhost');
        $this->port = (int) Env::get('SMTP_PORT', '25');
        $this->user = Env::get('SMTP_USER', '');
        $this->pass = Env::get('SMTP_PASS', '');
        $this->from = Env::get('SMTP_FROM', 'noreply@nixorcollege.edu.pk');
    }

    public function send(string $to, string $subject, string $html, string $text = ''): void
    {
        $boundary = bin2hex(random_bytes(12));
        $headers = [
            'From' => $this->from,
            'MIME-Version' => '1.0',
            'Content-Type' => 'multipart/alternative; boundary="' . $boundary . '"',
        ];
        $body = "--{$boundary}\r\n" .
            "Content-Type: text/plain; charset=utf-8\r\n\r\n" .
            ($text ?: strip_tags($html)) . "\r\n" .
            "--{$boundary}\r\n" .
            "Content-Type: text/html; charset=utf-8\r\n\r\n" .
            $html . "\r\n" .
            "--{$boundary}--";

        if (!mail($to, $subject, $body, $this->formatHeaders($headers))) {
            throw new RuntimeException('Unable to send email');
        }
    }

    private function formatHeaders(array $headers): string
    {
        $lines = [];
        foreach ($headers as $key => $value) {
            $lines[] = sprintf('%s: %s', $key, $value);
        }
        return implode("\r\n", $lines);
    }
}
