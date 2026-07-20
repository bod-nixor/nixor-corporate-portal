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

function connect_secret_is_strong(string $secret): bool {
    $length = strlen($secret);
    return $length >= 32
        && $length <= 512
        && !preg_match('/[^\x21-\x7E]/', $secret);
}

function connect_service_secret_is_configured(): bool {
    return connect_secret_is_strong(connect_service_shared_secret());
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
    return connect_secret_is_strong($secret) && $token !== null && hash_equals($secret, $token);
}

function connect_log_resolution_denied(string $email, string $reason): void {
    $safeReason = preg_replace('/[^A-Za-z0-9_.:-]/', '_', $reason) ?: 'unknown';
    $emailHash = $email !== '' ? substr(hash('sha256', strtolower(trim($email))), 0, 16) : 'unknown';
    error_log("connect_google_resolve_denied email_hash={$emailHash} reason={$safeReason}");
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

function connect_sorted_string_list($value, ?array $allowedValues = null, ?string $requiredPrefix = null): array {
    $values = [];
    foreach (connect_json_array($value) as $item) {
        if (!is_string($item)) {
            continue;
        }
        $item = trim($item);
        if ($item === '' || strlen($item) > 190) {
            continue;
        }
        if ($allowedValues !== null && !in_array($item, $allowedValues, true)) {
            continue;
        }
        if ($requiredPrefix !== null && !str_starts_with($item, $requiredPrefix)) {
            continue;
        }
        $values[$item] = true;
    }
    $values = array_keys($values);
    sort($values, SORT_STRING);
    return $values;
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

function connect_table_exists(string $table): bool {
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = db()->prepare(
            'SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1'
        );
        $stmt->execute([$table]);
        $cache[$table] = (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        $cache[$table] = false;
    }

    return $cache[$table];
}

function connect_slug(string $value, string $fallback): string {
    $slug = strtolower(trim($value));
    $slug = preg_replace('/[^a-z0-9]+/', '.', $slug) ?? '';
    $slug = trim($slug, '.');
    return $slug !== '' ? substr($slug, 0, 80) : $fallback;
}

function connect_resource_mapping_key(
    string $sourceType,
    string $sourceId,
    string $fallbackKey,
    string $displayName,
    string $resourceType
): string {
    $fallbackKey = substr($fallbackKey, 0, 190);
    $displayName = substr($displayName, 0, 190);
    $sourceId = substr($sourceId, 0, 190);

    if (!connect_table_exists('connect_resource_mappings')) {
        throw new RuntimeException('Connect resource mappings are unavailable. Apply the Connect migrations.');
    }

    $existing = db()->prepare(
        'SELECT resource_key
         FROM connect_resource_mappings
         WHERE source_type = ? AND source_id = ? AND active = 1
         LIMIT 1'
    );
    $existing->execute([$sourceType, $sourceId]);
    $resourceKey = trim((string)$existing->fetchColumn());
    if ($resourceKey !== '') {
        return $resourceKey;
    }

    $candidate = $fallbackKey;
    try {
        $stmt = db()->prepare(
            'INSERT INTO connect_resource_mappings (source_type, source_id, resource_key, resource_type, display_name)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$sourceType, $sourceId, $candidate, $resourceType, $displayName]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? null) !== 1062) {
            throw $e;
        }

        $existing->execute([$sourceType, $sourceId]);
        $resourceKey = trim((string)$existing->fetchColumn());
        if ($resourceKey !== '') {
            return $resourceKey;
        }

        $suffix = '.' . substr(hash('sha256', $sourceType . ':' . $sourceId), 0, 12);
        $candidate = substr($fallbackKey, 0, 190 - strlen($suffix)) . $suffix;
        try {
            $stmt->execute([$sourceType, $sourceId, $candidate, $resourceType, $displayName]);
        } catch (PDOException $retryError) {
            if (($retryError->errorInfo[1] ?? null) !== 1062) {
                throw $retryError;
            }
            $existing->execute([$sourceType, $sourceId]);
            if (!trim((string)$existing->fetchColumn())) {
                throw $retryError;
            }
        }
    }

    $existing->execute([$sourceType, $sourceId]);
    $resourceKey = trim((string)$existing->fetchColumn());
    if ($resourceKey === '') {
        throw new RuntimeException('Connect resource mapping could not be persisted.');
    }
    return $resourceKey;
}

function connect_fixed_resource_key(string $key): string {
    if (!connect_table_exists('connect_resource_mappings')) {
        throw new RuntimeException('Connect resource mappings are unavailable. Apply the Connect migrations.');
    }

    $stmt = db()->prepare(
        'SELECT resource_key
         FROM connect_resource_mappings
         WHERE source_type = ? AND source_id = ? AND active = 1
         LIMIT 1'
    );
    $stmt->execute([$key, $key]);
    $mapped = trim((string)$stmt->fetchColumn());
    if ($mapped === '') {
        throw new RuntimeException("Missing fixed Connect resource mapping: {$key}");
    }
    return $mapped;
}

function connect_user_public_id_from_row(array $user): string {
    $publicId = trim((string)($user['public_id'] ?? ''));
    if ($publicId !== '') {
        return $publicId;
    }

    if (!empty($user['id']) && function_exists('public_id_for_row')) {
        $generated = public_id_for_row('users', (int)$user['id']);
        if ($generated) {
            return $generated;
        }
    }

    return (string)(int)($user['id'] ?? 0);
}

function connect_fetch_user_by_email(string $email): ?array {
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([strtolower(trim($email))]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function connect_fetch_user_by_public_or_internal_id(string $identifier): ?array {
    $identifier = trim($identifier);
    if ($identifier === '') {
        return null;
    }

    if (ctype_digit($identifier)) {
        $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$identifier]);
    } else {
        $stmt = db()->prepare('SELECT * FROM users WHERE public_id = ? LIMIT 1');
        $stmt->execute([$identifier]);
    }

    $user = $stmt->fetch();
    return $user ?: null;
}

function connect_fetch_identity_overlay_by_user_id(int $userId): ?array {
    $stmt = db()->prepare('SELECT * FROM connect_google_identities WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $identity = $stmt->fetch();
    return $identity ?: null;
}

function connect_fetch_identity_overlay_by_email(string $email): ?array {
    $stmt = db()->prepare('SELECT * FROM connect_google_identities WHERE email = ? LIMIT 1');
    $stmt->execute([strtolower(trim($email))]);
    $identity = $stmt->fetch();
    return $identity ?: null;
}

function connect_fetch_subject_by_email(string $email): ?array {
    $user = connect_fetch_user_by_email($email);
    $identity = $user ? connect_fetch_identity_overlay_by_user_id((int)$user['id']) : null;
    if (!$identity) {
        $identity = connect_fetch_identity_overlay_by_email($email);
    }

    if ($user && $identity && !empty($identity['user_id']) && (int)$identity['user_id'] !== (int)$user['id']) {
        connect_log_resolution_denied($email, 'identity_user_mismatch');
        return null;
    }

    if (!$user && $identity && !empty($identity['user_id'])) {
        $user = connect_fetch_user_by_public_or_internal_id((string)$identity['user_id']);
    }

    return ($user || $identity) ? ['user' => $user, 'identity' => $identity] : null;
}

function connect_fetch_subject_by_google_sub(string $googleSub): ?array {
    $stmt = db()->prepare('SELECT * FROM connect_google_identities WHERE google_sub = ? LIMIT 1');
    $stmt->execute([trim($googleSub)]);
    $identity = $stmt->fetch();
    if (!$identity) {
        return null;
    }

    $user = !empty($identity['user_id'])
        ? connect_fetch_user_by_public_or_internal_id((string)$identity['user_id'])
        : connect_fetch_user_by_email((string)$identity['email']);

    return ['user' => $user, 'identity' => $identity];
}

function connect_subject_key(array $subject): string {
    if (!empty($subject['user']['id'])) {
        $publicId = connect_user_public_id_from_row($subject['user']);
        if ($publicId === '' || $publicId === '0') {
            throw new RuntimeException('Unable to establish stable NCP user identity.');
        }
        return 'user:' . $publicId;
    }

    if (!empty($subject['identity']['id'])) {
        return 'identity:' . (int)$subject['identity']['id'];
    }

    throw new RuntimeException('Unable to establish Connect identity subject.');
}

function connect_legacy_matrix_id_is_unambiguous(array $subject, string $candidate): bool {
    $stmt = db()->query(
        'SELECT u.id AS user_id, NULL AS identity_id, u.email
         FROM users u
         UNION ALL
         SELECT COALESCE(cgi.user_id, email_user.id) AS user_id, cgi.id AS identity_id, cgi.email
         FROM connect_google_identities cgi
         LEFT JOIN users email_user ON email_user.email = cgi.email'
    );
    $matchingSubjects = [];
    foreach ($stmt->fetchAll() as $row) {
        if (connect_matrix_user_id_for_email((string)$row['email']) !== $candidate) {
            continue;
        }
        $key = !empty($row['user_id'])
            ? 'user-internal:' . (int)$row['user_id']
            : 'identity:' . (int)$row['identity_id'];
        $matchingSubjects[$key] = true;
    }

    return count($matchingSubjects) === 1;
}

function connect_collision_safe_matrix_user_id(array $subject): string {
    $email = connect_subject_email($subject);
    $base = connect_sanitize_matrix_localpart(explode('@', $email)[0] ?? 'user');
    $suffix = '-' . substr(hash('sha256', connect_subject_key($subject)), 0, 16);
    $base = substr($base, 0, 120 - strlen($suffix));
    return '@' . $base . $suffix . ':' . CONNECT_MATRIX_SERVER_NAME;
}

function connect_claim_matrix_user_id(array $subject): string {
    if (!connect_table_exists('connect_matrix_id_mappings')) {
        throw new RuntimeException('Connect Matrix identity mappings are unavailable. Apply the Connect migrations.');
    }

    $subjectKey = connect_subject_key($subject);
    $existing = db()->prepare('SELECT matrix_user_id FROM connect_matrix_id_mappings WHERE subject_key = ? LIMIT 1');
    $existing->execute([$subjectKey]);
    $mapped = strtolower(trim((string)$existing->fetchColumn()));
    if ($mapped !== '') {
        return $mapped;
    }

    $identity = $subject['identity'] ?? null;
    $manual = strtolower(trim((string)($identity['matrix_user_id'] ?? '')));
    $legacy = connect_matrix_user_id_for_email(connect_subject_email($subject));
    $candidates = [];
    if ($manual !== '' && connect_matrix_user_id_is_valid($manual)) {
        $candidates[] = ['value' => $manual, 'manual' => true];
    } elseif (connect_legacy_matrix_id_is_unambiguous($subject, $legacy)) {
        $candidates[] = ['value' => $legacy, 'manual' => false];
    }
    $candidates[] = ['value' => connect_collision_safe_matrix_user_id($subject), 'manual' => false];

    $userId = !empty($subject['user']['id']) ? (int)$subject['user']['id'] : null;
    $identityId = !empty($identity['id']) ? (int)$identity['id'] : null;
    $insert = db()->prepare(
        'INSERT INTO connect_matrix_id_mappings (subject_key, user_id, identity_id, matrix_user_id)
         VALUES (?, ?, ?, ?)'
    );

    foreach ($candidates as $candidate) {
        try {
            $insert->execute([$subjectKey, $userId, $identityId, $candidate['value']]);
            if ($identityId) {
                db()->prepare(
                    'UPDATE connect_google_identities
                     SET matrix_user_id = ?
                     WHERE id = ? AND (matrix_user_id IS NULL OR matrix_user_id = "")'
                )->execute([$candidate['value'], $identityId]);
            }
            return $candidate['value'];
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) !== 1062) {
                throw $e;
            }
            $existing->execute([$subjectKey]);
            $mapped = strtolower(trim((string)$existing->fetchColumn()));
            if ($mapped !== '') {
                return $mapped;
            }
            if ($candidate['manual']) {
                throw new RuntimeException('Configured Matrix user ID is already claimed by another NCP identity.');
            }
        }
    }

    throw new RuntimeException('Unable to claim a collision-safe Matrix user ID.');
}

function connect_fetch_subject_by_matrix_user_id(string $matrixUserId): ?array {
    $matrixUserId = strtolower(trim($matrixUserId));
    if (!connect_matrix_user_id_is_valid($matrixUserId)) {
        return null;
    }

    if (connect_table_exists('connect_matrix_id_mappings')) {
        $stmt = db()->prepare(
            'SELECT user_id, identity_id
             FROM connect_matrix_id_mappings
             WHERE matrix_user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$matrixUserId]);
        $mapping = $stmt->fetch();
        if ($mapping) {
            $identity = !empty($mapping['identity_id'])
                ? connect_fetch_identity_by_id((int)$mapping['identity_id'])
                : null;
            $user = !empty($mapping['user_id'])
                ? connect_fetch_user_by_public_or_internal_id((string)$mapping['user_id'])
                : ($identity ? connect_fetch_user_by_email((string)$identity['email']) : null);
            return ($user || $identity) ? ['user' => $user, 'identity' => $identity] : null;
        }
    }

    $stmt = db()->prepare('SELECT * FROM connect_google_identities WHERE LOWER(matrix_user_id) = ? LIMIT 1');
    $stmt->execute([$matrixUserId]);
    $identity = $stmt->fetch();
    if ($identity) {
        $user = !empty($identity['user_id'])
            ? connect_fetch_user_by_public_or_internal_id((string)$identity['user_id'])
            : connect_fetch_user_by_email((string)$identity['email']);
        return ['user' => $user, 'identity' => $identity];
    }

    return null;
}

