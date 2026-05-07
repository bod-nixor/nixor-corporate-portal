<?php

function rbac_tables_ready(): bool {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        db()->query('SELECT 1 FROM rbac_roles LIMIT 1');
        db()->query('SELECT 1 FROM rbac_permissions LIMIT 1');
        db()->query('SELECT 1 FROM rbac_role_permissions LIMIT 1');
        db()->query('SELECT 1 FROM rbac_user_roles LIMIT 1');
        $ready = true;
    } catch (PDOException $e) {
        $ready = false;
    }
    return $ready;
}

function rbac_user_has_assignments(int $userId): bool {
    if (!rbac_tables_ready()) {
        return false;
    }
    $stmt = db()->prepare('SELECT 1 FROM rbac_user_roles WHERE user_id = ? AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1');
    $stmt->execute([$userId]);
    return (bool)$stmt->fetchColumn();
}

function can_permission(array $user, string $permission, ?int $entityId = null): bool {
    if (!$user || empty($user['id']) || $permission === '') {
        return false;
    }

    $userId = (int)$user['id'];
    if (rbac_user_has_assignments($userId)) {
        $params = [$userId, $permission];
        $entityClause = '';
        if ($entityId !== null) {
            $entityClause = ' AND (ur.entity_id IS NULL OR ur.entity_id = ?)';
            $params[] = $entityId;
        }
        $stmt = db()->prepare(
            'SELECT 1
             FROM rbac_user_roles ur
             JOIN rbac_role_permissions rp ON rp.role_id = ur.role_id
             JOIN rbac_permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = ?
               AND p.code = ?
               AND (ur.expires_at IS NULL OR ur.expires_at > NOW())'
             . $entityClause .
            ' LIMIT 1'
        );
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    }

    return legacy_can_permission($user, $permission, $entityId);
}

function require_permission(string $permission, ?int $entityId = null, ?array $user = null, string $message = 'Forbidden'): array {
    $user = $user ?: require_auth();
    if (!can_permission($user, $permission, $entityId)) {
        respond(['ok' => false, 'error' => $message], 403);
    }
    return $user;
}

