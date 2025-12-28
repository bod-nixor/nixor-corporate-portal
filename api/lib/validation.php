<?php
function sanitize_text(string $text, int $maxLength = 2000): string {
    $clean = strip_tags($text);
    $clean = preg_replace('/\s+/', ' ', $clean ?? '') ?? '';
    $clean = trim($clean);
    if (mb_strlen($clean) > $maxLength) {
        $clean = mb_substr($clean, 0, $maxLength);
    }
    return $clean;
}

function require_non_empty(string $value, string $field, int $maxLength = 255): string {
    $clean = sanitize_text($value, $maxLength);
    if ($clean === '') {
        respond(['ok' => false, 'error' => "$field is required"], 400);
    }
    return $clean;
}

function validate_email_address(string $email, string $field = 'email'): string {
    $email = trim($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(['ok' => false, 'error' => "Invalid {$field}"], 400);
    }
    return $email;
}
