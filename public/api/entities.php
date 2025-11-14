<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

use App\Auth\AuthService;
use App\Lib\Response;
use App\Lib\Session;
use App\Services\EntityService;
use App\Validation\Validator;
use Throwable;

$auth = new AuthService();
$user = $auth->requireUser();
$service = new EntityService();

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        Response::json(['data' => $service->all()]);
        break;
    case 'POST':
        Session::requireCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        $auth->enforceRoles($user, ['ADMIN']);
        $input = json_decode(file_get_contents('php://input') ?: 'null', true) ?? [];
        try {
            $payload = [
                'name' => Validator::string($input, 'name', 3, 128),
                'slug' => Validator::string($input, 'slug', 3, 64),
                'publishQuotaPer7d' => Validator::int($input, 'publishQuotaPer7d', 0, 100),
            ];
            Response::json(['data' => $service->create($payload, $user['id'])], 201);
        } catch (Throwable $exception) {
            Response::json(['message' => $exception->getMessage()], 422);
        }
        break;
    case 'PATCH':
        Session::requireCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        $auth->enforceRoles($user, ['ADMIN']);
        $input = json_decode(file_get_contents('php://input') ?: 'null', true) ?? [];
        try {
            $payload = [
                'id' => Validator::string($input, 'id', 36, 36),
                'name' => $input['name'] ?? null,
                'slug' => $input['slug'] ?? null,
                'publishQuotaPer7d' => Validator::int($input, 'publishQuotaPer7d', 0, 100),
            ];
            Response::json(['data' => $service->update($payload, $user['id'])]);
        } catch (Throwable $exception) {
            Response::json(['message' => $exception->getMessage()], 422);
        }
        break;
    default:
        Response::json(['message' => 'Method not allowed'], 405);
}
