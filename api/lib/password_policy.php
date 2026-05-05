<?php
function password_policy_rules(): array {
    return [
        'min_length' => 12,
        'uppercase' => true,
        'lowercase' => true,
        'number' => true,
        'symbol' => true,
    ];
}

function password_policy_errors(string $password, string $email = '', string $fullName = ''): array {
    $errors = [];
    $length = function_exists('mb_strlen') ? mb_strlen($password) : strlen($password);
    if ($length < 12) {
        $errors[] = 'Use at least 12 characters.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Include at least one uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Include at least one lowercase letter.';
    }
    if (!preg_match('/\d/', $password)) {
        $errors[] = 'Include at least one number.';
    }
    if (!preg_match('/[^A-Za-z0-9\s]/', $password)) {
        $errors[] = 'Include at least one symbol.';
    }

    return array_values(array_unique($errors));
}

function require_strong_password(string $password, string $confirmation = '', string $email = '', string $fullName = ''): void {
    if ($password === '') {
        respond(['ok' => false, 'error' => 'Password is required'], 400);
    }
    if ($confirmation !== '' && !hash_equals($password, $confirmation)) {
        respond(['ok' => false, 'error' => 'Password confirmation does not match'], 400);
    }
    $errors = password_policy_errors($password, $email, $fullName);
    if ($errors) {
        respond([
            'ok' => false,
            'error' => 'Password does not meet security requirements',
            'meta' => ['requirements' => $errors],
        ], 400);
    }
}
