<?php
function handle_social(string $method, array $segments): void {
    $user = require_auth();
    $id = $segments[1] ?? null;
    $action = $segments[2] ?? '';

    if ($method === 'GET' && !$id) {
        $entityId = (int)($_GET['entity_id'] ?? 0);
        if ($entityId <= 0) {
            respond(['ok' => false, 'error' => 'entity_id required'], 400);
        }
        ensure_entity_access($entityId, []);
        $stmt = db()->prepare('SELECT sp.*, u.full_name FROM social_posts sp JOIN users u ON sp.user_id = u.id WHERE sp.entity_id = ? ORDER BY sp.created_at DESC LIMIT 50');
        $stmt->execute([$entityId]);
        $posts = $stmt->fetchAll();
        $postIds = array_map(fn($row) => (int)$row['id'], $posts);
        $comments = [];
        if ($postIds) {
            $in = implode(',', array_fill(0, count($postIds), '?'));
            $commentStmt = db()->prepare("SELECT sc.*, u.full_name FROM social_comments sc JOIN users u ON sc.user_id = u.id WHERE sc.post_id IN ({$in}) ORDER BY sc.created_at ASC");
            $commentStmt->execute($postIds);
            $comments = $commentStmt->fetchAll();
        }
        respond(['ok' => true, 'data' => ['posts' => $posts, 'comments' => $comments]]);
    }

    if ($method === 'POST' && !$id) {
        $data = read_json();
        $entityId = (int)($data['entity_id'] ?? 0);
        if ($entityId <= 0) {
            respond(['ok' => false, 'error' => 'entity_id required'], 400);
        }
        ensure_entity_access($entityId, []);
        $content = require_non_empty($data['content'] ?? '', 'content', 2000);
        $stmt = db()->prepare('INSERT INTO social_posts (endeavour_id, entity_id, user_id, content) VALUES (?, ?, ?, ?)');
        $stmt->execute([
            $data['endeavour_id'] ?? null,
            $entityId,
            $user['id'],
            $content
        ]);
        $postId = (int)db()->lastInsertId();
        log_activity($user['id'], 'social_post', $postId, 'created', 'Social post created');
        emit_ws_event('social.created', ['id' => $postId]);
        respond(['ok' => true, 'data' => ['id' => $postId]]);
    }

    if ($method === 'POST' && $id && $action === 'comments') {
        $data = read_json();
        $postId = (int)$id;
        $check = db()->prepare('SELECT entity_id FROM social_posts WHERE id = ?');
        $check->execute([$postId]);
        $post = $check->fetch();
        if (!$post) {
            respond(['ok' => false, 'error' => 'Post not found'], 404);
        }
        ensure_entity_access((int)$post['entity_id'], []);
        $comment = require_non_empty($data['comment'] ?? '', 'comment', 1000);
        $stmt = db()->prepare('INSERT INTO social_comments (post_id, user_id, comment) VALUES (?, ?, ?)');
        $stmt->execute([$postId, $user['id'], $comment]);
        $commentId = (int)db()->lastInsertId();
        log_activity($user['id'], 'social_post', $postId, 'commented', 'Social comment added');
        emit_ws_event('social.commented', ['id' => $postId]);
        respond(['ok' => true, 'data' => ['id' => $commentId]]);
    }

    if ($method === 'DELETE' && $id) {
        $postId = (int)$id;
        $check = db()->prepare('SELECT * FROM social_posts WHERE id = ?');
        $check->execute([$postId]);
        $post = $check->fetch();
        if (!$post) {
            respond(['ok' => false, 'error' => 'Post not found'], 404);
        }
        ensure_entity_access((int)$post['entity_id'], []);
        if ($user['global_role'] !== 'admin' && (int)$post['user_id'] !== (int)$user['id']) {
            respond(['ok' => false, 'error' => 'Forbidden'], 403);
        }
        $del = db()->prepare('DELETE FROM social_posts WHERE id = ?');
        $del->execute([$postId]);
        log_activity($user['id'], 'social_post', $postId, 'deleted', 'Social post deleted');
        emit_ws_event('social.deleted', ['id' => $postId]);
        respond(['ok' => true]);
    }

    respond(['ok' => false, 'error' => 'Not Found'], 404);
}
