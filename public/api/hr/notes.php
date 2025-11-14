<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Auth\AuthService;
use App\Lib\Response;
use App\Lib\Session;
use App\Services\HrNotesService;
use App\Validation\Validator;
use Throwable;

$auth = new AuthService();
$user = $auth->requireUser();
$auth->enforceRoles($user, ['ADMIN', 'HR', 'ENTITY_MANAGER']);
$service = new HrNotesService();

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        $volunteer = $_GET['volunteerId'] ?? '';
        $entity = $_GET['entityId'] ?? '';
        if (!$volunteer || !$entity) {
            Response::json(['message' => 'Missing params'], 422);
            exit;
        }
        Response::json(['data' => $service->list($volunteer, $entity)]);
        break;
    case 'POST':
        Session::requireCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        $input = json_decode(file_get_contents('php://input') ?: 'null', true) ?? [];
        try {
            $service->create(
                Validator::string($input, 'volunteerId', 36, 36),
                Validator::string($input, 'entityId', 36, 36),
                Validator::string($input, 'note', 3, 1000),
                $user['id']
            );
            Response::noContent();
        } catch (Throwable $exception) {
            Response::json(['message' => $exception->getMessage()], 422);
        }
        break;
    default:
        Response::json(['message' => 'Method not allowed'], 405);
}