function connect_fetch_subject_for_current_resolution(array $data): array {
    $subjects = [];
    $lookups = [
        'ncp_user_id' => function (string $value): ?array {
            if (!preg_match('/^[A-Za-z0-9_-]{1,190}$/', $value)) {
                throw new InvalidArgumentException('invalid_lookup');
            }
            if (preg_match('/^connect_identity_([1-9][0-9]*)$/', $value, $matches)) {
                $identity = connect_fetch_identity_by_id((int)$matches[1]);
                if (!$identity) {
                    return null;
                }
                $user = !empty($identity['resolved_user_id'])
                    ? connect_fetch_user_by_public_or_internal_id((string)$identity['resolved_user_id'])
                    : null;
                return ['user' => $user, 'identity' => $identity];
            }
            $user = connect_fetch_user_by_public_or_internal_id($value);
            return $user ? ['user' => $user, 'identity' => connect_fetch_identity_overlay_by_user_id((int)$user['id'])] : null;
        },
        'google_sub' => function (string $value): ?array {
            if (strlen($value) > 190 || preg_match('/[^\x21-\x7E]/', $value)) {
                throw new InvalidArgumentException('invalid_lookup');
            }
            return connect_fetch_subject_by_google_sub($value);
        },
        'matrix_user_id' => function (string $value): ?array {
            if (!connect_matrix_user_id_is_valid(strtolower($value))) {
                throw new InvalidArgumentException('invalid_lookup');
            }
            return connect_fetch_subject_by_matrix_user_id($value);
        },
        'email' => function (string $value): ?array {
            $email = strtolower($value);
            if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('invalid_lookup');
            }
            return connect_fetch_subject_by_email($email);
        },
    ];

    try {
        foreach ($lookups as $field => $resolver) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = trim((string)$data[$field]);
            if ($value === '') {
                return ['subject' => null, 'error' => 'invalid_lookup'];
            }
            $subject = $resolver($value);
            if (!$subject) {
                return ['subject' => null, 'error' => 'not_allowed'];
            }
            $subjects[] = $subject;
        }
    } catch (InvalidArgumentException $e) {
        return ['subject' => null, 'error' => 'invalid_lookup'];
    }

    if (!$subjects) {
        return ['subject' => null, 'error' => 'missing_lookup'];
    }

    $subjectKey = connect_subject_key($subjects[0]);
    foreach (array_slice($subjects, 1) as $subject) {
        if (!hash_equals($subjectKey, connect_subject_key($subject))) {
            return ['subject' => null, 'error' => 'identifier_mismatch'];
        }
    }

    return ['subject' => $subjects[0], 'error' => null];
}

