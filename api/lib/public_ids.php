<?php

function public_id_prefix_for_table(string $table): string {
    $map = [
        'users' => 'usr',
        'entities' => 'ent',
        'endeavours' => 'end',
        'file_drive_items' => 'drv',
        'calendar_events' => 'cal',
        'dashboard_announcements' => 'ann',
        'social_posts' => 'pst',
        'social_comments' => 'cmt',
        'social_post_images' => 'img',
    ];
    return $map[$table] ?? 'pub';
}

function public_id_table_allowed(string $table): bool {
    return in_array($table, [
        'users',
        'entities',
        'endeavours',
        'file_drive_items',
        'calendar_events',
        'dashboard_announcements',
        'social_posts',
        'social_comments',
        'social_post_images',
    ], true);
}

function generate_public_id(string $prefix): string {
    $safePrefix = preg_replace('/[^a-z0-9]+/i', '', strtolower($prefix)) ?: 'pub';
    return $safePrefix . '_' . bin2hex(random_bytes(16));
}

function public_id_column_exists(string $table): bool {
    if (!public_id_table_allowed($table)) {
        return false;
    }
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    try {
        $stmt = db()->prepare(
            'SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = "public_id"
             LIMIT 1'
        );
        $stmt->execute([$table]);
        $cache[$table] = (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        $cache[$table] = false;
    }
    return $cache[$table];
}

function public_id_for_row(string $table, int $id, ?string $prefix = null): ?string {
    if ($id <= 0 || !public_id_table_allowed($table) || !public_id_column_exists($table)) {
        return null;
    }

    $stmt = db()->prepare("SELECT public_id FROM {$table} WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $publicId = trim((string)$stmt->fetchColumn());
    if ($publicId !== '') {
        return $publicId;
    }

    $prefix = $prefix ?: public_id_prefix_for_table($table);
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $candidate = generate_public_id($prefix);
        try {
            $update = db()->prepare("UPDATE {$table} SET public_id = ? WHERE id = ? AND (public_id IS NULL OR public_id = '')");
            $update->execute([$candidate, $id]);
            if ($update->rowCount() > 0) {
                return $candidate;
            }
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) !== 1062) {
                throw $e;
            }
        }

        $stmt->execute([$id]);
        $publicId = trim((string)$stmt->fetchColumn());
        if ($publicId !== '') {
            return $publicId;
        }
    }

    return null;
}

function resolve_public_or_internal_id(string $table, $identifier): ?int {
    if (!public_id_table_allowed($table)) {
        return null;
    }
    $value = trim((string)$identifier);
    if ($value === '') {
        return null;
    }

    if (ctype_digit($value)) {
        $id = (int)$value;
        return $id > 0 ? $id : null;
    }

    if (!public_id_column_exists($table)) {
        return null;
    }

    $stmt = db()->prepare("SELECT id FROM {$table} WHERE public_id = ? LIMIT 1");
    $stmt->execute([$value]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

function public_url_base(): string {
    $configured = trim((string)env_value('PUBLIC_BASE_URL', ''));
    if ($configured === '') {
        $configured = trim((string)base_url());
    }
    if ($configured === '') {
        $configured = 'https://ncp.nixorcorporate.com';
    }
    $parts = parse_url($configured);
    $host = strtolower((string)($parts['host'] ?? ''));
    if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        return 'https://ncp.nixorcorporate.com';
    }
    $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
    if (!in_array($scheme, ['http', 'https'], true)) {
        $scheme = 'https';
    }
    $path = isset($parts['path']) ? rtrim((string)$parts['path'], '/') : '';
    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    return $scheme . '://' . $host . $port . $path;
}

function public_relative_url(string $path, array $params = []): string {
    $path = '/' . ltrim($path, '/');
    $query = http_build_query(array_filter($params, static fn($value) => $value !== null && $value !== ''), '', '&', PHP_QUERY_RFC3986);
    return $path . ($query ? '?' . $query : '');
}

function public_absolute_url(string $path, array $params = []): string {
    return rtrim(public_url_base(), '/') . public_relative_url($path, $params);
}
