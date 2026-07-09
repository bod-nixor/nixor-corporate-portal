<?php

const CONNECT_MATRIX_SERVER_NAME = 'connect.nixorcorporate.com';

function connect_membership_roles(): array {
    return ['member', 'moderator', 'admin', 'owner'];
}

function connect_developer_permissions(): array {
    return ['apps:create', 'apps:manage:own', 'apps:manage:all', 'tokens:dangerous-scopes'];
}

function connect_service_shared_secret(): string {
    return trim((string)env_value('NCP_API_SHARED_SECRET', ''));
}

function connect_service_bearer_token(): ?string {
    $header = auth_authorization_header();
    if ($header === '' || !preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        return null;
    }
    $token = trim((string)$matches[1]);
    return $token === '' ? null : $token;
}

function connect_request_has_valid_service_token(): bool {
    $secret = connect_service_shared_secret();
    $token = connect_service_bearer_token();
    return $secret !== '' && $token !== null && hash_equals($secret, $token);
}

function connect_log_resolution_denied(string $email, string $reason): void {
    $safeEmail = preg_replace('/[^A-Za-z0-9@._+\-]/', '_', strtolower(trim($email))) ?: 'unknown';
    $safeReason = preg_replace('/[^A-Za-z0-9_.:-]/', '_', $reason) ?: 'unknown';
    error_log("connect_google_resolve_denied email={$safeEmail} reason={$safeReason}");
}

