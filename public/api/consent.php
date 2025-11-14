<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

use App\Auth\AuthService;
use App\Lib\Response;
use App\Lib\Session;
use App\Services\ConsentService;
use App\Services\RegistrationService;
use App\Validation\Validator;
use RuntimeException;
use Throwable;

$auth = new AuthService();
$user = $auth->requireUser();
$auth->enforceRoles($user, ['VOLUNTEER', 'ENTITY_MANAGER', 'HR', 'ADMIN']);

Session::requireCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);

$input = json_decode(file_get_contents('php://input') ?: 'null', true) ?? [];

try {
    $registrationId = Validator::string($input, 'registrationId', 36, 36);
    $service = new RegistrationService();
    $registration = $service->find($registrationId);
    if ($registration['volunteerId'] !== $user['id'] && !in_array($user['role'], ['ADMIN', 'HR'], true)) {
        throw new RuntimeException('Cannot submit consent for others');
    }
    (new ConsentService())->store($input, $registrationId, $user['id']);
    Response::noContent();
} catch (Throwable $exception) {
    Response::json(['message' => $exception->getMessage()], 422);
}