function rbac_permissions_for_user(array $user, ?int $entityId = null): array {
    if (!$user || empty($user['id'])) {
        return [];
    }
    $userId = (int)$user['id'];
    if (rbac_user_has_assignments($userId)) {
        $params = [$userId];
        $entityClause = '';
        if ($entityId !== null) {
            $entityClause = ' AND (ur.entity_id IS NULL OR ur.entity_id = ?)';
            $params[] = $entityId;
        }
        $stmt = db()->prepare(
            'SELECT DISTINCT p.code
             FROM rbac_user_roles ur
             JOIN rbac_role_permissions rp ON rp.role_id = ur.role_id
             JOIN rbac_permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = ?
               AND (ur.expires_at IS NULL OR ur.expires_at > NOW())'
             . $entityClause .
            ' ORDER BY p.code'
        );
        $stmt->execute($params);
        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    return legacy_permissions_for_user($user, $entityId);
}

function rbac_roles_for_user(array $user): array {
    if (!$user || empty($user['id']) || !rbac_tables_ready()) {
        return [];
    }
    $stmt = db()->prepare(
        'SELECT r.id, r.code, r.name, r.scope, ur.entity_id, e.name AS entity_name, ur.assigned_at, ur.expires_at
         FROM rbac_user_roles ur
         JOIN rbac_roles r ON r.id = ur.role_id
         LEFT JOIN entities e ON e.id = ur.entity_id
         WHERE ur.user_id = ?
           AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
         ORDER BY r.scope, r.name, e.name'
    );
    $stmt->execute([(int)$user['id']]);
    return $stmt->fetchAll();
}

function social_entity_memberships_for_user(array $user, int $entityId): array {
    if (!$user || empty($user['id']) || $entityId <= 0) {
        return [];
    }
    $stmt = db()->prepare('SELECT * FROM entity_memberships WHERE user_id = ? AND entity_id = ?');
    $stmt->execute([(int)$user['id'], $entityId]);
    return $stmt->fetchAll();
}

function social_entity_rbac_roles_for_user(array $user, int $entityId): array {
    if (!$user || empty($user['id']) || $entityId <= 0 || !rbac_tables_ready()) {
        return [];
    }
    $stmt = db()->prepare(
        'SELECT r.id, r.code, r.name, r.scope, ur.entity_id
         FROM rbac_user_roles ur
         JOIN rbac_roles r ON r.id = ur.role_id
         WHERE ur.user_id = ?
           AND ur.entity_id = ?
           AND (ur.expires_at IS NULL OR ur.expires_at > NOW())'
    );
    $stmt->execute([(int)$user['id'], $entityId]);
    return $stmt->fetchAll();
}

function social_role_is_entity_executive(array $role): bool {
    $code = preg_replace('/[^a-z0-9]+/', '_', strtolower((string)($role['code'] ?? '')));
    if (in_array($code, ['entity_executive', 'ceo', 'cco', 'cm', 'chro', 'hrm'], true)) {
        return true;
    }
    if (social_role_is_c_level($role)) {
        return true;
    }
    $name = strtolower((string)($role['name'] ?? ''));
    return (str_contains($code, 'executive') || str_contains($name, 'executive') || str_contains($name, 'manager'))
        && !str_contains($code, 'member')
        && !str_contains($code, 'volunteer')
        && !str_contains($name, 'member')
        && !str_contains($name, 'volunteer');
}

function social_user_has_entity_membership(array $user, int $entityId): bool {
    if (count(social_entity_memberships_for_user($user, $entityId)) > 0) {
        return true;
    }
    foreach (social_entity_rbac_roles_for_user($user, $entityId) as $role) {
        if (($role['scope'] ?? '') === 'entity') {
            return true;
        }
    }
    return false;
}

function social_user_has_entity_executive_membership(array $user, int $entityId): bool {
    foreach (social_entity_memberships_for_user($user, $entityId) as $membership) {
        if (in_array($membership['role'] ?? '', ['manager', 'executive'], true)) {
            return true;
        }
    }
    foreach (social_entity_rbac_roles_for_user($user, $entityId) as $role) {
        if (social_role_is_entity_executive($role)) {
            return true;
        }
    }
    return false;
}

function social_c_level_role_codes(): array {
    return ['ceo', 'cfo', 'coo', 'cco', 'chro', 'cto', 'cio', 'cmo', 'cpo'];
}

function social_role_is_c_level(array $role): bool {
    $code = preg_replace('/[^a-z0-9]+/', '_', strtolower((string)($role['code'] ?? '')));
    if (in_array($code, social_c_level_role_codes(), true)) {
        return true;
    }

    $name = strtoupper((string)($role['name'] ?? ''));
    foreach (['CEO', 'CFO', 'COO', 'CCO', 'CHRO', 'CTO', 'CIO', 'CMO', 'CPO'] as $label) {
        if (preg_match('/\b' . preg_quote($label, '/') . '\b/', $name)) {
            return true;
        }
    }
    return false;
}

function social_user_has_c_level_entity_role(array $user): bool {
    if (!$user || empty($user['id'])) {
        return false;
    }

    if (rbac_user_has_assignments((int)$user['id'])) {
        foreach (rbac_roles_for_user($user) as $role) {
            if (social_role_is_c_level($role)) {
                return true;
            }
        }
        return false;
    }

    $memberships = legacy_memberships_for_user((int)$user['id']);
    if (!$memberships) {
        return false;
    }
    if (($user['global_role'] ?? '') === 'ceo') {
        return true;
    }
    foreach ($memberships as $membership) {
        $department = $membership['department'] ?? '';
        $role = $membership['role'] ?? '';
        if ($role === 'manager' && in_array($department, ['communications', 'hr'], true)) {
            return true;
        }
    }
    return false;
}

function social_user_has_high_level_social_role(array $user): bool {
    if (!$user || empty($user['id'])) {
        return false;
    }

    if (rbac_user_has_assignments((int)$user['id'])) {
        foreach (rbac_roles_for_user($user) as $role) {
            $code = preg_replace('/[^a-z0-9]+/', '_', strtolower((string)($role['code'] ?? '')));
            if (($role['entity_id'] ?? null) === null && in_array($code, ['site_admin', 'member_board', 'student_affairs'], true)) {
                return true;
            }
        }
        return false;
    }

    return in_array($user['global_role'] ?? '', ['admin', 'board', 'student_affairs'], true);
}

function social_can_view_entity_feed(?array $user, int $entityId): bool {
    if (!$user || $entityId <= 0) {
        return false;
    }
    return social_user_has_entity_membership($user, $entityId)
        || rbac_has_global_permission($user, 'social.view');
}

function social_can_post_entity_feed(?array $user, int $entityId): bool {
    if (!$user || $entityId <= 0) {
        return false;
    }
    return (
        social_user_has_entity_executive_membership($user, $entityId)
        && can_permission($user, 'social.create', $entityId)
    ) || (
        social_user_has_high_level_social_role($user)
        && can_permission($user, 'social.create', $entityId)
    );
}

function social_can_interact_entity_feed(?array $user, int $entityId): bool {
    if (!$user || $entityId <= 0 || !social_user_has_entity_membership($user, $entityId)) {
        return false;
    }
    return can_permission($user, 'social.like', $entityId)
        || can_permission($user, 'social.create', $entityId);
}

function social_can_view_global_feed(?array $user = null): bool {
    return true;
}

function social_can_post_global_feed(?array $user): bool {
    if (!$user) {
        return false;
    }
    return social_user_has_c_level_entity_role($user)
        && can_permission($user, 'social.create');
}

function social_can_interact_global_feed(?array $user): bool {
    if (!$user) {
        return false;
    }
    return can_permission($user, 'social.like')
        || can_permission($user, 'social.create');
}

function rbac_visible_nav(array $user): array {
    $links = [
        'dashboard' => ['permission' => 'nav.dashboard', 'href' => '/dashboard.html', 'text' => 'Entity Dashboard'],
        'entity_endeavours' => ['permission' => 'nav.entity_endeavours', 'href' => '/entity_endeavours.html', 'text' => 'Entity Endeavours'],
        'entity_drive' => ['permission' => 'nav.entity_drive', 'href' => '/entity_drive.html', 'text' => 'Entity Drive'],
        'calendar' => ['permission' => 'nav.calendar', 'href' => '/calendar.html', 'text' => 'Calendar'],
        'social' => ['permission' => 'nav.social', 'href' => '/social.html', 'text' => 'Social'],
        'endeavours' => ['permission' => 'nav.volunteering', 'href' => '/endeavours.html', 'text' => 'Volunteering'],
        'volunteering_ops' => ['permission' => 'nav.volunteering_ops', 'href' => '/volunteering_ops.html', 'text' => 'Volunteer Ops'],
        'settings' => ['permission' => 'nav.settings', 'href' => '/settings.html', 'text' => 'Settings'],
        'admin' => ['permission' => 'nav.admin', 'href' => '/admin.html', 'text' => 'Admin Panel'],
    ];
    $visible = [];
    foreach ($links as $id => $link) {
        if (can_permission($user, $link['permission'])) {
            $visible[] = [
                'id' => $id,
                'href' => $link['href'],
                'text' => $link['text'],
                'permission' => $link['permission'],
            ];
        }
    }
    return $visible;
}

function rbac_entity_ids_for_permission(array $user, string $permission): array {
    if (!$user || empty($user['id'])) {
        return [];
    }
    if (can_permission($user, $permission, null) && rbac_has_global_permission($user, $permission)) {
        return array_map('intval', db()->query('SELECT id FROM entities')->fetchAll(PDO::FETCH_COLUMN));
    }
    if (rbac_user_has_assignments((int)$user['id'])) {
        $stmt = db()->prepare(
            'SELECT DISTINCT ur.entity_id
             FROM rbac_user_roles ur
             JOIN rbac_role_permissions rp ON rp.role_id = ur.role_id
             JOIN rbac_permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = ?
               AND ur.entity_id IS NOT NULL
               AND p.code = ?
               AND (ur.expires_at IS NULL OR ur.expires_at > NOW())'
        );
        $stmt->execute([(int)$user['id'], $permission]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    return legacy_entity_ids_for_permission($user, $permission);
}

function rbac_has_global_permission(array $user, string $permission): bool {
    if (!$user || empty($user['id'])) {
        return false;
    }
    if (rbac_user_has_assignments((int)$user['id'])) {
        $stmt = db()->prepare(
            'SELECT 1
             FROM rbac_user_roles ur
             JOIN rbac_role_permissions rp ON rp.role_id = ur.role_id
             JOIN rbac_permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = ?
               AND ur.entity_id IS NULL
               AND p.code = ?
               AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
             LIMIT 1'
        );
        $stmt->execute([(int)$user['id'], $permission]);
        return (bool)$stmt->fetchColumn();
    }
    return in_array($user['global_role'] ?? '', ['admin', 'board', 'student_affairs'], true);
}

function legacy_permissions_for_user(array $user, ?int $entityId = null): array {
    $all = [
        'nav.dashboard','nav.entity_endeavours','nav.entity_drive','nav.calendar','nav.social','nav.volunteering','nav.volunteering_ops','nav.settings','nav.admin',
        'admin.manage_entities','admin.manage_users','admin.manage_roles','admin.assign_roles','entity.view','entity.announce',
        'drive.view','drive.manage','drive.label','endeavour.view','endeavour.create','endeavour.edit','endeavour.submit_docs',
        'endeavour.approve_mob','endeavour.approve_sa','endeavour.approve_edit','endeavour.view_confidential','endeavour.manage_periods',
        'volunteering.view','volunteering.register','volunteering.shortlist','volunteering.ops',
        'calendar.view','calendar.create','calendar.manage','calendar.rsvp','calendar.minutes.submit','calendar.minutes.view',
        'social.view','social.global.view','social.create','social.moderate','social.like',
        'settings.view','notifications.manage_preferences'
    ];
    if (($user['global_role'] ?? '') === 'admin') {
        return $all;
    }

    $perms = ['nav.settings', 'settings.view', 'notifications.manage_preferences', 'social.global.view'];
    $globalRole = $user['global_role'] ?? '';
    if ($globalRole === 'board') {
        return array_values(array_diff($all, ['nav.admin','admin.manage_entities','admin.manage_users','admin.manage_roles','admin.assign_roles','endeavour.approve_sa','volunteering.ops']));
    }
    if ($globalRole === 'student_affairs') {
        return array_values(array_diff($all, ['nav.admin','admin.manage_entities','admin.manage_users','admin.manage_roles','admin.assign_roles','endeavour.approve_mob']));
    }

    $memberships = legacy_memberships_for_user((int)$user['id'], $entityId);
    if ($memberships) {
        array_push($perms, 'nav.dashboard', 'nav.entity_endeavours', 'nav.entity_drive', 'nav.calendar', 'nav.social', 'nav.volunteering');
        array_push($perms, 'entity.view', 'drive.view', 'endeavour.view', 'calendar.view', 'calendar.rsvp', 'calendar.minutes.view', 'social.view', 'social.create', 'social.like', 'volunteering.view');
    }

    foreach ($memberships as $membership) {
        $department = $membership['department'] ?? '';
        $role = $membership['role'] ?? '';
        $isExecutive = in_array($role, ['manager', 'executive'], true);
        if ($globalRole === 'ceo' || ($department === 'management' && $role === 'manager')) {
            array_push($perms, 'entity.announce', 'drive.manage', 'drive.label', 'endeavour.create', 'endeavour.edit', 'endeavour.submit_docs', 'endeavour.view_confidential', 'volunteering.shortlist', 'calendar.create', 'calendar.manage', 'calendar.minutes.submit', 'social.moderate');
        } elseif ($isExecutive) {
            array_push($perms, 'endeavour.edit', 'endeavour.submit_docs', 'calendar.minutes.submit');
            if ($department === 'communications') {
                array_push($perms, 'entity.announce', 'calendar.create', 'calendar.manage', 'social.moderate');
            }
            if ($department === 'hr') {
                array_push($perms, 'volunteering.shortlist');
            }
        }
    }

    if ($globalRole === 'volunteer') {
        array_push($perms, 'nav.volunteering', 'volunteering.register', 'volunteering.view', 'social.create', 'social.like');
    }

    return array_values(array_unique($perms));
}

function legacy_can_permission(array $user, string $permission, ?int $entityId = null): bool {
    return in_array($permission, legacy_permissions_for_user($user, $entityId), true);
}

function legacy_memberships_for_user(int $userId, ?int $entityId = null): array {
    $params = [$userId];
    $where = 'user_id = ?';
    if ($entityId !== null) {
        $where .= ' AND entity_id = ?';
        $params[] = $entityId;
    }
    $stmt = db()->prepare("SELECT * FROM entity_memberships WHERE {$where}");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function legacy_entity_ids_for_permission(array $user, string $permission): array {
    if (in_array($user['global_role'] ?? '', ['admin', 'board', 'student_affairs'], true)) {
        return array_map('intval', db()->query('SELECT id FROM entities')->fetchAll(PDO::FETCH_COLUMN));
    }
    $stmt = db()->prepare('SELECT DISTINCT entity_id FROM entity_memberships WHERE user_id = ?');
    $stmt->execute([(int)$user['id']]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    return array_values(array_filter($ids, fn($entityId) => legacy_can_permission($user, $permission, $entityId)));
}
