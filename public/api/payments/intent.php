<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Auth\AuthService;
use App\Lib\Response;
use App\Lib\Session;
use App\Services\PaymentService;
use App\Validation\Validator;
use Throwable;

$auth = new AuthService();
$user = $auth->requireUser();
$auth->enforceRoles($user, ['VOLUNTEER', 'HR', 'ADMIN', 'ENTITY_MANAGER']);

Session::requireCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);

$input = json_decode(file_get_contents('php://input') ?: 'null', true) ?? [];

try {
    $intent = (new PaymentService())->createIntent(
        Validator::string($input, 'registrationId', 36, 36),
        Validator::int($input, 'amountCents', 0, 1000000),
        Validator::string($input, 'currency', 3, 8)
    );
    Response::json(['data' => $intent], 201);
} catch (Throwable $exception) {
    Response::json(['message' => $exception->getMessage()], 422);
}