function connect_subject_email(array $subject): string {
    $user = $subject['user'] ?? null;
    $identity = $subject['identity'] ?? null;
    return strtolower(trim((string)($user['email'] ?? $identity['email'] ?? '')));
}

function connect_subject_display_name(array $subject, array $requestData = []): string {
    $user = $subject['user'] ?? null;
    $identity = $subject['identity'] ?? null;
    $displayName = trim((string)($identity['display_name'] ?? ''));
    if ($displayName !== '') {
        return $displayName;
    }
    $fullName = trim((string)($user['full_name'] ?? ''));
    if ($fullName !== '') {
        return $fullName;
    }
    $requestName = sanitize_text((string)($requestData['name'] ?? ''), 190);
    if ($requestName !== '') {
        return $requestName;
    }
    $email = connect_subject_email($subject);
    return explode('@', $email)[0] ?: $email;
}

function connect_subject_matrix_user_id(array $subject): string {
    return connect_claim_matrix_user_id($subject);
}

function connect_subject_account_status(array $subject): string {
    $user = $subject['user'] ?? null;
    if (!$user) {
        return !empty($subject['identity']['is_allowed']) ? 'active' : 'inactive';
    }
    $status = (string)($user['status'] ?? '');
    return $status === 'active' ? 'active' : ($status === 'suspended' ? 'suspended' : 'inactive');
}

function connect_subject_is_allowed(array $subject): bool {
    $user = $subject['user'] ?? null;
    if ($user) {
        return connect_subject_account_status($subject) === 'active';
    }

    return !empty($subject['identity']['is_allowed']);
}

function connect_subject_is_school_admin(array $subject): bool {
    $role = (string)($subject['user']['global_role'] ?? '');
    return in_array($role, ['admin', 'board', 'ceo', 'student_affairs'], true)
        || !empty($subject['identity']['is_school_admin']);
}

function connect_subject_global_roles(array $subject): array {
    $roles = [];
    $role = trim((string)($subject['user']['global_role'] ?? ''));
    if ($role !== '') {
        $roles[] = $role;
    }
    if (connect_subject_is_school_admin($subject)) {
        $roles[] = 'school_admin';
    }
    if (!empty($subject['identity']['is_approved_developer'])) {
        $roles[] = 'approved_developer';
    }

    if (!empty($subject['user']['id']) && rbac_tables_ready()) {
        $stmt = db()->prepare(
            'SELECT DISTINCT r.code
             FROM rbac_user_roles ur
             JOIN rbac_roles r ON r.id = ur.role_id
             WHERE ur.user_id = ?
               AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
             ORDER BY r.code'
        );
        $stmt->execute([(int)$subject['user']['id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $code) {
            $roles[] = (string)$code;
        }
    }

    $roles = array_values(array_unique(array_filter($roles)));
    sort($roles, SORT_STRING);
    return $roles;
}

function connect_managed_role_rank(string $role): int {
    return ['member' => 0, 'moderator' => 1, 'manager' => 2, 'admin' => 3][$role] ?? 0;
}

function connect_add_managed_membership(array &$memberships, string $resourceType, string $resourceKey, string $role): void {
    $role = in_array($role, ['member', 'moderator', 'manager', 'admin'], true) ? $role : 'member';
    $mapKey = $resourceType . ':' . $resourceKey;
    if (!isset($memberships[$mapKey]) || connect_managed_role_rank($role) > connect_managed_role_rank($memberships[$mapKey]['role'])) {
        $memberships[$mapKey] = [
            'resource_type' => $resourceType,
            'resource_key' => $resourceKey,
            'role' => $role,
        ];
    }
}

function connect_membership_role_for_entity(string $role): string {
    return match ($role) {
        'manager' => 'manager',
        'executive' => 'moderator',
        default => 'member',
    };
}

function connect_entity_resource_key(array $entity): string {
    $slugSource = trim((string)($entity['name'] ?? ''));
    $fallback = 'entity.' . connect_slug($slugSource, 'entity-' . (int)$entity['id']);
    return connect_resource_mapping_key('entity', (string)(int)$entity['id'], $fallback, (string)$entity['name'], 'space');
}

function connect_department_resource_key(string $department): string {
    $slug = connect_slug($department, 'other');
    return connect_resource_mapping_key('department', $slug, 'department.' . $slug, ucfirst(str_replace('_', ' ', $slug)), 'channel');
}

function connect_project_resource_key(array $endeavour): string {
    $id = (int)$endeavour['id'];
    return connect_resource_mapping_key('project', (string)$id, 'project.' . $id, (string)$endeavour['name'], 'channel');
}

function connect_managed_memberships_for_subject(array $subject): array {
    $memberships = [];
    $user = $subject['user'] ?? null;

    connect_add_managed_membership($memberships, 'space', connect_fixed_resource_key('root'), 'member');
    connect_add_managed_membership($memberships, 'channel', connect_fixed_resource_key('announcements'), 'member');

    if (!$user || empty($user['id'])) {
        return array_values($memberships);
    }

    $userId = (int)$user['id'];
    $globalRole = (string)($user['global_role'] ?? '');
    if (in_array($globalRole, ['admin', 'board', 'ceo', 'student_affairs'], true)) {
        connect_add_managed_membership($memberships, 'channel', connect_fixed_resource_key('leadership'), 'manager');
    }
    if (in_array($globalRole, ['admin', 'board', 'ceo'], true)) {
        connect_add_managed_membership($memberships, 'channel', connect_fixed_resource_key('board'), 'manager');
    }

    $stmt = db()->prepare(
        'SELECT em.*, e.id AS entity_id, e.name AS entity_name, e.public_id AS entity_public_id
         FROM entity_memberships em
         JOIN entities e ON e.id = em.entity_id
         WHERE em.user_id = ?
           AND (em.start_date IS NULL OR em.start_date <= CURDATE())
           AND (em.end_date IS NULL OR em.end_date >= CURDATE())
         ORDER BY e.name, em.department'
    );
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $row) {
        $entity = ['id' => $row['entity_id'], 'name' => $row['entity_name'], 'public_id' => $row['entity_public_id']];
        $role = connect_membership_role_for_entity((string)$row['role']);
        connect_add_managed_membership($memberships, 'space', connect_entity_resource_key($entity), $role);
        connect_add_managed_membership($memberships, 'channel', connect_department_resource_key((string)$row['department']), $role);
    }

    if (connect_table_exists('entity_mob_assignments')) {
        $stmt = db()->prepare(
            'SELECT e.id, e.name, e.public_id
             FROM entity_mob_assignments ema
             JOIN entities e ON e.id = ema.entity_id
             WHERE ema.user_id = ?
             ORDER BY e.name'
        );
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $entity) {
            connect_add_managed_membership($memberships, 'space', connect_entity_resource_key($entity), 'manager');
            connect_add_managed_membership($memberships, 'channel', connect_fixed_resource_key('board'), 'moderator');
        }
    }

    if (rbac_tables_ready()) {
        $stmt = db()->prepare(
            'SELECT ur.entity_id, r.code, r.scope, e.name AS entity_name, e.public_id AS entity_public_id
             FROM rbac_user_roles ur
             JOIN rbac_roles r ON r.id = ur.role_id
             LEFT JOIN entities e ON e.id = ur.entity_id
             WHERE ur.user_id = ?
               AND (ur.expires_at IS NULL OR ur.expires_at > NOW())'
        );
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $role) {
            $code = strtolower((string)$role['code']);
            $managedRole = str_contains($code, 'admin') || str_contains($code, 'manager') || str_contains($code, 'executive') ? 'manager' : 'member';
            if (!empty($role['entity_id'])) {
                connect_add_managed_membership($memberships, 'space', connect_entity_resource_key([
                    'id' => $role['entity_id'],
                    'name' => $role['entity_name'] ?? ('Entity ' . $role['entity_id']),
                    'public_id' => $role['entity_public_id'] ?? null,
                ]), $managedRole);
            }
            if (in_array($code, ['admin', 'site_admin', 'board', 'ceo'], true)) {
                connect_add_managed_membership($memberships, 'channel', connect_fixed_resource_key('leadership'), 'manager');
            }
        }
    }

    $stmt = db()->prepare(
        'SELECT id, name
         FROM endeavours
         WHERE created_by = ?
           AND status NOT IN ("completed", "rejected")
         ORDER BY id'
    );
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $endeavour) {
        connect_add_managed_membership($memberships, 'channel', connect_project_resource_key($endeavour), 'manager');
    }

    if (connect_table_exists('volunteer_registrations')) {
        $stmt = db()->prepare(
            'SELECT e.id, e.name, vr.status
             FROM volunteer_registrations vr
             JOIN endeavours e ON e.id = vr.endeavour_id
             WHERE vr.user_id = ?
               AND vr.status <> "rejected"
               AND e.status NOT IN ("completed", "rejected")
             ORDER BY e.id'
        );
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $endeavour) {
            connect_add_managed_membership($memberships, 'channel', connect_project_resource_key($endeavour), 'member');
        }
    }

    $memberships = array_values($memberships);
    usort($memberships, static function (array $left, array $right): int {
        return [$left['resource_type'], $left['resource_key'], $left['role']]
            <=> [$right['resource_type'], $right['resource_key'], $right['role']];
    });
    return $memberships;
}

