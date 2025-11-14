<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../../src/bootstrap.php';

use App\Auth\AuthService;
use App\Lib\Response;
use App\Lib\DB;

$auth = new AuthService();
$user = $auth->requireUser();

if (!in_array($user['role'], ['VOLUNTEER', 'ENTITY_MANAGER', 'ADMIN'], true)) {
    Response::json(['message' => 'Forbidden'], 403);
    exit;
}

$pdo = DB::pdo();
$stmt = $pdo->prepare('SELECT r.*, e.title, ent.name AS entityName FROM Registration r INNER JOIN Endeavour e ON e.id = r.endeavourId INNER JOIN Entity ent ON ent.id = e.entityId WHERE r.volunteerId = :volunteerId ORDER BY r.registeredAt DESC');
$stmt->execute([':volunteerId' => $user['id']]);

Response::json(['data' => $stmt->fetchAll()]);