function connect_json_array($value): array {
    if (is_array($value)) {
        return array_values($value);
    }
    if (!is_string($value) || trim($value) === '') {
        return [];
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? array_values($decoded) : [];
}

function connect_encode_string_array(array $values): string {
    $json = json_encode(array_values($values));
    return $json === false ? '[]' : $json;
}

function connect_truthy($value): bool {
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value)) {
        return $value === 1;
    }
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function connect_normalize_email($email): string {
    $email = strtolower(trim((string)$email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(['ok' => false, 'error' => 'Invalid email'], 400);
    }
    return $email;
}

function connect_public_identity_id(array $identity): string {
    $publicId = trim((string)($identity['resolved_user_public_id'] ?? $identity['user_public_id'] ?? ''));
    if ($publicId !== '') {
        return $publicId;
    }
    $userId = (int)($identity['resolved_user_id'] ?? $identity['user_id'] ?? 0);
    if ($userId > 0) {
        return (string)$userId;
    }
    return 'connect_identity_' . (int)$identity['id'];
}

function connect_display_name(array $identity, array $requestData = []): string {
    $displayName = trim((string)($identity['display_name'] ?? ''));
    if ($displayName !== '') {
        return $displayName;
    }
    $requestName = sanitize_text((string)($requestData['name'] ?? ''), 190);
    if ($requestName !== '') {
        return $requestName;
    }
    return explode('@', (string)$identity['email'])[0] ?: (string)$identity['email'];
}

function connect_sanitize_matrix_localpart(string $localpart): string {
    $source = $localpart;
    $localpart = strtolower($localpart);
    $localpart = preg_replace('/[^a-z0-9._=\/-]+/', '.', $localpart) ?? '';
    $localpart = preg_replace('/[.]{2,}/', '.', $localpart) ?? '';
    $localpart = trim($localpart, '.-_=/');
    if ($localpart === '') {
        $localpart = 'user-' . substr(hash('sha256', $source), 0, 10);
    }
    return substr($localpart, 0, 120);
}

function connect_matrix_user_id_for_email(string $email): string {
    $localpart = explode('@', strtolower(trim($email)))[0] ?? '';
    return '@' . connect_sanitize_matrix_localpart($localpart) . ':' . CONNECT_MATRIX_SERVER_NAME;
}

function connect_matrix_user_id_is_valid(string $matrixUserId): bool {
    return (bool)preg_match('/^@[a-z0-9._=\/-]+:' . preg_quote(CONNECT_MATRIX_SERVER_NAME, '/') . '$/', $matrixUserId);
}

function connect_effective_matrix_user_id(array $identity): string {
    $manual = strtolower(trim((string)($identity['matrix_user_id'] ?? '')));
    if ($manual !== '' && connect_matrix_user_id_is_valid($manual)) {
        return $manual;
    }
    return connect_matrix_user_id_for_email((string)$identity['email']);
}

function connect_string_list_from_input($value, string $field, ?array $allowedValues = null, ?string $requiredPrefix = null): array {
    if (is_string($value)) {
        $items = preg_split('/[\s,]+/', $value) ?: [];
    } elseif (is_array($value)) {
        $items = $value;
    } elseif ($value === null) {
        $items = [];
    } else {
        respond(['ok' => false, 'error' => "{$field} must be an array"], 400);
    }

    $normalized = [];
    foreach ($items as $item) {
        $item = trim((string)$item);
        if ($item === '') {
            continue;
        }
        if (mb_strlen($item) > 190) {
            respond(['ok' => false, 'error' => "Invalid {$field}"], 400);
        }
        if ($allowedValues !== null && !in_array($item, $allowedValues, true)) {
            respond(['ok' => false, 'error' => "Invalid {$field}"], 400);
        }
        if ($requiredPrefix !== null && !str_starts_with($item, $requiredPrefix)) {
            respond(['ok' => false, 'error' => "Invalid {$field}"], 400);
        }
        if ($requiredPrefix === 'app_' && !preg_match('/^app_[A-Za-z0-9_-]+$/', $item)) {
            respond(['ok' => false, 'error' => "Invalid {$field}"], 400);
        }
        $normalized[] = $item;
    }
    return array_values(array_unique($normalized));
}

function connect_normalize_memberships($memberships): array {
    if ($memberships === null) {
        return [];
    }
    if (!is_array($memberships)) {
        respond(['ok' => false, 'error' => 'memberships must be an array'], 400);
    }
    $roles = connect_membership_roles();
    $normalized = [];
    $seen = [];
    foreach ($memberships as $membership) {
        if (!is_array($membership)) {
            respond(['ok' => false, 'error' => 'Invalid membership'], 400);
        }
        $serverPublicId = trim((string)($membership['server_public_id'] ?? ''));
        $role = trim((string)($membership['role'] ?? 'member'));
        if ($serverPublicId === '' || !str_starts_with($serverPublicId, 'srv_') || !preg_match('/^srv_[A-Za-z0-9_-]+$/', $serverPublicId)) {
            respond(['ok' => false, 'error' => 'Invalid server_public_id'], 400);
        }
        if (!in_array($role, $roles, true)) {
            respond(['ok' => false, 'error' => 'Invalid membership role'], 400);
        }
        if (isset($seen[$serverPublicId])) {
            respond(['ok' => false, 'error' => 'Duplicate server_public_id'], 400);
        }
        $seen[$serverPublicId] = true;
        $normalized[] = [
            'server_public_id' => $serverPublicId,
            'role' => $role,
        ];
    }
    return $normalized;
}

function connect_load_memberships(int $identityId): array {
    $stmt = db()->prepare('SELECT server_public_id, role FROM connect_user_memberships WHERE identity_id = ? ORDER BY id');
    $stmt->execute([$identityId]);
    $roles = connect_membership_roles();
    $memberships = [];
    foreach ($stmt->fetchAll() as $membership) {
        $role = (string)($membership['role'] ?? 'member');
        $memberships[] = [
            'server_public_id' => (string)$membership['server_public_id'],
            'role' => in_array($role, $roles, true) ? $role : 'member',
        ];
    }
    return $memberships;
}

function connect_sync_memberships(int $identityId, array $memberships): void {
    db()->prepare('DELETE FROM connect_user_memberships WHERE identity_id = ?')->execute([$identityId]);
    if (!$memberships) {
        return;
    }
    $insert = db()->prepare('INSERT INTO connect_user_memberships (identity_id, server_public_id, role) VALUES (?, ?, ?)');
    foreach ($memberships as $membership) {
        $insert->execute([$identityId, $membership['server_public_id'], $membership['role']]);
    }
}

function connect_select_identity_sql(): string {
    return "SELECT cgi.*,
                   linked_user.id AS linked_user_id,
                   linked_user.public_id AS linked_user_public_id,
                   linked_user.status AS linked_user_status,
                   email_user.id AS email_user_id,
                   email_user.public_id AS email_user_public_id,
                   email_user.status AS email_user_status,
                   COALESCE(linked_user.id, email_user.id) AS resolved_user_id,
                   COALESCE(linked_user.public_id, email_user.public_id) AS resolved_user_public_id
            FROM connect_google_identities cgi
            LEFT JOIN users linked_user ON linked_user.id = cgi.user_id
            LEFT JOIN users email_user ON email_user.email = cgi.email";
}

function connect_fetch_identity_by_email(string $email): ?array {
    $stmt = db()->prepare(connect_select_identity_sql() . ' WHERE cgi.email = ? LIMIT 1');
    $stmt->execute([$email]);
    $identity = $stmt->fetch();
    return $identity ?: null;
}

function connect_fetch_identity_by_id(int $id): ?array {
    $stmt = db()->prepare(connect_select_identity_sql() . ' WHERE cgi.id = ? LIMIT 1');
    $stmt->execute([$id]);
    $identity = $stmt->fetch();
    return $identity ?: null;
}

function connect_identity_for_admin(array $identity): array {
    return [
        'id' => (int)$identity['id'],
        'user_id' => $identity['user_id'] !== null ? (int)$identity['user_id'] : null,
        'resolved_user_id' => $identity['resolved_user_id'] !== null ? (int)$identity['resolved_user_id'] : null,
        'resolved_user_public_id' => $identity['resolved_user_public_id'] ?? null,
        'google_sub' => $identity['google_sub'] ?? null,
        'email' => (string)$identity['email'],
        'display_name' => connect_display_name($identity),
        'matrix_user_id' => $identity['matrix_user_id'] ?? '',
        'effective_matrix_user_id' => connect_effective_matrix_user_id($identity),
        'is_allowed' => (bool)$identity['is_allowed'],
        'is_school_admin' => (bool)$identity['is_school_admin'],
        'is_approved_developer' => (bool)$identity['is_approved_developer'],
        'developer_permissions' => connect_json_array($identity['developer_permissions'] ?? '[]'),
        'owned_developer_app_ids' => connect_json_array($identity['owned_developer_app_ids'] ?? '[]'),
        'memberships' => connect_load_memberships((int)$identity['id']),
        'created_at' => $identity['created_at'] ?? null,
        'updated_at' => $identity['updated_at'] ?? null,
    ];
}

function connect_entitlement_user(array $identity, array $requestData = []): array {
    return [
        'id' => connect_public_identity_id($identity),
        'email' => (string)$identity['email'],
        'display_name' => connect_display_name($identity, $requestData),
        'matrix_user_id' => connect_effective_matrix_user_id($identity),
        'is_school_admin' => (bool)$identity['is_school_admin'],
        'is_approved_developer' => (bool)$identity['is_approved_developer'],
        'developer_permissions' => connect_json_array($identity['developer_permissions'] ?? '[]'),
        'owned_developer_app_ids' => connect_json_array($identity['owned_developer_app_ids'] ?? '[]'),
        'memberships' => connect_load_memberships((int)$identity['id']),
    ];
}

function connect_not_allowed(string $email, string $reason): array {
    connect_log_resolution_denied($email, $reason);
    return ['status' => 403, 'payload' => ['ok' => false, 'error' => 'not_allowed']];
}

function connect_resolve_google_payload(array $data, bool $bindGoogleSub = false): array {
    $email = strtolower(trim((string)($data['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return connect_not_allowed($email, 'invalid_email');
    }
    if (!connect_truthy($data['email_verified'] ?? false)) {
        return connect_not_allowed($email, 'email_unverified');
    }

    $identity = connect_fetch_identity_by_email($email);
    if (!$identity) {
        return connect_not_allowed($email, 'unknown_email');
    }
    if (!connect_truthy($identity['is_allowed'] ?? false)) {
        return connect_not_allowed($email, 'identity_not_allowed');
    }

    $linkedStatus = (string)($identity['linked_user_status'] ?? '');
    if ((int)($identity['user_id'] ?? 0) > 0 && $linkedStatus !== '' && $linkedStatus !== 'active') {
        return connect_not_allowed($email, 'linked_user_inactive');
    }

    $googleSub = trim((string)($data['google_sub'] ?? ''));
    $storedSub = trim((string)($identity['google_sub'] ?? ''));
    if ($storedSub !== '' && $googleSub !== '' && !hash_equals($storedSub, $googleSub)) {
        return connect_not_allowed($email, 'google_sub_mismatch');
    }
    if ($bindGoogleSub && $storedSub === '' && $googleSub !== '') {
        $stmt = db()->prepare('UPDATE connect_google_identities SET google_sub = ? WHERE id = ? AND google_sub IS NULL');
        try {
            $stmt->execute([$googleSub, (int)$identity['id']]);
            $identity['google_sub'] = $googleSub;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return connect_not_allowed($email, 'google_sub_already_bound');
            }
            throw $e;
        }
    }

    return ['status' => 200, 'payload' => ['ok' => true, 'user' => connect_entitlement_user($identity, $data)]];
}

function connect_payload_for_admin_save(array $data, ?array $existing = null): array {
    $email = connect_normalize_email($data['email'] ?? ($existing['email'] ?? ''));
    $displayName = sanitize_text((string)($data['display_name'] ?? ($existing['display_name'] ?? '')), 190);
    if ($displayName === '') {
        $displayName = explode('@', $email)[0] ?: $email;
    }
    $googleSub = sanitize_text((string)($data['google_sub'] ?? ($existing['google_sub'] ?? '')), 190);
    $matrixUserId = strtolower(trim((string)($data['matrix_user_id'] ?? ($existing['matrix_user_id'] ?? ''))));
    if ($matrixUserId !== '' && !connect_matrix_user_id_is_valid($matrixUserId)) {
        respond(['ok' => false, 'error' => 'Invalid matrix_user_id'], 400);
    }

    $userId = null;
    if (array_key_exists('user_id', $data) && $data['user_id'] !== null && $data['user_id'] !== '') {
        $userId = (int)$data['user_id'];
        if ($userId <= 0) {
            respond(['ok' => false, 'error' => 'Invalid user_id'], 400);
        }
        $stmt = db()->prepare('SELECT id FROM users WHERE id = ? AND status <> "deleted"');
        $stmt->execute([$userId]);
        if (!$stmt->fetch()) {
            respond(['ok' => false, 'error' => 'User not found'], 404);
        }
    } elseif ($existing && $existing['user_id'] !== null && !array_key_exists('user_id', $data)) {
        $userId = (int)$existing['user_id'];
    }

    return [
        'user_id' => $userId,
        'google_sub' => $googleSub !== '' ? $googleSub : null,
        'email' => $email,
        'display_name' => $displayName,
        'matrix_user_id' => $matrixUserId !== '' ? $matrixUserId : null,
        'is_allowed' => connect_truthy($data['is_allowed'] ?? ($existing['is_allowed'] ?? false)) ? 1 : 0,
        'is_school_admin' => connect_truthy($data['is_school_admin'] ?? ($existing['is_school_admin'] ?? false)) ? 1 : 0,
        'is_approved_developer' => connect_truthy($data['is_approved_developer'] ?? ($existing['is_approved_developer'] ?? false)) ? 1 : 0,
        'developer_permissions' => connect_string_list_from_input($data['developer_permissions'] ?? connect_json_array($existing['developer_permissions'] ?? '[]'), 'developer_permissions', connect_developer_permissions()),
        'owned_developer_app_ids' => connect_string_list_from_input($data['owned_developer_app_ids'] ?? connect_json_array($existing['owned_developer_app_ids'] ?? '[]'), 'owned_developer_app_ids', null, 'app_'),
        'memberships' => connect_normalize_memberships($data['memberships'] ?? ($existing ? connect_load_memberships((int)$existing['id']) : [])),
    ];
}

function connect_create_identity(array $data, array $actor): array {
    $payload = connect_payload_for_admin_save($data);
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'INSERT INTO connect_google_identities
             (user_id, google_sub, email, display_name, matrix_user_id, is_allowed, is_school_admin, is_approved_developer, developer_permissions, owned_developer_app_ids)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $payload['user_id'],
            $payload['google_sub'],
            $payload['email'],
            $payload['display_name'],
            $payload['matrix_user_id'],
            $payload['is_allowed'],
            $payload['is_school_admin'],
            $payload['is_approved_developer'],
            connect_encode_string_array($payload['developer_permissions']),
            connect_encode_string_array($payload['owned_developer_app_ids']),
        ]);
        $identityId = (int)$pdo->lastInsertId();
        connect_sync_memberships($identityId, $payload['memberships']);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e->getCode() === '23000') {
            respond(['ok' => false, 'error' => 'Connect identity already exists'], 409);
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    log_activity($actor['id'], 'connect_identity', $identityId, 'created', 'Connect entitlement created');
    $identity = connect_fetch_identity_by_id($identityId);
    return connect_identity_for_admin($identity);
}

function connect_update_identity(int $identityId, array $data, array $actor): array {
    $existing = connect_fetch_identity_by_id($identityId);
    if (!$existing) {
        respond(['ok' => false, 'error' => 'Connect identity not found'], 404);
    }
    $payload = connect_payload_for_admin_save($data, $existing);
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'UPDATE connect_google_identities
             SET user_id = ?, google_sub = ?, email = ?, display_name = ?, matrix_user_id = ?,
                 is_allowed = ?, is_school_admin = ?, is_approved_developer = ?,
                 developer_permissions = ?, owned_developer_app_ids = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $payload['user_id'],
            $payload['google_sub'],
            $payload['email'],
            $payload['display_name'],
            $payload['matrix_user_id'],
            $payload['is_allowed'],
            $payload['is_school_admin'],
            $payload['is_approved_developer'],
            connect_encode_string_array($payload['developer_permissions']),
            connect_encode_string_array($payload['owned_developer_app_ids']),
            $identityId,
        ]);
        connect_sync_memberships($identityId, $payload['memberships']);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e->getCode() === '23000') {
            respond(['ok' => false, 'error' => 'Connect identity already exists'], 409);
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    log_activity($actor['id'], 'connect_identity', $identityId, 'updated', 'Connect entitlement updated');
    return connect_identity_for_admin(connect_fetch_identity_by_id($identityId));
}

function connect_delete_identity(int $identityId, array $actor): void {
    $stmt = db()->prepare('DELETE FROM connect_google_identities WHERE id = ?');
    $stmt->execute([$identityId]);
    if ($stmt->rowCount() === 0) {
        respond(['ok' => false, 'error' => 'Connect identity not found'], 404);
    }
    log_activity($actor['id'], 'connect_identity', $identityId, 'deleted', 'Connect entitlement deleted');
}