function connect_subject_student_id(array $subject): ?string {
    if (empty($subject['user']['id']) || !connect_table_exists('students')) {
        return null;
    }
    $stmt = db()->prepare('SELECT student_id FROM students WHERE user_id = ? LIMIT 1');
    $stmt->execute([(int)$subject['user']['id']]);
    $studentId = trim((string)$stmt->fetchColumn());
    return $studentId !== '' ? $studentId : null;
}

function connect_entitlement_state_for_subject(array $subject, array $requestData = []): array {
    $identity = $subject['identity'] ?? [];
    $managedMemberships = connect_managed_memberships_for_subject($subject);
    $accountStatus = connect_subject_account_status($subject);

    return [
        'id' => !empty($subject['user']) ? connect_user_public_id_from_row($subject['user']) : connect_public_identity_id($identity),
        'email' => connect_subject_email($subject),
        'display_name' => connect_subject_display_name($subject, $requestData),
        'student_id' => connect_subject_student_id($subject),
        'profile_image_url' => $identity['profile_image_url'] ?? null,
        'account_status' => $accountStatus,
        'matrix_user_id' => connect_subject_matrix_user_id($subject),
        'is_school_admin' => connect_subject_is_school_admin($subject),
        'is_approved_developer' => (bool)($identity['is_approved_developer'] ?? false),
        'developer_permissions' => connect_sorted_string_list($identity['developer_permissions'] ?? '[]', connect_developer_permissions()),
        'owned_developer_app_ids' => connect_sorted_string_list($identity['owned_developer_app_ids'] ?? '[]', null, 'app_'),
        'global_roles' => connect_subject_global_roles($subject),
        'managed_memberships' => $managedMemberships,
        'memberships' => !empty($identity['id']) ? connect_load_memberships((int)$identity['id']) : [],
    ];
}

function connect_entitlement_version(array $state): string {
    $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Unable to encode Connect entitlement state.');
    }
    return 'ncp_' . substr(hash('sha256', $json), 0, 32);
}

