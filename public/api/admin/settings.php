<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Auth\AuthService;
use App\Lib\Response;
use App\Lib\Session;
use App\Services\SettingsService;
use App\Services\EntityService;
use App\Validation\Validator;
use RuntimeException;
use Throwable;

$auth = new AuthService();
$user = $auth->requireUser();
$auth->enforceRoles($user, ['ADMIN']);

$settings = new SettingsService();
$entities = new EntityService();

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        Response::json([
            'visibilityMode' => $settings->getVisibilityMode(),
            'entities' => $entities->all(),
        ]);
        break;
    case 'POST':
        Session::requireCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        $input = json_decode(file_get_contents('php://input') ?: 'null', true) ?? [];
        try {
            $mode = strtoupper(Validator::string($input, 'visibilityMode', 4, 16));
            if (!in_array($mode, ['RESTRICTED', 'OPEN'], true)) {
                throw new RuntimeException('Invalid visibility mode');
            }
            $settings->updateVisibilityMode($mode);
            Response::noContent();
        } catch (Throwable $exception) {
            Response::json(['message' => $exception->getMessage()], 422);
        }
        break;
    default:
        Response::json(['message' => 'Method not allowed'], 405);
}
