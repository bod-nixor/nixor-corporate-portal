<?php
function handle_auth(string $method, array $segments): void {
    $action = $segments[1] ?? '';
    if ($action === 'login' && $method === 'POST') {
        $data = read_json();
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$data['email'] ?? '']);
        $user = $stmt->fetch();
        if (!$user || !verify_password($data['password'] ?? '', $user['password_hash'])) {
            respond(['ok' => false, 'error' => 'Invalid credentials'], 401);
        }
        $_SESSION['user_id'] = $user['id'];
        respond(['ok' => true, 'data' => ['user' => $user]]);
    }

    if ($action === 'logout' && $method === 'POST') {
        session_destroy();
        respond(['ok' => true, 'data' => ['message' => 'Logged out']]);
    }

    if ($action === 'me' && $method === 'GET') {
        $user = current_user();
        respond(['ok' => true, 'data' => ['user' => $user]]);
    }

    if ($action === 'google_callback' && $method === 'POST') {
        $data = read_json();
        $stmt = db()->prepare('SELECT * FROM users WHERE google_id = ? OR email = ?');
        $stmt->execute([$data['google_id'] ?? '', $data['email'] ?? '']);
        $user = $stmt->fetch();
        if (!$user) {
            respond(['ok' => false, 'error' => 'Google account not found'], 404);
        }
        $_SESSION['user_id'] = $user['id'];
        respond(['ok' => true, 'data' => ['user' => $user]]);
    }

    respond(['ok' => false, 'error' => 'Not Found'], 404);
}