function connect_database_datetime_to_iso8601(string $value): string {
    try {
        $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    } catch (Throwable $e) {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}

function connect_record_entitlement_version(
    array $subject,
    array $state,
    string $version,
    bool $enqueueChange = false
): string {
    if (!connect_table_exists('connect_entitlement_versions')) {
        throw new RuntimeException('Connect entitlement version storage is unavailable. Apply the Connect migrations.');
    }

    $ncpUserId = (string)$state['id'];
    $userId = !empty($subject['user']['id']) ? (int)$subject['user']['id'] : null;
    $stateJson = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($stateJson === false) {
        throw new RuntimeException('Unable to encode Connect entitlement snapshot.');
    }

    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $select = $pdo->prepare(
            'SELECT entitlement_version, last_enqueued_version, changed_at
             FROM connect_entitlement_versions
             WHERE ncp_user_id = ?
             FOR UPDATE'
        );
        $select->execute([$ncpUserId]);
        $row = $select->fetch();

        if (!$row) {
            $pdo->prepare(
                'INSERT INTO connect_entitlement_versions
                 (ncp_user_id, user_id, entitlement_version, state_json)
                 VALUES (?, ?, ?, ?)'
            )->execute([$ncpUserId, $userId, $version, $stateJson]);
        } elseif (!hash_equals((string)$row['entitlement_version'], $version)) {
            $pdo->prepare(
                'UPDATE connect_entitlement_versions
                 SET user_id = ?, entitlement_version = ?, state_json = ?, changed_at = UTC_TIMESTAMP(6)
                 WHERE ncp_user_id = ?'
            )->execute([$userId, $version, $stateJson, $ncpUserId]);
        }

        $select->execute([$ncpUserId]);
        $current = $select->fetch();
        if (!$current) {
            throw new RuntimeException('Connect entitlement version could not be persisted.');
        }

        if ($enqueueChange && !hash_equals((string)($current['last_enqueued_version'] ?? ''), $version)) {
            connect_enqueue_entitlement_event(
                $ncpUserId,
                'entitlement_version_changed',
                ['entitlement_version' => $version],
                $version
            );
            $pdo->prepare(
                'UPDATE connect_entitlement_versions
                 SET last_enqueued_version = ?, last_enqueued_at = UTC_TIMESTAMP(6)
                 WHERE ncp_user_id = ?'
            )->execute([$version, $ncpUserId]);
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return connect_database_datetime_to_iso8601((string)$current['changed_at']);
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function connect_entitlement_user_for_subject(array $subject, array $requestData = []): array {
    $state = connect_entitlement_state_for_subject($subject, $requestData);
    $version = connect_entitlement_version($state);
    $updatedAt = connect_record_entitlement_version($subject, $state, $version);
    return [
        ...$state,
        'entitlement_version' => $version,
        'updated_at' => $updatedAt,
    ];
}

function connect_google_profile_image_url($value): ?string {
    $url = trim((string)$value);
    if ($url === '' || strlen($url) > 1000 || !filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }
    $parts = parse_url($url);
    if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
        return null;
    }
    return $url;
}

function connect_bind_google_sub_for_subject(array &$subject, string $googleSub, array $requestData = []): bool {
    $googleSub = trim($googleSub);
    if ($googleSub === '') {
        return false;
    }
    $profileImageUrl = connect_google_profile_image_url($requestData['picture'] ?? null);

    $existing = connect_fetch_subject_by_google_sub($googleSub);
    if ($existing && connect_subject_email($existing) !== connect_subject_email($subject)) {
        return false;
    }

    $identity = $subject['identity'] ?? null;
    $user = $subject['user'] ?? null;
    if ($identity) {
        if ($user && !empty($identity['user_id']) && (int)$identity['user_id'] !== (int)$user['id']) {
            return false;
        }
        $storedSub = trim((string)($identity['google_sub'] ?? ''));
        if ($storedSub !== '' && !hash_equals($storedSub, $googleSub)) {
            return false;
        }
        if ($storedSub === '') {
            $stmt = db()->prepare('UPDATE connect_google_identities SET google_sub = ? WHERE id = ? AND google_sub IS NULL');
            $stmt->execute([$googleSub, (int)$identity['id']]);
            $subject['identity']['google_sub'] = $googleSub;
        }
        db()->prepare(
            'UPDATE connect_google_identities
             SET user_id = COALESCE(user_id, ?),
                 profile_image_url = COALESCE(?, profile_image_url),
                 google_verified_at = COALESCE(google_verified_at, UTC_TIMESTAMP(6)),
                 last_google_login_at = UTC_TIMESTAMP(6)
             WHERE id = ?'
        )->execute([$user ? (int)$user['id'] : null, $profileImageUrl, (int)$identity['id']]);
        $subject['identity'] = $user
            ? connect_fetch_identity_overlay_by_user_id((int)$user['id'])
            : connect_fetch_identity_overlay_by_email(connect_subject_email($subject));
        return true;
    }

    if (!$user) {
        return false;
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO connect_google_identities
             (user_id, google_sub, email, display_name, profile_image_url, google_verified_at, last_google_login_at,
              matrix_user_id, is_allowed, is_school_admin, is_approved_developer, developer_permissions, owned_developer_app_ids)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), NULL, 1, ?, 0, "[]", "[]")'
        );
        $stmt->execute([
            (int)$user['id'],
            $googleSub,
            strtolower((string)$user['email']),
            (string)$user['full_name'],
            $profileImageUrl,
            connect_subject_is_school_admin($subject) ? 1 : 0,
        ]);
        $subject['identity'] = connect_fetch_identity_overlay_by_user_id((int)$user['id']);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return false;
        }
        throw $e;
    }

    return true;
}

function connect_enqueue_entitlement_event(
    string $ncpUserId,
    string $reason,
    array $metadata = [],
    ?string $entitlementVersion = null
): string {
    if (!connect_table_exists('connect_entitlement_outbox')) {
        throw new RuntimeException('Connect entitlement outbox is unavailable. Apply the Connect migrations.');
    }
    $ncpUserId = trim($ncpUserId);
    if ($ncpUserId === '' || strlen($ncpUserId) > 190) {
        throw new InvalidArgumentException('Invalid NCP user ID for entitlement event.');
    }
    $reason = trim($reason);
    if ($reason === '') {
        throw new InvalidArgumentException('Entitlement event reason is required.');
    }
    $reason = substr($reason, 0, 120);
    $eventId = 'ncp_evt_' . bin2hex(random_bytes(16));
    $occurredAt = gmdate('Y-m-d\TH:i:s\Z');
    $payload = [
        'event_id' => $eventId,
        'ncp_user_id' => $ncpUserId,
        'reason' => $reason,
        'occurred_at' => $occurredAt,
        'metadata' => $metadata,
    ];
    if ($entitlementVersion !== null) {
        $payload['entitlement_version'] = $entitlementVersion;
    }
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Failed to encode Connect entitlement outbox payload.');
    }

    $stmt = db()->prepare(
        'INSERT INTO connect_entitlement_outbox
         (event_id, ncp_user_id, reason, entitlement_version, payload_json, occurred_at)
         VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6))'
    );
    $stmt->execute([$eventId, $ncpUserId, $reason, $entitlementVersion, $json]);
    return $eventId;
}

function connect_enqueue_entitlement_change_for_user(int $userId, string $reason, array $metadata = []): string {
    if ($userId <= 0) {
        throw new InvalidArgumentException('Invalid user for Connect entitlement event.');
    }
    $user = connect_fetch_user_by_public_or_internal_id((string)$userId);
    if (!$user) {
        throw new RuntimeException('NCP user not found for Connect entitlement event.');
    }
    return connect_enqueue_entitlement_event(connect_user_public_id_from_row($user), $reason, $metadata);
}

function connect_enqueue_entitlement_changes_for_endeavour(int $endeavourId, string $reason, array $metadata = []): int {
    if ($endeavourId <= 0) {
        throw new InvalidArgumentException('Invalid endeavour for Connect entitlement event.');
    }
    $stmt = db()->prepare(
        'SELECT created_by AS user_id FROM endeavours WHERE id = ?
         UNION
         SELECT user_id FROM volunteer_registrations WHERE endeavour_id = ?'
    );
    $stmt->execute([$endeavourId, $endeavourId]);
    $userIds = array_values(array_unique(array_filter(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)))));
    foreach ($userIds as $userId) {
        connect_enqueue_entitlement_change_for_user($userId, $reason, ['endeavour_id' => $endeavourId, ...$metadata]);
    }
    return count($userIds);
}

function connect_enqueue_entitlement_change_safely(int $userId, string $reason, array $metadata = []): bool {
    try {
        connect_enqueue_entitlement_change_for_user($userId, $reason, $metadata);
        return true;
    } catch (Throwable $e) {
        error_log('Failed to enqueue Connect entitlement change for user_id=' . $userId . ': ' . connect_safe_delivery_error($e));
        return false;
    }
}

