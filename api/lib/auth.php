<?php
function current_user(): ?array {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND status = ?');
    $stmt->execute([$_SESSION['user_id'], 'active']);
    $user = $stmt->fetch();
    return $user ?: null;
}

function require_auth(): array {
    $user = current_user();
    if (!$user) {
        respond(['ok' => false, 'error' => 'Unauthorized'], 401);
    }
    return $user;
}

function require_role(array $roles): array {
    $user = require_auth();
    if (!in_array($user['global_role'], $roles, true)) {
        respond(['ok' => false, 'error' => 'Forbidden'], 403);
    }
    return $user;
}

function ensure_entity_access(int $entityId, array $roles = []): array {
    $user = require_auth();
    // Admin and Board roles can access all entities by design.
    if (in_array($user['global_role'], ['admin', 'board'], true)) {
        return $user;
    }
    $stmt = db()->prepare('SELECT * FROM entity_memberships WHERE entity_id = ? AND user_id = ?');
    $stmt->execute([$entityId, $user['id']]);
    $membership = $stmt->fetch();
    if (!$membership) {
        respond(['ok' => false, 'error' => 'Entity access denied'], 403);
    }
    if ($roles && !in_array($membership['department'], $roles, true)) {
        respond(['ok' => false, 'error' => 'Department access denied'], 403);
    }
    return $user;
}

function verify_password(string $password, string $hash): bool {
    if (str_starts_with($hash, '$2y$')) {
        return password_verify($password, $hash);
    }
    if (strlen($hash) === 64) {
        return hash('sha256', $password) === $hash;
    }
    return false;
}
