<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Auth\AuthService;
use App\Lib\Response;
use App\Services\AuditService;

$auth = new AuthService();
$user = $auth->requireUser();
$auth->enforceRoles($user, ['ADMIN']);

$limit = isset($_GET['limit']) ? min(200, max(1, (int) $_GET['limit'])) : 50;

Response::json(['data' => (new AuditService())->recent($limit)]);