function connect_transactional(callable $operation) {
    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $result = $operation($pdo);
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $result;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function connect_entitlement_webhook_url(): string {
    return trim((string)env_value('CONNECT_ENTITLEMENT_WEBHOOK_URL', env_value('NCP_ENTITLEMENT_WEBHOOK_URL', '')));
}

function connect_entitlement_webhook_secret(): string {
    return trim((string)env_value('CONNECT_ENTITLEMENT_WEBHOOK_SECRET', env_value('NCP_ENTITLEMENT_WEBHOOK_SECRET', '')));
}

function connect_entitlement_webhook_max_attempts(): int {
    return min(25, max(1, (int)env_value('CONNECT_ENTITLEMENT_WEBHOOK_MAX_ATTEMPTS', '10')));
}

function connect_entitlement_webhook_lease_seconds(): int {
    return min(3600, max(60, (int)env_value('CONNECT_ENTITLEMENT_WEBHOOK_LEASE_SECONDS', '600')));
}

function connect_entitlement_webhook_configuration_error(): ?string {
    $url = connect_entitlement_webhook_url();
    if ($url === '') {
        return 'webhook_url_missing';
    }
    $parts = parse_url($url);
    if (!is_array($parts)
        || empty($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['query'])
        || isset($parts['fragment'])
        || ($parts['path'] ?? '') !== '/internal/ncp/entitlements/changed') {
        return 'webhook_url_invalid';
    }
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $environment = (string)env_value('APP_ENV', 'production');
    if ($scheme !== 'https' && !($environment === 'testing' && $scheme === 'http')) {
        return 'webhook_url_requires_https';
    }
    if (!connect_secret_is_strong(connect_entitlement_webhook_secret())) {
        return 'webhook_secret_missing_or_weak';
    }
    return null;
}

function connect_safe_delivery_error(Throwable $error): string {
    $message = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $error->getMessage()) ?: 'delivery_failed';
    $message = preg_replace('/Bearer\s+[^\s]+/i', 'Bearer [redacted]', $message) ?: 'delivery_failed';
    foreach ([connect_entitlement_webhook_secret(), connect_service_shared_secret()] as $secret) {
        if ($secret !== '') {
            $message = str_replace($secret, '[redacted]', $message);
        }
    }
    return substr(trim($message), 0, 1000);
}

function connect_send_entitlement_webhook(array $payload): void {
    $configurationError = connect_entitlement_webhook_configuration_error();
    if ($configurationError !== null) {
        throw new RuntimeException('Connect entitlement webhook configuration error: ' . $configurationError);
    }
    $url = connect_entitlement_webhook_url();
    $parts = parse_url($url);
    $payload['delivered_at'] = gmdate('Y-m-d\TH:i:s\Z');

    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        throw new RuntimeException('Failed to json_encode entitlement webhook body.');
    }

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . connect_entitlement_webhook_secret(),
    ];
    if (!empty($payload['event_id'])) {
        $headers[] = 'X-NCP-Event-ID: ' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$payload['event_id']);
    }

    if (function_exists('curl_version')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        if (defined('CURLOPT_PROTOCOLS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, ($parts['scheme'] ?? '') === 'https' ? CURLPROTO_HTTPS : CURLPROTO_HTTP);
        }
        $result = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($result === false || $errno !== 0 || $status < 200 || $status >= 300) {
            $suffix = $error !== '' ? ': ' . $error : '';
            throw new RuntimeException('Connect entitlement webhook failed with HTTP status ' . $status . $suffix);
        }
        return;
    }

    if (!ini_get('allow_url_fopen')) {
        throw new RuntimeException('Cannot send entitlement webhook: allow_url_fopen is disabled and cURL is not available.');
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => 6,
            'ignore_errors' => true,
        ],
    ]);
    $result = @file_get_contents($url, false, $context);
    $statusLine = isset($http_response_header) && is_array($http_response_header) && isset($http_response_header[0]) ? $http_response_header[0] : '';
    $status = preg_match('/\s(\d{3})\s/', $statusLine, $matches) ? (int)$matches[1] : 0;
    if ($result === false || $status < 200 || $status >= 300) {
        throw new RuntimeException('Connect entitlement webhook failed with HTTP status ' . $status);
    }
}

function dispatch_connect_entitlement_outbox(int $limit = 50, ?callable $sender = null): array {
    if (!connect_table_exists('connect_entitlement_outbox')) {
        return ['processed' => 0, 'sent' => 0, 'failed' => 0, 'dead_lettered' => 0, 'recovered' => 0, 'disabled' => true, 'error' => 'outbox_unavailable'];
    }

    if ($sender === null && ($configurationError = connect_entitlement_webhook_configuration_error()) !== null) {
        return ['processed' => 0, 'sent' => 0, 'failed' => 0, 'dead_lettered' => 0, 'recovered' => 0, 'disabled' => true, 'error' => $configurationError];
    }

    $limit = min(200, max(1, $limit));
    $pdo = db();
    $leaseSeconds = connect_entitlement_webhook_lease_seconds();
    $maxAttempts = connect_entitlement_webhook_max_attempts();
    $recovery = $pdo->exec(
        'UPDATE connect_entitlement_outbox
         SET status = "failed", claim_token = NULL, claimed_at = NULL,
             next_attempt_at = UTC_TIMESTAMP(6), last_error = "delivery lease expired"
         WHERE status = "sending"
           AND (claimed_at IS NULL OR claimed_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL ' . $leaseSeconds . ' SECOND))'
    );
    $pdo->prepare(
        'UPDATE connect_entitlement_outbox
         SET status = "dead_letter", dead_lettered_at = UTC_TIMESTAMP(6), claim_token = NULL, claimed_at = NULL
         WHERE status IN ("queued", "failed") AND attempts >= ?'
    )->execute([$maxAttempts]);

    $stmt = $pdo->prepare(
        'SELECT id
         FROM connect_entitlement_outbox
         WHERE status IN ("queued", "failed")
           AND next_attempt_at <= UTC_TIMESTAMP(6)
           AND attempts < ?
         ORDER BY id
         LIMIT ?'
    );
    $stmt->bindValue(1, $maxAttempts, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $sent = 0;
    $failed = 0;
    $deadLettered = 0;
    $processed = 0;

    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $claimToken = bin2hex(random_bytes(16));
        $claim = $pdo->prepare(
            'UPDATE connect_entitlement_outbox
             SET status = "sending", attempts = attempts + 1, claim_token = ?,
                 claimed_at = UTC_TIMESTAMP(6), last_attempt_at = UTC_TIMESTAMP(6)
             WHERE id = ? AND status IN ("queued", "failed")
               AND next_attempt_at <= UTC_TIMESTAMP(6) AND attempts < ?'
        );
        $claim->execute([$claimToken, $id, $maxAttempts]);
        if ($claim->rowCount() !== 1) {
            continue;
        }
        $claimed = $pdo->prepare('SELECT * FROM connect_entitlement_outbox WHERE id = ? AND claim_token = ? LIMIT 1');
        $claimed->execute([$id, $claimToken]);
        $claimedRow = $claimed->fetch();
        if (!$claimedRow) {
            continue;
        }
        $processed++;
        $attempts = (int)$claimedRow['attempts'];

        try {
            $payload = json_decode((string)$claimedRow['payload_json'], true);
            if (!is_array($payload)) {
                throw new RuntimeException('Invalid outbox payload JSON.');
            }
            if ($sender !== null) {
                $sender($payload);
            } else {
                connect_send_entitlement_webhook($payload);
            }
            $pdo->prepare(
                'UPDATE connect_entitlement_outbox
                 SET status = "sent", sent_at = UTC_TIMESTAMP(6), last_error = NULL,
                     claim_token = NULL, claimed_at = NULL
                 WHERE id = ? AND claim_token = ?'
            )->execute([$id, $claimToken]);
            $sent++;
        } catch (Throwable $e) {
            $error = connect_safe_delivery_error($e);
            if ($attempts >= $maxAttempts) {
                $pdo->prepare(
                    'UPDATE connect_entitlement_outbox
                     SET status = "dead_letter", dead_lettered_at = UTC_TIMESTAMP(6), last_error = ?,
                         claim_token = NULL, claimed_at = NULL
                     WHERE id = ? AND claim_token = ?'
                )->execute([$error, $id, $claimToken]);
                $deadLettered++;
            } else {
                $delaySeconds = min(3600, 30 * (2 ** max(0, $attempts - 1)));
                $pdo->prepare(
                    'UPDATE connect_entitlement_outbox
                     SET status = "failed",
                         next_attempt_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ' . (int)$delaySeconds . ' SECOND),
                         last_error = ?, claim_token = NULL, claimed_at = NULL
                     WHERE id = ? AND claim_token = ?'
                )->execute([$error, $id, $claimToken]);
                $failed++;
            }
        }
    }

    return [
        'processed' => $processed,
        'sent' => $sent,
        'failed' => $failed,
        'dead_lettered' => $deadLettered,
        'recovered' => (int)$recovery,
        'disabled' => false,
    ];
}

