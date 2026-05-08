<?php
function handle_social(string $method, array $segments): void {
    $id = $segments[1] ?? null;
    $action = $segments[2] ?? '';

    if ($method === 'GET' && $id === 'global') {
        if ($action !== '' || count($segments) !== 2) {
            respond(['ok' => false, 'error' => 'Not Found'], 404);
        }
        $user = current_user();
        respond([
            'ok' => true,
            'data' => social_fetch_feed(null, 'global', $user),
            'meta' => ['permissions' => social_feed_permissions('global', null, $user)]
        ]);
    }

    $user = require_auth();

    if ($method === 'GET' && !$id) {
        $entityId = (int)($_GET['entity_id'] ?? 0);
        if ($entityId <= 0) {
            respond(['ok' => false, 'error' => 'entity_id required'], 400);
        }
        if (!social_can_view_entity_feed($user, $entityId)) {
            respond(['ok' => false, 'error' => 'Forbidden'], 403);
        }
        respond([
            'ok' => true,
            'data' => social_fetch_feed($entityId, 'entity', $user),
            'meta' => ['permissions' => social_feed_permissions('entity', $entityId, $user)]
        ]);
    }

    if ($method === 'POST' && !$id) {
        $data = social_read_request_data();
        $uploadedImages = social_uploaded_image_files();
        $feedScope = (($data['feed_scope'] ?? '') === 'global' || !empty($data['global'])) ? 'global' : 'entity';
        $entityId = null;
        if ($feedScope === 'global') {
            if (!social_can_post_global_feed($user)) {
                respond(['ok' => false, 'error' => 'Forbidden'], 403);
            }
        } else {
            $entityId = (int)($data['entity_id'] ?? 0);
            if ($entityId <= 0) {
                respond(['ok' => false, 'error' => 'entity_id required'], 400);
            }
            if (!social_can_post_entity_feed($user, $entityId)) {
                respond(['ok' => false, 'error' => 'Forbidden'], 403);
            }
        }
        $content = require_non_empty($data['content'] ?? '', 'content', 4000);
        $endeavourId = social_validate_endeavour_id($data['endeavour_id'] ?? null, $entityId);
        social_validate_mentions($data, $feedScope);

        $pdo = db();
        $imageCleanup = ['delete_after_commit' => [], 'delete_on_rollback' => []];
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO social_posts (endeavour_id, entity_id, feed_scope, user_id, content) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$endeavourId, $entityId, $feedScope, $user['id'], $content]);
            $postId = (int)$pdo->lastInsertId();
            $imageCleanup = social_sync_post_images($postId, $data, $user, $entityId, $uploadedImages);
            social_sync_mentions($postId, null, $data, $feedScope);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            social_cleanup_storage_paths($imageCleanup['delete_on_rollback'] ?? []);
                social_upload_log('handle_social.create_failed', [
                    'uploaded_count' => count($uploadedImages),
                    'feed_scope' => $feedScope,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            throw $e;
        }
        social_cleanup_storage_paths($imageCleanup['delete_after_commit'] ?? []);
        log_activity($user['id'], 'social_post', $postId, 'created', $feedScope === 'global' ? 'Global social post created' : 'Social post created');
        emit_ws_event('social.created', ['id' => $postId, 'feed_scope' => $feedScope]);
        $detail = social_fetch_post_detail($postId, $user);
        respond(['ok' => true, 'data' => [
            'id' => $postId,
            'post' => $detail['post'] ?? null,
            'comments' => $detail['comments'] ?? [],
        ]]);
    }

    if ($method === 'POST' && $id && $action === 'comments') {
        $data = read_json();
        $post = social_fetch_post((int)$id);
        if (!$post) {
            respond(['ok' => false, 'error' => 'Post not found'], 404);
        }
        social_require_post_interaction($post, $user);
        $comment = require_non_empty($data['comment'] ?? '', 'comment', 1500);
        $stmt = db()->prepare('INSERT INTO social_comments (post_id, user_id, comment) VALUES (?, ?, ?)');
        $stmt->execute([(int)$post['id'], $user['id'], $comment]);
        $commentId = (int)db()->lastInsertId();
        social_sync_mentions((int)$post['id'], $commentId, $data, $post['feed_scope'] ?? 'entity');
        log_activity($user['id'], 'social_post', (int)$post['id'], 'commented', 'Social comment added');
        emit_ws_event('social.commented', ['id' => (int)$post['id']]);
        respond(['ok' => true, 'data' => [
            'id' => $commentId,
            'comment' => social_fetch_comment_detail($commentId, $user),
        ]]);
    }

    if ($method === 'POST' && $id && $action === 'like') {
        $post = social_fetch_post((int)$id);
        if (!$post) {
            respond(['ok' => false, 'error' => 'Post not found'], 404);
        }
        social_require_post_interaction($post, $user);
        $liked = social_toggle_like('post', (int)$post['id'], (int)$user['id']);
        respond(['ok' => true, 'data' => ['liked' => $liked, 'likes_count' => social_like_count_target('post', (int)$post['id'])]]);
    }

    if ($method === 'POST' && $id === 'comments' && $action && ($segments[3] ?? '') === 'like') {
        $comment = social_fetch_comment((int)$action);
        if (!$comment) {
            respond(['ok' => false, 'error' => 'Comment not found'], 404);
        }
        social_require_post_interaction($comment, $user);
        $liked = social_toggle_like('comment', (int)$comment['id'], (int)$user['id']);
        respond(['ok' => true, 'data' => ['liked' => $liked, 'likes_count' => social_like_count_target('comment', (int)$comment['id'])]]);
    }

    if (($method === 'PUT' && $id && $action === '') || ($method === 'POST' && $id && $action === 'update')) {
        $post = social_fetch_post((int)$id);
        if (!$post) {
            respond(['ok' => false, 'error' => 'Post not found'], 404);
        }
        if (!social_user_can_manage_record($user, $post, (int)$post['user_id'])) {
            respond(['ok' => false, 'error' => 'Forbidden'], 403);
        }
        $data = social_read_request_data();
        $uploadedImages = social_uploaded_image_files();
        $content = require_non_empty($data['content'] ?? $post['content'], 'content', 4000);
        social_validate_mentions($data, $post['feed_scope'] ?? 'entity');
        $pdo = db();
        $imageCleanup = ['delete_after_commit' => [], 'delete_on_rollback' => []];
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE social_posts SET content = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            $stmt->execute([$content, (int)$post['id']]);
            $imageCleanup = social_sync_post_images((int)$post['id'], $data, $user, $post['entity_id'] ? (int)$post['entity_id'] : null, $uploadedImages);
            social_sync_mentions((int)$post['id'], null, $data, $post['feed_scope'] ?? 'entity');
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            social_cleanup_storage_paths($imageCleanup['delete_on_rollback'] ?? []);
            social_upload_log('handle_social.update_failed', [
                'post_id' => (int)$post['id'],
                'uploaded_count' => count($uploadedImages),
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
        social_cleanup_storage_paths($imageCleanup['delete_after_commit'] ?? []);
        log_activity($user['id'], 'social_post', (int)$post['id'], 'updated', 'Social post updated');
        emit_ws_event('social.updated', ['id' => (int)$post['id']]);
        $detail = social_fetch_post_detail((int)$post['id'], $user);
        respond(['ok' => true, 'data' => [
            'post' => $detail['post'] ?? null,
            'comments' => $detail['comments'] ?? [],
        ]]);
    }

    if ($method === 'PUT' && $id === 'comments' && $action) {
        $comment = social_fetch_comment((int)$action);
        if (!$comment) {
            respond(['ok' => false, 'error' => 'Comment not found'], 404);
        }
        if (!social_user_can_manage_record($user, $comment, (int)$comment['user_id'])) {
            respond(['ok' => false, 'error' => 'Forbidden'], 403);
        }
        $data = read_json();
        $content = require_non_empty($data['comment'] ?? $comment['comment'], 'comment', 1500);
        $stmt = db()->prepare('UPDATE social_comments SET comment = ? WHERE id = ?');
        $stmt->execute([$content, (int)$comment['id']]);
        social_sync_mentions((int)$comment['post_id'], (int)$comment['id'], $data, $comment['feed_scope'] ?? 'entity');
        log_activity($user['id'], 'social_post', (int)$comment['post_id'], 'comment_updated', 'Social comment updated');
        emit_ws_event('social.comment_updated', ['post_id' => (int)$comment['post_id']]);
        respond(['ok' => true, 'data' => [
            'comment' => social_fetch_comment_detail((int)$comment['id'], $user),
        ]]);
    }

    if ($method === 'DELETE' && $id === 'comments' && $action) {
        $comment = social_fetch_comment((int)$action);
        if (!$comment) {
            respond(['ok' => false, 'error' => 'Comment not found'], 404);
        }
        if (!social_user_can_manage_record($user, $comment, (int)$comment['user_id'])) {
            respond(['ok' => false, 'error' => 'Forbidden'], 403);
        }
        $postId = (int)$comment['post_id'];
        db()->prepare('DELETE FROM social_comments WHERE id = ?')->execute([(int)$comment['id']]);
        log_activity($user['id'], 'social_post', $postId, 'comment_deleted', 'Social comment deleted');
        emit_ws_event('social.comment_deleted', ['post_id' => $postId]);
        respond(['ok' => true, 'data' => [
            'id' => (int)$comment['id'],
            'post_id' => $postId,
            'comments_count' => social_comment_count_for_post($postId),
        ]]);
    }

    if ($method === 'DELETE' && $id && $action === '') {
        $post = social_fetch_post((int)$id);
        if (!$post) {
            respond(['ok' => false, 'error' => 'Post not found'], 404);
        }
        if (!social_user_can_manage_record($user, $post, (int)$post['user_id'])) {
            respond(['ok' => false, 'error' => 'Forbidden'], 403);
        }
        $storagePaths = social_storage_paths_for_post((int)$post['id']);
        $deleteStmt = db()->prepare('DELETE FROM social_posts WHERE id = ?');
        $deleteStmt->execute([(int)$post['id']]);
        if ($deleteStmt->rowCount() > 0) {
            foreach ($storagePaths as $path) {
                delete_uploaded_relative_path($path);
            }
            log_activity($user['id'], 'social_post', (int)$post['id'], 'deleted', 'Social post deleted');
            emit_ws_event('social.deleted', ['id' => (int)$post['id']]);
        }
        respond(['ok' => true, 'data' => ['id' => (int)$post['id']]]);
    }

    respond(['ok' => false, 'error' => 'Not Found'], 404);
}

function social_read_request_data(): array {
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
    if (!str_contains($contentType, 'multipart/form-data')) {
        return read_json();
    }
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMaxBytes = upload_ini_bytes((string)ini_get('post_max_size'));
    if ($contentLength > 0 && $postMaxBytes > 0 && $contentLength > $postMaxBytes && empty($_POST) && empty($_FILES)) {
        social_upload_log('social_read_request_data.post_max_exceeded', [
            'content_length' => $contentLength,
            'post_max_size' => $postMaxBytes,
        ]);
        respond(['ok' => false, 'error' => 'Image is too large.'], 400);
    }

    $data = $_POST;
    foreach (['image_urls', 'image_file_ids', 'keep_image_ids', 'mentioned_user_ids', 'mentioned_entity_ids'] as $field) {
        if (array_key_exists($field, $data)) {
            $data[$field] = social_request_array($data[$field]);
        }
    }
    return $data;
}

function social_request_array($value): array {
    if (is_array($value)) {
        return array_values($value);
    }
    $value = trim((string)$value);
    if ($value === '') {
        return [];
    }
    return array_values(array_filter(array_map('trim', explode(',', $value)), static fn($item) => $item !== ''));
}

function social_uploaded_image_files(): array {
    // No files at all: normal text-only posts
    if (empty($_FILES)) {
        return [];
    }

    // If client used the expected field name, use it
    if (isset($_FILES['images'])) {
        $raw = $_FILES['images'];
    } else {
        // Look for predictable alternative field names: image(s), file(s), upload(s)
        $foundKey = null;
        $pattern = '/^(?:images?|files?|uploads?)(?:\[\])?$/i';
        foreach (array_keys($_FILES) as $k) {
            if (preg_match($pattern, $k)) {
                $foundKey = $k;
                break;
            }
        }
        if ($foundKey === null) {
            // No recognized file fields present
            social_upload_log('social_uploaded_image_files.unrecognized_keys', ['available_files' => array_keys($_FILES)]);
            return [];
        }
        social_upload_log('social_uploaded_image_files.found_alternative', ['key' => $foundKey]);
        $raw = $_FILES[$foundKey];
    }
    $files = [];
    $uploadErrors = [];
    if (is_array($raw['name'] ?? null)) {
        foreach ($raw['name'] as $index => $name) {
            $file = [
                'name' => $name,
                'type' => $raw['type'][$index] ?? '',
                'tmp_name' => $raw['tmp_name'][$index] ?? '',
                'error' => $raw['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $raw['size'][$index] ?? 0,
            ];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $uploadErrors[] = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
            $files[] = $file;
        }
    } else {
        if (($raw['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $uploadErrors[] = (int)($raw['error'] ?? UPLOAD_ERR_NO_FILE);
            $files[] = $raw;
        }
    }

    $limits = social_image_upload_limits();
    social_upload_log('social_uploaded_image_files.collected', [
        'count' => count($files),
        'errors' => $uploadErrors,
        'max_files' => $limits['max_files'],
    ]);
    if (count($files) > $limits['max_files']) {
        respond(['ok' => false, 'error' => 'Posts may include up to 10 images'], 400);
    }
    foreach ($files as $file) {
        validate_social_image_upload($file);
    }
    return $files;
}

function social_feed_permissions(string $scope, ?int $entityId, ?array $user): array {
    if ($scope === 'global') {
        $canInteract = social_can_interact_global_feed($user);
        return [
            'scope' => 'global',
            'can_view' => social_can_view_global_feed($user),
            'can_post' => social_can_post_global_feed($user),
            'can_interact' => $canInteract,
            'can_like' => $canInteract,
            'can_comment' => $canInteract,
            'authenticated' => (bool)$user,
        ];
    }

    $entityId = (int)$entityId;
    $canInteract = social_can_interact_entity_feed($user, $entityId);
    return [
        'scope' => 'entity',
        'entity_id' => $entityId,
        'can_view' => social_can_view_entity_feed($user, $entityId),
        'can_post' => social_can_post_entity_feed($user, $entityId),
        'can_interact' => $canInteract,
        'can_like' => $canInteract,
        'can_comment' => $canInteract,
        'authenticated' => (bool)$user,
    ];
}

function social_fetch_feed(?int $entityId, string $scope, ?array $user): array {
    if ($scope === 'global') {
        $stmt = db()->prepare(
            'SELECT sp.*, u.full_name, e.name AS entity_name
             FROM social_posts sp
             JOIN users u ON u.id = sp.user_id
             LEFT JOIN entities e ON e.id = sp.entity_id
             WHERE sp.feed_scope = "global"
             ORDER BY sp.created_at DESC
             LIMIT 80'
        );
        $stmt->execute();
    } else {
        $stmt = db()->prepare(
            'SELECT sp.*, u.full_name, e.name AS entity_name
             FROM social_posts sp
             JOIN users u ON u.id = sp.user_id
             JOIN entities e ON e.id = sp.entity_id
             WHERE sp.entity_id = ? AND COALESCE(sp.feed_scope, "entity") = "entity"
             ORDER BY sp.created_at DESC
             LIMIT 80'
        );
        $stmt->execute([$entityId]);
    }
    return social_hydrate_posts($stmt->fetchAll(), $user);
}

function social_fetch_post_detail(int $postId, ?array $user): ?array {
    $post = social_fetch_post($postId);
    if (!$post) {
        return null;
    }
    $payload = social_hydrate_posts([$post], $user);
    if (empty($payload['posts'])) {
        return null;
    }
    return [
        'post' => $payload['posts'][0],
        'comments' => $payload['comments'],
    ];
}

function social_hydrate_posts(array $posts, ?array $user): array {
    $postIds = array_map(fn($row) => (int)$row['id'], $posts);
    $comments = social_comments_for_posts($postIds, $user);
    $images = social_images_for_posts($postIds);
    $mentions = social_mentions_for_posts($postIds);
    $likeCounts = social_like_counts('post', $postIds);
    $liked = $user ? social_liked_targets('post', $postIds, (int)$user['id']) : [];
    $commentIds = array_map(fn($row) => (int)$row['id'], $comments);
    $commentLikeCounts = social_like_counts('comment', $commentIds);
    $commentLiked = $user ? social_liked_targets('comment', $commentIds, (int)$user['id']) : [];

    $posts = array_map(static function ($post) use ($user, $images, $mentions, $likeCounts, $liked) {
        $postId = (int)$post['id'];
        $post['safe_html'] = social_render_markdown($post['content']);
        $post['images'] = $images[$postId] ?? [];
        $post['mentions'] = $mentions[$postId] ?? [];
        $post['likes_count'] = $likeCounts[$postId] ?? 0;
        $post['liked_by_me'] = isset($liked[$postId]);
        $post['can_interact'] = social_record_allows_interaction($post, $user);
        $post['can_like'] = $post['can_interact'];
        $post['can_comment'] = $post['can_interact'];
        $post['can_manage'] = $user ? social_user_can_manage_record($user, $post, (int)$post['user_id']) : false;
        if (!$user && ($post['feed_scope'] ?? '') === 'global') {
            unset($post['user_id'], $post['entity_id'], $post['endeavour_id']);
        }
        return $post;
    }, $posts);

    $comments = array_map(static function ($comment) use ($user, $commentLikeCounts, $commentLiked) {
        $commentId = (int)$comment['id'];
        $comment['safe_html'] = social_render_markdown($comment['comment']);
        $comment['likes_count'] = $commentLikeCounts[$commentId] ?? 0;
        $comment['liked_by_me'] = isset($commentLiked[$commentId]);
        $comment['can_interact'] = social_record_allows_interaction($comment, $user);
        $comment['can_like'] = $comment['can_interact'];
        $comment['can_manage'] = $user ? social_user_can_manage_record($user, $comment, (int)$comment['user_id']) : false;
        if (!$user) {
            unset($comment['user_id'], $comment['entity_id']);
        }
        return $comment;
    }, $comments);

    return ['posts' => $posts, 'comments' => $comments];
}

function social_fetch_post(int $postId): ?array {
    $stmt = db()->prepare('SELECT sp.*, u.full_name, e.name AS entity_name FROM social_posts sp JOIN users u ON u.id = sp.user_id LEFT JOIN entities e ON e.id = sp.entity_id WHERE sp.id = ?');
    $stmt->execute([$postId]);
    $post = $stmt->fetch();
    return $post ?: null;
}

function social_fetch_comment(int $commentId): ?array {
    $stmt = db()->prepare(
        'SELECT sc.*, sp.entity_id, sp.feed_scope, sp.user_id AS post_user_id
         FROM social_comments sc
         JOIN social_posts sp ON sp.id = sc.post_id
         WHERE sc.id = ?'
    );
    $stmt->execute([$commentId]);
    $comment = $stmt->fetch();
    return $comment ?: null;
}

function social_fetch_comment_detail(int $commentId, ?array $user): ?array {
    $stmt = db()->prepare(
        'SELECT sc.*, u.full_name, sp.entity_id, sp.feed_scope
         FROM social_comments sc
         JOIN users u ON u.id = sc.user_id
         JOIN social_posts sp ON sp.id = sc.post_id
         WHERE sc.id = ?'
    );
    $stmt->execute([$commentId]);
    $comment = $stmt->fetch();
    if (!$comment) {
        return null;
    }
    $commentId = (int)$comment['id'];
    $comment['safe_html'] = social_render_markdown($comment['comment']);
    $comment['likes_count'] = social_like_count_target('comment', $commentId);
    $comment['liked_by_me'] = $user ? isset(social_liked_targets('comment', [$commentId], (int)$user['id'])[$commentId]) : false;
    $comment['can_interact'] = social_record_allows_interaction($comment, $user);
    $comment['can_like'] = $comment['can_interact'];
    $comment['can_manage'] = $user ? social_user_can_manage_record($user, $comment, (int)$comment['user_id']) : false;
    if (!$user) {
        unset($comment['user_id'], $comment['entity_id']);
    }
    return $comment;
}

function social_record_allows_interaction(array $record, ?array $user): bool {
    if (!$user) {
        return false;
    }
    $scope = $record['feed_scope'] ?? 'entity';
    if ($scope === 'global') {
        return social_can_interact_global_feed($user);
    }
    $entityId = (int)($record['entity_id'] ?? 0);
    return $entityId > 0 && social_can_interact_entity_feed($user, $entityId);
}

function social_require_post_interaction(array $post, array $user): void {
    $scope = $post['feed_scope'] ?? 'entity';
    if ($scope !== 'global' && (int)($post['entity_id'] ?? 0) <= 0) {
        respond(['ok' => false, 'error' => 'Invalid post scope'], 400);
    }
    if (!social_record_allows_interaction($post, $user)) {
        respond(['ok' => false, 'error' => 'Forbidden'], 403);
    }
}

function social_user_can_manage_record(array $user, array $record, int $authorId): bool {
    $scope = $record['feed_scope'] ?? 'entity';
    if ($scope === 'global') {
        if ((int)$user['id'] === $authorId) {
            return true;
        }
        return can_permission($user, 'social.moderate');
    }
    $entityId = (int)($record['entity_id'] ?? 0);
    if ($entityId <= 0 || !social_can_view_entity_feed($user, $entityId)) {
        return false;
    }
    if ((int)$user['id'] === $authorId) {
        return true;
    }
    return can_permission($user, 'social.moderate', $entityId);
}

function social_validate_endeavour_id($raw, ?int $entityId): ?int {
    if ($raw === null || $raw === '') {
        return null;
    }
    if ($entityId === null) {
        respond(['ok' => false, 'error' => 'Endeavour links are only supported in entity feeds'], 400);
    }
    $endeavourId = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($endeavourId === false) {
        respond(['ok' => false, 'error' => 'Invalid endeavour_id'], 400);
    }
    $endeavourCheck = db()->prepare('SELECT id FROM endeavours WHERE id = ? AND entity_id = ?');
    $endeavourCheck->execute([$endeavourId, $entityId]);
    if (!$endeavourCheck->fetch()) {
        respond(['ok' => false, 'error' => 'Endeavour not found for entity'], 400);
    }
    return (int)$endeavourId;
}

function social_assert_post_images_schema_ready(): void {
    static $ready = null;
    if ($ready === true) {
        return;
    }

    $requiredColumns = [
        'id',
        'post_id',
        'file_drive_item_id',
        'image_url',
        'storage_path',
        'original_name',
        'mime_type',
        'size_bytes',
        'sort_order',
        'created_at',
    ];
    $placeholders = implode(',', array_fill(0, count($requiredColumns), '?'));
    $stmt = db()->prepare(
        "SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'social_post_images'
           AND COLUMN_NAME IN ({$placeholders})"
    );
    $stmt->execute($requiredColumns);
    $found = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
    $missing = array_values(array_filter($requiredColumns, static fn($column) => !isset($found[$column])));
    if ($missing) {
        social_upload_log('social_post_images.schema_missing_columns', ['missing_columns' => $missing]);
        throw new RuntimeException('social_post_images schema is missing required columns: ' . implode(', ', $missing));
    }
    $ready = true;
}

function social_sync_post_images(int $postId, array $data, array $user, ?int $entityId, array $uploadedFiles = []): array {
    $cleanup = ['delete_after_commit' => [], 'delete_on_rollback' => []];
    $hasImages = array_key_exists('image_urls', $data)
        || array_key_exists('image_file_ids', $data)
        || array_key_exists('keep_image_ids', $data)
        || count($uploadedFiles) > 0;
    social_upload_log('social_sync_post_images.start', [
        'post_id' => $postId,
        'has_images' => $hasImages,
        'uploaded_count' => count($uploadedFiles),
    ]);
    if (!$hasImages) {
        return $cleanup;
    }
    social_assert_post_images_schema_ready();
    $images = [];
    $keptStoragePaths = [];

    $keepIds = social_normalize_id_list($data['keep_image_ids'] ?? []);
    if ($keepIds) {
        $keptRows = social_existing_images($postId, $keepIds);
        if (count($keptRows) !== count($keepIds)) {
            respond(['ok' => false, 'error' => 'One or more images are no longer available'], 400);
        }
        foreach ($keptRows as $row) {
            $images[] = [
                'file_id' => $row['file_drive_item_id'] ? (int)$row['file_drive_item_id'] : null,
                'url' => $row['image_url'],
                'storage_path' => $row['storage_path'],
                'original_name' => $row['original_name'],
                'mime_type' => $row['mime_type'],
                'size_bytes' => (int)($row['size_bytes'] ?? 0),
            ];
            if (!empty($row['storage_path'])) {
                $keptStoragePaths[] = (string)$row['storage_path'];
            }
        }
    }

    $urls = is_array($data['image_urls'] ?? null) ? $data['image_urls'] : [];
    foreach ($urls as $url) {
        $url = trim((string)$url);
        if ($url === '') {
            continue;
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            respond(['ok' => false, 'error' => 'Invalid image URL'], 400);
        }
        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?: '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            respond(['ok' => false, 'error' => 'Images must use http or https URLs'], 400);
        }
        $images[] = [
            'file_id' => null,
            'url' => $url,
            'storage_path' => null,
            'original_name' => null,
            'mime_type' => null,
            'size_bytes' => 0,
        ];
    }
    $fileIds = is_array($data['image_file_ids'] ?? null) ? $data['image_file_ids'] : [];
    foreach ($fileIds as $fileId) {
        $fileId = (int)$fileId;
        if ($fileId <= 0) {
            continue;
        }
        $item = drive_get_item_by_id($fileId);
        if (!$item || ($item['item_type'] ?? '') !== 'file') {
            respond(['ok' => false, 'error' => 'Image file not found'], 400);
        }
        if ($entityId !== null && (int)$item['entity_id'] !== $entityId) {
            respond(['ok' => false, 'error' => 'Image file must belong to the same entity'], 400);
        }
        drive_assert_can_view_item($user, $item);
        $images[] = [
            'file_id' => $fileId,
            'url' => null,
            'storage_path' => null,
            'original_name' => null,
            'mime_type' => null,
            'size_bytes' => 0,
        ];
    }
    if (count($images) + count($uploadedFiles) > 10) {
        respond(['ok' => false, 'error' => 'Posts may include up to 10 images'], 400);
    }

    $oldStoragePaths = social_storage_paths_for_post($postId);
    $newStoragePaths = [];

    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        foreach ($uploadedFiles as $file) {
            $uploaded = save_social_image_file($file);
            $images[] = [
                'file_id' => null,
                'url' => null,
                'storage_path' => $uploaded['path'],
                'original_name' => $uploaded['original'],
                'mime_type' => $uploaded['mime'],
                'size_bytes' => (int)$uploaded['size'],
            ];
            $newStoragePaths[] = $uploaded['path'];
        }
        $cleanup['delete_on_rollback'] = $newStoragePaths;

        $pdo->prepare('DELETE FROM social_post_images WHERE post_id = ?')->execute([$postId]);
        $stmt = $pdo->prepare(
            'INSERT INTO social_post_images (post_id, file_drive_item_id, image_url, storage_path, original_name, mime_type, size_bytes, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        social_upload_log('social_sync_post_images.insert_start', [
            'post_id' => $postId,
            'image_count' => count($images),
            'uploaded_count' => count($uploadedFiles),
        ]);
        foreach (array_values($images) as $index => $image) {
            $stmt->execute([
                $postId,
                $image['file_id'],
                $image['url'],
                $image['storage_path'],
                $image['original_name'],
                $image['mime_type'],
                $image['size_bytes'],
                $index
            ]);
        }
        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (\Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        foreach ($newStoragePaths as $path) {
            delete_uploaded_relative_path($path);
        }
        social_upload_log('social_sync_post_images.failed', [
            'post_id' => $postId,
            'uploaded_count' => count($uploadedFiles),
            'exception' => get_class($e),
            'message' => $e->getMessage(),
        ]);
        throw $e;
    }

    $staleStoragePaths = [];
    foreach ($oldStoragePaths as $path) {
        if (!in_array($path, $keptStoragePaths, true)) {
            $staleStoragePaths[] = $path;
        }
    }
    if ($ownsTransaction) {
        social_cleanup_storage_paths($staleStoragePaths);
        $staleStoragePaths = [];
    }
    social_upload_log('social_sync_post_images.ok', [
        'post_id' => $postId,
        'image_count' => count($images),
        'delete_after_commit_count' => count($staleStoragePaths),
    ]);
    $cleanup['delete_after_commit'] = $staleStoragePaths;
    return $cleanup;
}

function social_existing_images(int $postId, array $imageIds): array {
    $imageIds = array_values(array_unique(array_filter(array_map('intval', $imageIds))));
    if (!$imageIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($imageIds), '?'));
    $stmt = db()->prepare("SELECT * FROM social_post_images WHERE post_id = ? AND id IN ({$in}) ORDER BY sort_order, id");
    $stmt->execute(array_merge([$postId], $imageIds));
    return $stmt->fetchAll();
}

function social_storage_paths_for_post(int $postId): array {
    $stmt = db()->prepare('SELECT storage_path FROM social_post_images WHERE post_id = ? AND storage_path IS NOT NULL AND storage_path <> ""');
    $stmt->execute([$postId]);
    return array_values(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
}

function social_cleanup_storage_paths(array $paths): void {
    foreach (array_values(array_unique(array_filter(array_map('strval', $paths)))) as $path) {
        delete_uploaded_relative_path($path);
    }
}


function social_validate_mentions(array $data, string $scope): void {
    $hasMentions = array_key_exists('mentioned_user_ids', $data) || array_key_exists('mentioned_entity_ids', $data);
    if (!$hasMentions) {
        return;
    }
    $userIds = social_normalize_id_list($data['mentioned_user_ids'] ?? []);
    $entityIds = social_normalize_id_list($data['mentioned_entity_ids'] ?? []);
    if ($entityIds && $scope !== 'global') {
        respond(['ok' => false, 'error' => 'Entity mentions are only allowed in the global feed'], 400);
    }
    social_validate_ids_exist('users', $userIds, 'mentioned_user_ids');
    social_validate_ids_exist('entities', $entityIds, 'mentioned_entity_ids');
}

function social_sync_mentions(int $postId, ?int $commentId, array $data, string $scope): void {
    $hasMentions = array_key_exists('mentioned_user_ids', $data) || array_key_exists('mentioned_entity_ids', $data);
    if (!$hasMentions) {
        return;
    }
    social_validate_mentions($data, $scope);
    if ($commentId !== null) {
        db()->prepare('DELETE FROM social_mentions WHERE comment_id = ?')->execute([$commentId]);
    } else {
        db()->prepare('DELETE FROM social_mentions WHERE post_id = ? AND comment_id IS NULL')->execute([$postId]);
    }

    $userIds = social_normalize_id_list($data['mentioned_user_ids'] ?? []);
    $entityIds = social_normalize_id_list($data['mentioned_entity_ids'] ?? []);

    $stmt = db()->prepare('INSERT INTO social_mentions (post_id, comment_id, mentioned_user_id, mentioned_entity_id) VALUES (?, ?, ?, ?)');
    foreach ($userIds as $userId) {
        $stmt->execute([$postId, $commentId, $userId, null]);
    }
    foreach ($entityIds as $entityId) {
        $stmt->execute([$postId, $commentId, null, $entityId]);
    }
}

function social_normalize_id_list($raw): array {
    if (!is_array($raw)) {
        return [];
    }
    $ids = [];
    foreach ($raw as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    return array_values(array_unique($ids));
}

function social_validate_ids_exist(string $table, array $ids, string $field): void {
    if (!$ids) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT id FROM {$table} WHERE id IN ({$placeholders})");
    $stmt->execute($ids);
    $found = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (count($found) !== count($ids)) {
        respond(['ok' => false, 'error' => "Invalid {$field}"], 400);
    }
}

function social_comments_for_posts(array $postIds, ?array $user): array {
    if (!$postIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($postIds), '?'));
    $stmt = db()->prepare(
        "SELECT sc.*, u.full_name, sp.entity_id, sp.feed_scope
         FROM social_comments sc
         JOIN users u ON u.id = sc.user_id
         JOIN social_posts sp ON sp.id = sc.post_id
         WHERE sc.post_id IN ({$in})
         ORDER BY sc.created_at ASC"
    );
    $stmt->execute($postIds);
    return $stmt->fetchAll();
}

function social_images_for_posts(array $postIds): array {
    $map = [];
    foreach ($postIds as $id) {
        $map[(int)$id] = [];
    }
    if (!$postIds) {
        return $map;
    }
    $in = implode(',', array_fill(0, count($postIds), '?'));
    $stmt = db()->prepare(
        "SELECT spi.*, f.name AS file_name
         FROM social_post_images spi
         LEFT JOIN file_drive_items f ON f.id = spi.file_drive_item_id
         WHERE spi.post_id IN ({$in})
         ORDER BY spi.sort_order, spi.id"
    );
    $stmt->execute($postIds);
    foreach ($stmt->fetchAll() as $row) {
        $postId = (int)$row['post_id'];
        $url = $row['image_url'];
        if (!$url && !empty($row['storage_path'])) {
            $url = '/api/files/download?type=social_image&id=' . urlencode((string)$row['id']);
        } elseif (!$url && $row['file_drive_item_id']) {
            $url = '/api/files/download?type=drive&id=' . urlencode((string)$row['file_drive_item_id']);
        }
        $map[$postId][] = [
            'id' => (int)$row['id'],
            'url' => $url,
            'file_drive_item_id' => $row['file_drive_item_id'] ? (int)$row['file_drive_item_id'] : null,
            'name' => $row['original_name'] ?: $row['file_name'],
            'mime_type' => $row['mime_type'],
            'size_bytes' => (int)($row['size_bytes'] ?? 0),
        ];
    }
    return $map;
}

function social_mentions_for_posts(array $postIds): array {
    $map = [];
    foreach ($postIds as $id) {
        $map[(int)$id] = [];
    }
    if (!$postIds) {
        return $map;
    }
    $in = implode(',', array_fill(0, count($postIds), '?'));
    $stmt = db()->prepare(
        "SELECT sm.*, u.full_name AS user_name, e.name AS entity_name
         FROM social_mentions sm
         LEFT JOIN users u ON u.id = sm.mentioned_user_id
         LEFT JOIN entities e ON e.id = sm.mentioned_entity_id
         WHERE sm.post_id IN ({$in})
         ORDER BY sm.id"
    );
    $stmt->execute($postIds);
    foreach ($stmt->fetchAll() as $row) {
        $postId = (int)$row['post_id'];
        $map[$postId][] = [
            'user_id' => $row['mentioned_user_id'] ? (int)$row['mentioned_user_id'] : null,
            'user_name' => $row['user_name'],
            'entity_id' => $row['mentioned_entity_id'] ? (int)$row['mentioned_entity_id'] : null,
            'entity_name' => $row['entity_name'],
            'comment_id' => $row['comment_id'] ? (int)$row['comment_id'] : null,
        ];
    }
    return $map;
}

function social_like_counts(string $targetType, array $targetIds): array {
    $targetIds = array_values(array_unique(array_filter(array_map('intval', $targetIds))));
    if (!$targetIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($targetIds), '?'));
    $params = array_merge([$targetType], $targetIds);
    $stmt = db()->prepare("SELECT target_id, COUNT(*) AS total FROM social_likes WHERE target_type = ? AND target_id IN ({$in}) GROUP BY target_id");
    $stmt->execute($params);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int)$row['target_id']] = (int)$row['total'];
    }
    return $map;
}

