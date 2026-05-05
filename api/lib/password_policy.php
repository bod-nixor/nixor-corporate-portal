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

    $passwordIdentity = password_policy_identity_normalize($password);
    $identityTokens = [];
    $emailLocal = (string)strstr($email, '@', true);
    $emailLocal = password_policy_identity_normalize($emailLocal);
    if ((function_exists('mb_strlen') ? mb_strlen($emailLocal) : strlen($emailLocal)) >= 3) {
        $identityTokens[] = $emailLocal;
    }
    $nameParts = preg_split('/\s+/', password_policy_lower($fullName));
    foreach ($nameParts ?: [] as $part) {
        $normalized = password_policy_identity_normalize($part);
        if ((function_exists('mb_strlen') ? mb_strlen($normalized) : strlen($normalized)) >= 3) {
            $identityTokens[] = $normalized;
        }
    }
    foreach (array_unique($identityTokens) as $identityToken) {
        if ($passwordIdentity === $identityToken || str_contains($passwordIdentity, $identityToken)) {
            $errors[] = 'Password must not contain your name or email.';
            break;
        }
    }

    return array_values(array_unique($errors));
}

function password_policy_lower(string $value): string {
    return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
}

function password_policy_identity_normalize(string $value): string {
    $value = password_policy_lower($value);
    return preg_replace('/[^a-z0-9]+/u', '', $value) ?? '';
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