function reconcile_connect_entitlement_versions(int $limit = 250): array {
    if (!connect_table_exists('connect_entitlement_reconciliation_state')
        || !connect_table_exists('connect_entitlement_versions')) {
        return ['processed' => 0, 'changed' => 0, 'failed' => 0, 'disabled' => true];
    }

    $limit = min(1000, max(1, $limit));
    $pdo = db();
    $pdo->exec('INSERT IGNORE INTO connect_entitlement_reconciliation_state (id, last_user_id) VALUES (1, 0)');
    $cursor = (int)$pdo->query('SELECT last_user_id FROM connect_entitlement_reconciliation_state WHERE id = 1')->fetchColumn();
    $pdo->exec('UPDATE connect_entitlement_reconciliation_state SET last_started_at = UTC_TIMESTAMP(6) WHERE id = 1');
    $stmt = $pdo->prepare('SELECT id FROM users WHERE id > ? ORDER BY id LIMIT ?');
    $stmt->bindValue(1, $cursor, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $userIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $changed = 0;
    $failed = 0;

    foreach ($userIds as $userId) {
        try {
            $user = connect_fetch_user_by_public_or_internal_id((string)$userId);
            if (!$user) {
                continue;
            }
            $subject = ['user' => $user, 'identity' => connect_fetch_identity_overlay_by_user_id($userId)];
            $state = connect_entitlement_state_for_subject($subject);
            $version = connect_entitlement_version($state);
            $previous = $pdo->prepare('SELECT last_enqueued_version FROM connect_entitlement_versions WHERE ncp_user_id = ?');
            $previous->execute([(string)$state['id']]);
            $previousVersion = (string)$previous->fetchColumn();
            connect_record_entitlement_version($subject, $state, $version, true);
            if (!hash_equals($previousVersion, $version)) {
                $changed++;
            }
        } catch (Throwable $e) {
            $failed++;
            error_log('Connect entitlement reconciliation failed for user_id=' . $userId . ': ' . connect_safe_delivery_error($e));
        }
    }

    $completed = count($userIds) < $limit;
    $nextCursor = $completed || !$userIds ? 0 : (int)end($userIds);
    $update = $pdo->prepare(
        'UPDATE connect_entitlement_reconciliation_state
         SET last_user_id = ?, last_completed_at = IF(? = 1, UTC_TIMESTAMP(6), last_completed_at)
         WHERE id = 1'
    );
    $update->execute([$nextCursor, $completed ? 1 : 0]);

    return [
        'processed' => count($userIds),
        'changed' => $changed,
        'failed' => $failed,
        'completed_cycle' => $completed,
        'next_user_id' => $nextCursor,
        'disabled' => false,
    ];
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
        if ($requiredPrefix === 'app_' && !preg_match('/^app_[A-Za-z0-9_-]+$/', $item)) {
            respond(['ok' => false, 'error' => "Invalid {$field}"], 400);
        }
        $normalized[] = $item;
    }
    $normalized = array_values(array_unique($normalized));
    sort($normalized, SORT_STRING);
    return $normalized;
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
        if ($serverPublicId === '' || !preg_match('/^srv_[A-Za-z0-9_-]+$/', $serverPublicId)) {
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
    usort($normalized, static fn(array $left, array $right): int => $left['server_public_id'] <=> $right['server_public_id']);
    return $normalized;
}

function connect_load_memberships(int $identityId): array {
    $stmt = db()->prepare('SELECT server_public_id, role FROM connect_user_memberships WHERE identity_id = ? ORDER BY server_public_id');
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
    $subject = connect_fetch_subject_by_email((string)$identity['email']);
    return [
        'id' => (int)$identity['id'],
        'user_id' => $identity['user_id'] !== null ? (int)$identity['user_id'] : null,
        'resolved_user_id' => $identity['resolved_user_id'] !== null ? (int)$identity['resolved_user_id'] : null,
        'resolved_user_public_id' => $identity['resolved_user_public_id'] ?? null,
        'google_sub' => $identity['google_sub'] ?? null,
        'email' => (string)$identity['email'],
        'display_name' => connect_display_name($identity),
        'matrix_user_id' => $identity['matrix_user_id'] ?? '',
        'effective_matrix_user_id' => $subject ? connect_subject_matrix_user_id($subject) : connect_effective_matrix_user_id($identity),
        'is_allowed' => (bool)$identity['is_allowed'],
        'is_school_admin' => (bool)$identity['is_school_admin'],
        'is_approved_developer' => (bool)$identity['is_approved_developer'],
        'developer_permissions' => connect_sorted_string_list($identity['developer_permissions'] ?? '[]', connect_developer_permissions()),
        'owned_developer_app_ids' => connect_sorted_string_list($identity['owned_developer_app_ids'] ?? '[]', null, 'app_'),
        'memberships' => connect_load_memberships((int)$identity['id']),
        'created_at' => $identity['created_at'] ?? null,
        'updated_at' => $identity['updated_at'] ?? null,
    ];
}

function connect_entitlement_user(array $identity, array $requestData = []): array {
    $subject = connect_fetch_subject_by_email((string)$identity['email']);
    if ($subject) {
        return connect_entitlement_user_for_subject($subject, $requestData);
    }
    return [
        'id' => connect_public_identity_id($identity),
        'email' => (string)$identity['email'],
        'display_name' => connect_display_name($identity, $requestData),
        'matrix_user_id' => connect_effective_matrix_user_id($identity),
        'is_school_admin' => (bool)$identity['is_school_admin'],
        'is_approved_developer' => (bool)$identity['is_approved_developer'],
        'developer_permissions' => connect_sorted_string_list($identity['developer_permissions'] ?? '[]', connect_developer_permissions()),
        'owned_developer_app_ids' => connect_sorted_string_list($identity['owned_developer_app_ids'] ?? '[]', null, 'app_'),
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

    $subject = connect_fetch_subject_by_email($email);
    if (!$subject) {
        return connect_not_allowed($email, 'unknown_email');
    }

    if (!connect_subject_is_allowed($subject)) {
        return connect_not_allowed($email, connect_subject_account_status($subject));
    }

    $googleSub = trim((string)($data['google_sub'] ?? ''));
    if (($bindGoogleSub && $googleSub === '')
        || strlen($googleSub) > 190
        || ($googleSub !== '' && preg_match('/[^\x21-\x7E]/', $googleSub))) {
        return connect_not_allowed($email, 'invalid_google_sub');
    }
    $storedSub = trim((string)($subject['identity']['google_sub'] ?? ''));
    if ($storedSub !== '' && $googleSub !== '' && !hash_equals($storedSub, $googleSub)) {
        return connect_not_allowed($email, 'google_sub_mismatch');
    }
    if ($bindGoogleSub) {
        $pdo = db();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            if (!connect_bind_google_sub_for_subject($subject, $googleSub, $data)) {
                if ($ownsTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return connect_not_allowed($email, 'google_sub_already_bound');
            }
            connect_subject_matrix_user_id($subject);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    return ['status' => 200, 'payload' => ['ok' => true, 'user' => connect_entitlement_user_for_subject($subject, $data)]];
}

function connect_resolve_current_entitlements_payload(array $data): array {
    if (empty($data['ncp_user_id']) && empty($data['google_sub']) && empty($data['matrix_user_id']) && empty($data['email'])) {
        return ['status' => 400, 'payload' => ['ok' => false, 'error' => 'missing_lookup']];
    }

    $resolution = connect_fetch_subject_for_current_resolution($data);
    if ($resolution['error'] === 'invalid_lookup') {
        return ['status' => 400, 'payload' => ['ok' => false, 'error' => 'invalid_lookup']];
    }
    if ($resolution['error'] === 'identifier_mismatch') {
        return ['status' => 409, 'payload' => ['ok' => false, 'error' => 'identifier_mismatch']];
    }
    $subject = $resolution['subject'];
    if (!$subject) {
        return ['status' => 200, 'payload' => ['allowed' => false, 'error' => 'not_allowed']];
    }

    $user = connect_entitlement_user_for_subject($subject, $data);
    if (!connect_subject_is_allowed($subject)) {
        return [
            'status' => 200,
            'payload' => [
                'allowed' => false,
                'identity' => [
                    'ncp_user_id' => $user['id'],
                    'email' => $user['email'],
                    'display_name' => $user['display_name'],
                    'account_status' => $user['account_status'],
                    'matrix_user_id' => $user['matrix_user_id'],
                ],
                'entitlement_version' => $user['entitlement_version'],
                'updated_at' => $user['updated_at'],
            ],
        ];
    }

    return [
        'status' => 200,
        'payload' => [
            'allowed' => true,
            'identity' => [
                'ncp_user_id' => $user['id'],
                'email' => $user['email'],
                'display_name' => $user['display_name'],
                'student_id' => $user['student_id'],
                'profile_image_url' => $user['profile_image_url'],
                'account_status' => $user['account_status'],
                'matrix_user_id' => $user['matrix_user_id'],
            ],
            'global_roles' => $user['global_roles'],
            'developer_permissions' => $user['developer_permissions'],
            'owned_developer_app_ids' => $user['owned_developer_app_ids'],
            'managed_memberships' => $user['managed_memberships'],
            'memberships' => $user['memberships'],
            'entitlement_version' => $user['entitlement_version'],
            'updated_at' => $user['updated_at'],
            'user' => $user,
        ],
    ];
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
    if ($existing && array_key_exists('matrix_user_id', $data) && connect_table_exists('connect_matrix_id_mappings')) {
        $mapped = db()->prepare('SELECT matrix_user_id FROM connect_matrix_id_mappings WHERE identity_id = ? LIMIT 1');
        $mapped->execute([(int)$existing['id']]);
        $claimed = strtolower(trim((string)$mapped->fetchColumn()));
        if ($claimed !== '' && !hash_equals($claimed, $matrixUserId)) {
            respond(['ok' => false, 'error' => 'matrix_user_id is immutable after it is claimed'], 409);
        }
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
    if ($userId !== null) {
        $linkedEmail = db()->prepare('SELECT email FROM users WHERE id = ? AND status <> "deleted"');
        $linkedEmail->execute([$userId]);
        $authoritativeEmail = strtolower(trim((string)$linkedEmail->fetchColumn()));
        if ($authoritativeEmail === '' || !hash_equals($authoritativeEmail, $email)) {
            respond(['ok' => false, 'error' => 'Connect identity email must match the linked NCP user'], 409);
        }
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
        $identity = connect_fetch_identity_by_id($identityId);
        $subject = connect_fetch_subject_by_email($payload['email']);
        if (!$identity || !$subject) {
            throw new RuntimeException('Connect identity could not be resolved after creation.');
        }
        connect_subject_matrix_user_id($subject);
        $resolvedUserId = (int)($identity['resolved_user_id'] ?? $identity['user_id'] ?? 0);
        if ($resolvedUserId > 0) {
            connect_enqueue_entitlement_change_for_user($resolvedUserId, 'connect_identity_created', ['identity_id' => $identityId, 'actor_id' => (int)$actor['id']]);
        }
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
        if ($stmt->rowCount() === 0 && !connect_fetch_identity_by_id($identityId)) {
            $pdo->rollBack();
            respond(['ok' => false, 'error' => 'Connect identity not found'], 404);
        }
        connect_sync_memberships($identityId, $payload['memberships']);
        $identity = connect_fetch_identity_by_id($identityId);
        $subject = connect_fetch_subject_by_email($payload['email']);
        if (!$identity || !$subject) {
            throw new RuntimeException('Connect identity could not be resolved after update.');
        }
        connect_subject_matrix_user_id($subject);
        $affectedUserIds = array_unique(array_filter([
            (int)($existing['resolved_user_id'] ?? $existing['user_id'] ?? 0),
            (int)($identity['resolved_user_id'] ?? $identity['user_id'] ?? 0),
        ]));
        foreach ($affectedUserIds as $resolvedUserId) {
            connect_enqueue_entitlement_change_for_user((int)$resolvedUserId, 'connect_identity_updated', ['identity_id' => $identityId, 'actor_id' => (int)$actor['id']]);
        }
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
    $identity = connect_fetch_identity_by_id($identityId);
    if (!$identity) {
        respond(['ok' => false, 'error' => 'Connect identity not found'], 404);
    }
    return connect_identity_for_admin($identity);
}

function connect_delete_identity(int $identityId, array $actor): void {
    $identity = connect_fetch_identity_by_id($identityId);
    if (!$identity) {
        respond(['ok' => false, 'error' => 'Connect identity not found'], 404);
    }
    connect_transactional(function () use ($identityId, $identity, $actor): void {
        $stmt = db()->prepare('DELETE FROM connect_google_identities WHERE id = ?');
        $stmt->execute([$identityId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Connect identity changed before it could be deleted.');
        }
        $resolvedUserId = (int)($identity['resolved_user_id'] ?? $identity['user_id'] ?? 0);
        if ($resolvedUserId > 0) {
            connect_enqueue_entitlement_change_for_user($resolvedUserId, 'connect_identity_deleted', ['identity_id' => $identityId, 'actor_id' => (int)$actor['id']]);
        }
    });
    log_activity($actor['id'], 'connect_identity', $identityId, 'deleted', 'Connect entitlement deleted');
}