function social_like_count_target(string $targetType, int $targetId): int {
    if ($targetId <= 0) {
        return 0;
    }
    $stmt = db()->prepare('SELECT COUNT(*) FROM social_likes WHERE target_type = ? AND target_id = ?');
    $stmt->execute([$targetType, $targetId]);
    return (int)$stmt->fetchColumn();
}

function social_comment_count_for_post(int $postId): int {
    if ($postId <= 0) {
        return 0;
    }
    $stmt = db()->prepare('SELECT COUNT(*) FROM social_comments WHERE post_id = ?');
    $stmt->execute([$postId]);
    return (int)$stmt->fetchColumn();
}

function social_liked_targets(string $targetType, array $targetIds, int $userId): array {
    $targetIds = array_values(array_unique(array_filter(array_map('intval', $targetIds))));
    if (!$targetIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($targetIds), '?'));
    $params = array_merge([$userId, $targetType], $targetIds);
    $stmt = db()->prepare("SELECT target_id FROM social_likes WHERE user_id = ? AND target_type = ? AND target_id IN ({$in})");
    $stmt->execute($params);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $map[(int)$id] = true;
    }
    return $map;
}

function social_toggle_like(string $targetType, int $targetId, int $userId): bool {
    $check = db()->prepare('SELECT id FROM social_likes WHERE user_id = ? AND target_type = ? AND target_id = ?');
    $check->execute([$userId, $targetType, $targetId]);
    $id = $check->fetchColumn();
    if ($id) {
        db()->prepare('DELETE FROM social_likes WHERE id = ?')->execute([(int)$id]);
        return false;
    }
    db()->prepare('INSERT INTO social_likes (user_id, target_type, target_id) VALUES (?, ?, ?)')->execute([$userId, $targetType, $targetId]);
    return true;
}

function social_render_markdown(string $content): string {
    $escaped = htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $escaped = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $escaped);
    $escaped = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $escaped);
    $escaped = preg_replace_callback('/\bhttps?:\/\/[^\s<]+/i', static function ($matches) {
        $url = $matches[0];
        $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<a href="' . $safeUrl . '" target="_blank" rel="noopener noreferrer">' . $safeUrl . '</a>';
    }, $escaped);
    return nl2br($escaped);
}
