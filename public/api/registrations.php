<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

use App\Auth\AuthService;
use App\Lib\Response;
use App\Lib\DB;
use App\Lib\Session;
use App\Services\RegistrationService;
use App\Services\MailService;
use App\Services\EndeavourService;
use App\Validation\Validator;
use RuntimeException;
use Throwable;

$auth = new AuthService();
$user = $auth->requireUser();
$service = new RegistrationService();

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        $auth->enforceRoles($user, ['ADMIN', 'HR', 'ENTITY_MANAGER']);
        $data = $service->listForHr();
        if (($_GET['format'] ?? '') === 'csv') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="registrations.csv"');
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['Volunteer', 'Entity', 'Endeavour', 'Status', 'Registered']);
            foreach ($data as $row) {
                fputcsv($out, [$row['volunteer']['name'], $row['entity']['name'], $row['endeavour']['title'], $row['status'], $row['registeredAt']]);
            }
            fclose($out);
            exit;
        }
        Response::json(['data' => $data]);
        break;
    case 'POST':
        Session::requireCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        $auth->enforceRoles($user, ['VOLUNTEER', 'ENTITY_MANAGER', 'ADMIN']);
        $input = json_decode(file_get_contents('php://input') ?: 'null', true) ?? [];
        try {
            $endeavourId = Validator::string($input, 'endeavourId', 36, 36);
            $service->ensureEligibility($endeavourId, $user);
            $endeavour = (new EndeavourService())->find($endeavourId);
            $registration = $service->create($endeavourId, $user['id']);
            $contactsStmt = DB::pdo()->prepare('SELECT email FROM ParentContact WHERE volunteerId = :volunteerId');
            $contactsStmt->execute([':volunteerId' => $user['id']]);
            $recipients = $contactsStmt->fetchAll();
            $mailer = new MailService();
            $context = [
                'volunteer' => $user['name'],
                'endeavour' => $endeavour['title'],
                'entity' => $endeavour['entityName'],
                'schedule' => $registration['registeredAt'],
            ];
            if ($recipients) {
                foreach ($recipients as $recipient) {
                    $mailer->send('ParentRegistrationNotice', $recipient['email'], $context);
                }
            } else {
                $mailer->send('ParentRegistrationNotice', $user['email'], $context);
            }
            Response::json(['data' => $registration], 201);
        } catch (RuntimeException $exception) {
            Response::json(['message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            Response::json(['message' => $exception->getMessage()], 400);
        }
        break;
    case 'PATCH':
        Session::requireCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        $auth->enforceRoles($user, ['ADMIN', 'HR', 'ENTITY_MANAGER']);
        $input = json_decode(file_get_contents('php://input') ?: 'null', true) ?? [];
        try {
            $registration = $service->updateStatus(
                Validator::string($input, 'id', 36, 36),
                Validator::string($input, 'status', 5, 32),
                $user['id']
            );
            Response::json(['data' => $registration]);
        } catch (Throwable $exception) {
            Response::json(['message' => $exception->getMessage()], 422);
        }
        break;
    default:
        Response::json(['message' => 'Method not allowed'], 405);
}
