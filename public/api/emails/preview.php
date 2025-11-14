<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Auth\AuthService;
use App\Lib\Response;
use App\Services\MailService;

$auth = new AuthService();
$user = $auth->requireUser();
$auth->enforceRoles($user, ['ADMIN']);

$template = $_GET['template'] ?? 'ParentRegistrationNotice';
$context = json_decode($_GET['context'] ?? '[]', true);

$service = new MailService();
$html = $service->preview($template, $context);

Response::json([
    'template' => $template,
    'html' => $html,
]);
