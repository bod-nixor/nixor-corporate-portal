<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

use App\Auth\AuthService;
use App\Lib\Response;
use App\Lib\Session;
use App\Services\EndeavourService;
use App\Services\EntityService;
use App\Services\RateLimitService;
use App\Validation\Validator;
use RuntimeException;
use Throwable;

$auth = new AuthService();
$user = $auth->requireUser();
$service = new EndeavourService();

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        $filters = [
            'entityId' => $_GET['entityId'] ?? null,
            'startFrom' => $_GET['startFrom'] ?? null,
            'endTo' => $_GET['endTo'] ?? null,
        ];
        Response::json(['data' => $service->list($filters, $user)]);
        break;
    case 'POST':
        Session::requireCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        $auth->enforceRoles($user, ['ADMIN', 'ENTITY_MANAGER', 'HR']);
        $input = json_decode(file_get_contents('php://input') ?: 'null', true) ?? [];
        try {
            $payload = [
                'entityId' => Validator::string($input, 'entityId', 36, 36),
                'title' => Validator::string($input, 'title', 5, 191),
                'description' => Validator::string($input, 'description', 10, 2000),
                'venue' => Validator::string($input, 'venue', 3, 191),
                'startAt' => Validator::string($input, 'startAt', 1, 32),
                'endAt' => Validator::string($input, 'endAt', 1, 32),
                'maxVolunteers' => Validator::int($input, 'maxVolunteers', 0, 1000, false) ?? null,
                'requiresTransportPayment' => (bool) ($input['requiresTransportPayment'] ?? false),
                'tags' => $input['tags'] ?? [],
            ];
            if ($user['role'] === 'ENTITY_MANAGER' && !in_array($payload['entityId'], $user['entityIds'], true)) {
                throw new RuntimeException('Cannot publish for other entities');
            }
            $entity = (new EntityService())->find($payload['entityId']);
            $quota = (int) $entity['publishQuotaPer7d'];
            if ($quota > 0) {
                $rate = new RateLimitService();
                if (!$rate->checkQuota($payload['entityId'], 'endeavour.publish', $quota, 7 * 24 * 3600)) {
                    Response::json(['message' => 'Publish quota reached'], 429);
                    exit;
                }
            }
            $created = $service->create($payload, $user);
            Response::json(['data' => $created], 201);
        } catch (Throwable $exception) {
            Response::json(['message' => $exception->getMessage()], 422);
        }
        break;
    case 'PATCH':
        Session::requireCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        $auth->enforceRoles($user, ['ADMIN', 'ENTITY_MANAGER']);
        $input = json_decode(file_get_contents('php://input') ?: 'null', true) ?? [];
        try {
            $payload = [
                'id' => Validator::string($input, 'id', 36, 36),
                'entityId' => Validator::string($input, 'entityId', 36, 36),
                'title' => Validator::string($input, 'title', 5, 191),
                'description' => Validator::string($input, 'description', 10, 2000),
                'venue' => Validator::string($input, 'venue', 3, 191),
                'startAt' => Validator::string($input, 'startAt', 1, 32),
                'endAt' => Validator::string($input, 'endAt', 1, 32),
                'maxVolunteers' => Validator::int($input, 'maxVolunteers', 0, 1000, false) ?? null,
                'requiresTransportPayment' => (bool) ($input['requiresTransportPayment'] ?? false),
                'tags' => $input['tags'] ?? [],
            ];
            if ($user['role'] === 'ENTITY_MANAGER' && !in_array($payload['entityId'], $user['entityIds'], true)) {
                throw new RuntimeException('Cannot update other entities');
            }
            Response::json(['data' => $service->update($payload, $user)]);
        } catch (Throwable $exception) {
            Response::json(['message' => $exception->getMessage()], 422);
        }
        break;
    default:
        Response::json(['message' => 'Method not allowed'], 405);
}
