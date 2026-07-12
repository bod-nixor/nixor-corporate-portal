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
        return $fallbackKey;
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

    try {
        $stmt = db()->prepare(
            'INSERT INTO connect_resource_mappings (source_type, source_id, resource_key, resource_type, display_name)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               display_name = VALUES(display_name),
               resource_type = VALUES(resource_type),
               active = 1'
        );
        $stmt->execute([$sourceType, $sourceId, $fallbackKey, $resourceType, $displayName]);
    } catch (PDOException $e) {
        error_log('Failed to persist Connect resource mapping: ' . $e->getMessage());
        return $fallbackKey;
    }

    $existing->execute([$sourceType, $sourceId]);
    $resourceKey = trim((string)$existing->fetchColumn());
    return $resourceKey !== '' ? $resourceKey : $fallbackKey;
}

function connect_fixed_resource_key(string $key): string {
    if (!connect_table_exists('connect_resource_mappings')) {
        return $key;
    }

    $stmt = db()->prepare(
        'SELECT resource_key
         FROM connect_resource_mappings
         WHERE source_type = ? AND source_id = ? AND active = 1
         LIMIT 1'
    );
    $stmt->execute([$key, $key]);
    $mapped = trim((string)$stmt->fetchColumn());
    return $mapped !== '' ? $mapped : $key;
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

function connect_fetch_subject_by_matrix_user_id(string $matrixUserId): ?array {
    $matrixUserId = strtolower(trim($matrixUserId));
    $stmt = db()->prepare('SELECT * FROM connect_google_identities WHERE LOWER(matrix_user_id) = ? LIMIT 1');
    $stmt->execute([$matrixUserId]);
    $identity = $stmt->fetch();
    if ($identity) {
        $user = !empty($identity['user_id'])
            ? connect_fetch_user_by_public_or_internal_id((string)$identity['user_id'])
            : connect_fetch_user_by_email((string)$identity['email']);
        return ['user' => $user, 'identity' => $identity];
    }

    if (preg_match('/^@([^:]+):' . preg_quote(CONNECT_MATRIX_SERVER_NAME, '/') . '$/', $matrixUserId, $matches)) {
        $localpart = str_replace('.', '', $matches[1]);
        $stmt = db()->prepare('SELECT * FROM users WHERE LOWER(REPLACE(SUBSTRING_INDEX(email, "@", 1), ".", "")) = ? LIMIT 1');
        $stmt->execute([$localpart]);
        $user = $stmt->fetch();
        if ($user) {
            return ['user' => $user, 'identity' => connect_fetch_identity_overlay_by_user_id((int)$user['id'])];
        }
    }

    return null;
}

function connect_fetch_subject_for_current_resolution(array $data): ?array {
    if (!empty($data['ncp_user_id'])) {
        $user = connect_fetch_user_by_public_or_internal_id((string)$data['ncp_user_id']);
        if ($user) {
            return ['user' => $user, 'identity' => connect_fetch_identity_overlay_by_user_id((int)$user['id'])];
        }
    }

    if (!empty($data['google_sub'])) {
        $subject = connect_fetch_subject_by_google_sub((string)$data['google_sub']);
        if ($subject) {
            return $subject;
        }
    }

    if (!empty($data['matrix_user_id'])) {
        $subject = connect_fetch_subject_by_matrix_user_id((string)$data['matrix_user_id']);
        if ($subject) {
            return $subject;
        }
    }

    if (!empty($data['email'])) {
        return connect_fetch_subject_by_email((string)$data['email']);
    }

    return null;
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
    $identity = $subject['identity'] ?? [];
    $manual = strtolower(trim((string)($identity['matrix_user_id'] ?? '')));
    if ($manual !== '' && connect_matrix_user_id_is_valid($manual)) {
        return $manual;
    }
    return connect_matrix_user_id_for_email(connect_subject_email($subject));
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

    return array_values(array_unique(array_filter($roles)));
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

    return array_values($memberships);
}

function connect_entitlement_version(array $subject, array $managedMemberships): string {
    $identity = $subject['identity'] ?? null;
    $user = $subject['user'] ?? null;
    $fingerprint = [
        'user_id' => $user['id'] ?? null,
        'user_updated_at' => $user['updated_at'] ?? null,
        'user_status' => $user['status'] ?? null,
        'user_role' => $user['global_role'] ?? null,
        'identity_updated_at' => $identity['updated_at'] ?? null,
        'memberships' => $managedMemberships,
    ];

    return 'ncp_' . substr(hash('sha256', json_encode($fingerprint) ?: serialize($fingerprint)), 0, 16);
}

function connect_entitlement_user_for_subject(array $subject, array $requestData = []): array {
    $identity = $subject['identity'] ?? [];
    $managedMemberships = connect_managed_memberships_for_subject($subject);
    $accountStatus = connect_subject_account_status($subject);

    return [
        'id' => !empty($subject['user']) ? connect_user_public_id_from_row($subject['user']) : connect_public_identity_id($identity),
        'email' => connect_subject_email($subject),
        'display_name' => connect_subject_display_name($subject, $requestData),
        'student_id' => null,
        'profile_image_url' => $identity['profile_image_url'] ?? ($requestData['picture'] ?? null),
        'account_status' => $accountStatus,
        'matrix_user_id' => connect_subject_matrix_user_id($subject),
        'is_school_admin' => connect_subject_is_school_admin($subject),
        'is_approved_developer' => (bool)($identity['is_approved_developer'] ?? false),
        'developer_permissions' => connect_json_array($identity['developer_permissions'] ?? '[]'),
        'owned_developer_app_ids' => connect_json_array($identity['owned_developer_app_ids'] ?? '[]'),
        'global_roles' => connect_subject_global_roles($subject),
        'managed_memberships' => $managedMemberships,
        'entitlement_version' => connect_entitlement_version($subject, $managedMemberships),
        'updated_at' => gmdate('c'),
        'memberships' => !empty($identity['id']) ? connect_load_memberships((int)$identity['id']) : [],
    ];
}

function connect_bind_google_sub_for_subject(array &$subject, string $googleSub): bool {
    $googleSub = trim($googleSub);
    if ($googleSub === '') {
        return true;
    }

    $existing = connect_fetch_subject_by_google_sub($googleSub);
    if ($existing && connect_subject_email($existing) !== connect_subject_email($subject)) {
        return false;
    }

    $identity = $subject['identity'] ?? null;
    $user = $subject['user'] ?? null;
    if ($identity) {
        $storedSub = trim((string)($identity['google_sub'] ?? ''));
        if ($storedSub !== '' && !hash_equals($storedSub, $googleSub)) {
            return false;
        }
        if ($storedSub === '') {
            $stmt = db()->prepare('UPDATE connect_google_identities SET google_sub = ? WHERE id = ? AND google_sub IS NULL');
            $stmt->execute([$googleSub, (int)$identity['id']]);
            $subject['identity']['google_sub'] = $googleSub;
        }
        return true;
    }

    if (!$user) {
        return false;
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO connect_google_identities
             (user_id, google_sub, email, display_name, matrix_user_id, is_allowed, is_school_admin, is_approved_developer, developer_permissions, owned_developer_app_ids)
             VALUES (?, ?, ?, ?, NULL, 1, ?, 0, "[]", "[]")'
        );
        $stmt->execute([
            (int)$user['id'],
            $googleSub,
            strtolower((string)$user['email']),
            (string)$user['full_name'],
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

function connect_enqueue_entitlement_change_for_user(int $userId, string $reason, array $metadata = []): void {
    if ($userId <= 0 || !connect_table_exists('connect_entitlement_outbox')) {
        return;
    }

    $user = connect_fetch_user_by_public_or_internal_id((string)$userId);
    if (!$user) {
        return;
    }

    $eventId = 'ncp_evt_' . bin2hex(random_bytes(16));
    $occurredAt = gmdate('c');
    $payload = [
        'event_id' => $eventId,
        'ncp_user_id' => connect_user_public_id_from_row($user),
        'reason' => $reason,
        'occurred_at' => $occurredAt,
        'metadata' => $metadata,
    ];
    $json = json_encode($payload);
    if ($json === false) {
        error_log('Failed to encode Connect entitlement outbox payload for user_id=' . $userId);
        return;
    }

    $stmt = db()->prepare(
        'INSERT INTO connect_entitlement_outbox (event_id, ncp_user_id, reason, payload_json, occurred_at)
         VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6))'
    );
    $stmt->execute([$eventId, (string)$payload['ncp_user_id'], substr($reason, 0, 120), $json]);
}

function connect_enqueue_entitlement_change_safely(int $userId, string $reason, array $metadata = []): void {
    try {
        connect_enqueue_entitlement_change_for_user($userId, $reason, $metadata);
    } catch (Throwable $e) {
        error_log('Failed to enqueue Connect entitlement change for user_id=' . $userId . ': ' . $e->getMessage());
    }
}

function connect_entitlement_webhook_url(): string {
    return trim((string)env_value('CONNECT_ENTITLEMENT_WEBHOOK_URL', env_value('NCP_ENTITLEMENT_WEBHOOK_URL', '')));
}

function connect_entitlement_webhook_secret(): string {
    return trim((string)env_value('CONNECT_ENTITLEMENT_WEBHOOK_SECRET', env_value('NCP_ENTITLEMENT_WEBHOOK_SECRET', '')));
}

function connect_send_entitlement_webhook(array $payload): void {
    $url = connect_entitlement_webhook_url();
    if ($url === '') {
        throw new RuntimeException('Connect entitlement webhook URL missing.');
    }

    $parts = parse_url($url);
    if (($parts['scheme'] ?? '') !== 'https' && env_value('APP_ENV') !== 'testing') {
        throw new RuntimeException('Connect entitlement webhook URL must use https scheme.');
    }

    $body = json_encode($payload);
    if ($body === false) {
        throw new RuntimeException('Failed to json_encode entitlement webhook body.');
    }

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    $secret = connect_entitlement_webhook_secret();
    if ($secret !== '') {
        $headers[] = 'Authorization: Bearer ' . $secret;
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
        $result = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($result === false || $errno !== 0 || $status < 200 || $status >= 300) {
            throw new RuntimeException('Connect entitlement webhook failed: ' . $status . ' ' . trim($error ?: substr((string)$result, 0, 200)));
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
        throw new RuntimeException('Connect entitlement webhook failed: ' . $status . ' ' . substr((string)$result, 0, 200));
    }
}

function dispatch_connect_entitlement_outbox(int $limit = 50): array {
    if (!connect_table_exists('connect_entitlement_outbox')) {
        return ['processed' => 0, 'sent' => 0, 'failed' => 0, 'disabled' => true];
    }

    if (connect_entitlement_webhook_url() === '') {
        return ['processed' => 0, 'sent' => 0, 'failed' => 0, 'disabled' => true];
    }

    $limit = min(200, max(1, $limit));
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT *
         FROM connect_entitlement_outbox
         WHERE status IN ("queued", "failed")
           AND next_attempt_at <= UTC_TIMESTAMP(6)
         ORDER BY id
         LIMIT ?'
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $sent = 0;
    $failed = 0;

    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $attempts = (int)$row['attempts'] + 1;
        $pdo->prepare('UPDATE connect_entitlement_outbox SET status = "sending", attempts = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?')
            ->execute([$attempts, $id]);

        try {
            $payload = json_decode((string)$row['payload_json'], true);
            if (!is_array($payload)) {
                throw new RuntimeException('Invalid outbox payload JSON.');
            }
            connect_send_entitlement_webhook($payload);
            $pdo->prepare('UPDATE connect_entitlement_outbox SET status = "sent", sent_at = UTC_TIMESTAMP(6), last_error = NULL WHERE id = ?')
                ->execute([$id]);
            $sent++;
        } catch (Throwable $e) {
            $delaySeconds = min(3600, 30 * (2 ** max(0, $attempts - 1)));
            $pdo->prepare(
                'UPDATE connect_entitlement_outbox
                 SET status = "failed",
                     next_attempt_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ' . (int)$delaySeconds . ' SECOND),
                     last_error = ?
                 WHERE id = ?'
            )->execute([substr($e->getMessage(), 0, 1000), $id]);
            $failed++;
        }
    }

    return ['processed' => count($rows), 'sent' => $sent, 'failed' => $failed, 'disabled' => false];
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

    $subject = connect_fetch_subject_by_email($email);
    if (!$subject) {
        return connect_not_allowed($email, 'unknown_email');
    }

    if (!connect_subject_is_allowed($subject)) {
        return connect_not_allowed($email, connect_subject_account_status($subject));
    }

    $googleSub = trim((string)($data['google_sub'] ?? ''));
    $storedSub = trim((string)($subject['identity']['google_sub'] ?? ''));
    if ($storedSub !== '' && $googleSub !== '' && !hash_equals($storedSub, $googleSub)) {
        return connect_not_allowed($email, 'google_sub_mismatch');
    }
    if ($bindGoogleSub && !connect_bind_google_sub_for_subject($subject, $googleSub)) {
        return connect_not_allowed($email, 'google_sub_already_bound');
    }

    return ['status' => 200, 'payload' => ['ok' => true, 'user' => connect_entitlement_user_for_subject($subject, $data)]];
}

function connect_resolve_current_entitlements_payload(array $data): array {
    if (empty($data['ncp_user_id']) && empty($data['google_sub']) && empty($data['matrix_user_id']) && empty($data['email'])) {
        return ['status' => 400, 'payload' => ['ok' => false, 'error' => 'missing_lookup']];
    }

    $subject = connect_fetch_subject_for_current_resolution($data);
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
    $resolvedUserId = (int)($identity['resolved_user_id'] ?? $identity['user_id'] ?? 0);
    if ($resolvedUserId > 0) {
        connect_enqueue_entitlement_change_safely($resolvedUserId, 'connect_identity_created', ['identity_id' => $identityId, 'actor_id' => (int)$actor['id']]);
    }
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
    $resolvedUserId = (int)($identity['resolved_user_id'] ?? $identity['user_id'] ?? 0);
    if ($resolvedUserId > 0) {
        connect_enqueue_entitlement_change_safely($resolvedUserId, 'connect_identity_updated', ['identity_id' => $identityId, 'actor_id' => (int)$actor['id']]);
    }
    return connect_identity_for_admin($identity);
}

function connect_delete_identity(int $identityId, array $actor): void {
    $identity = connect_fetch_identity_by_id($identityId);
    $stmt = db()->prepare('DELETE FROM connect_google_identities WHERE id = ?');
    $stmt->execute([$identityId]);
    if ($stmt->rowCount() === 0) {
        respond(['ok' => false, 'error' => 'Connect identity not found'], 404);
    }
    log_activity($actor['id'], 'connect_identity', $identityId, 'deleted', 'Connect entitlement deleted');
    $resolvedUserId = (int)($identity['resolved_user_id'] ?? $identity['user_id'] ?? 0);
    if ($resolvedUserId > 0) {
        connect_enqueue_entitlement_change_safely($resolvedUserId, 'connect_identity_deleted', ['identity_id' => $identityId, 'actor_id' => (int)$actor['id']]);
    }
}
