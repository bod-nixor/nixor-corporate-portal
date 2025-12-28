<?php
function handle_public(string $method, array $segments): void {
    $action = $segments[1] ?? '';

    if ($action === 'volunteer_posts' && $method === 'GET') {
        $stmt = db()->query('SELECT vp.*, e.name AS endeavour_name, en.name AS entity_name FROM volunteer_posts vp JOIN endeavours e ON vp.endeavour_id = e.id JOIN entities en ON e.entity_id = en.id WHERE vp.published = 1 ORDER BY vp.published_at DESC LIMIT 20');
        respond(['ok' => true, 'data' => $stmt->fetchAll()]);
    }

    if ($action === 'interest' && $method === 'POST') {
        if (!rate_limit('interest', 10, 600)) {
            respond(['ok' => false, 'error' => 'Too many requests'], 429);
        }
        $data = read_json();
        $name = require_non_empty($data['name'] ?? '', 'name', 190);
        $email = validate_email_address($data['email'] ?? '', 'email');
        $phone = sanitize_text($data['phone'] ?? '', 60);
        $message = sanitize_text($data['message'] ?? '', 1000);
        $stmt = db()->prepare('INSERT INTO interest_submissions (name, email, phone, message) VALUES (?, ?, ?, ?)');
        $stmt->execute([$name, $email, $phone, $message]);
        respond(['ok' => true, 'data' => ['id' => (int)db()->lastInsertId()]]);
    }

    respond(['ok' => false, 'error' => 'Not Found'], 404);
}
