<?php
function handle_public(string $method, array $segments): void {
    $action = $segments[1] ?? '';

    if ($action === 'volunteer_posts' && $method === 'GET') {
        $stmt = db()->query('SELECT vp.*, e.public_id AS endeavour_public_id, e.name AS endeavour_name, en.public_id AS entity_public_id, en.name AS entity_name FROM volunteer_posts vp JOIN endeavours e ON vp.endeavour_id = e.id JOIN entities en ON e.entity_id = en.id WHERE vp.published = 1 ORDER BY vp.published_at DESC LIMIT 20');
        respond(['ok' => true, 'data' => $stmt->fetchAll()]);
    }

    if ($action === 'volunteer_detail' && $method === 'GET') {
        if (!rate_limit('volunteer_detail', 60, 600)) {
            respond(['ok' => false, 'error' => 'Too many requests'], 429);
        }
        $rawEndeavourId = $_GET['e'] ?? ($_GET['endeavour_public_id'] ?? ($_GET['endeavour_id'] ?? ($_GET['id'] ?? '')));
        $endeavourId = resolve_public_or_internal_id('endeavours', $rawEndeavourId);
        if (!$endeavourId) {
            respond(['ok' => false, 'error' => 'endeavour_id required'], 400);
        }
        $stmt = db()->prepare(
            'SELECT e.id, e.public_id, e.name, e.title, e.description, e.long_description, e.start_at, e.end_at,
                    e.event_start_at, e.event_end_at, e.venue, e.volunteering_enabled,
                    e.volunteer_signup_deadline, e.transport_fee_enabled, e.transport_fee_amount,
                    en.public_id AS entity_public_id, en.name AS entity_name
             FROM endeavours e
             JOIN entities en ON en.id = e.entity_id
             WHERE e.id = ? AND e.volunteering_enabled = 1'
        );
        $stmt->execute([$endeavourId]);
        $row = $stmt->fetch();
        if (!$row) {
            respond(['ok' => false, 'error' => 'Volunteer opportunity not found'], 404);
        }
        respond(['ok' => true, 'data' => [
            'id' => (int)$row['id'],
            'public_id' => $row['public_id'] ?: public_id_for_row('endeavours', (int)$row['id']),
            'entity_public_id' => $row['entity_public_id'] ?: null,
            'title' => $row['title'] ?: $row['name'],
            'description' => $row['description'],
            'long_description' => $row['long_description'],
            'entity_name' => $row['entity_name'],
            'venue' => $row['venue'],
            'start_at' => $row['event_start_at'] ?: $row['start_at'],
            'end_at' => $row['event_end_at'] ?: $row['end_at'],
            'volunteer_signup_deadline' => $row['volunteer_signup_deadline'],
            'transport_fee_enabled' => (bool)$row['transport_fee_enabled'],
            'transport_fee_amount' => $row['transport_fee_amount'],
        ]]);
    }

    if ($action === 'social_global' && $method === 'GET') {
        if (!rate_limit('social_global', 120, 600)) {
            respond(['ok' => false, 'error' => 'Too many requests'], 429);
        }
        require_once __DIR__ . '/social.php';
        respond([
            'ok' => true,
            'data' => social_fetch_feed(null, 'global', null),
            'meta' => ['permissions' => social_feed_permissions('global', null, null)]
        ]);
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
