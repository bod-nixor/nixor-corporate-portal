<?php

declare(strict_types=1);

namespace App\Services;

use App\Lib\DB;
use PDO;

final class RegistrationService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = DB::pdo();
    }

    public function listForHr(): array
    {
        $stmt = $this->pdo->query('SELECT r.*, u.name AS volunteerName, u.id AS volunteerId, en.title AS endeavourTitle, ent.name AS entityName, ent.id AS entityId FROM Registration r INNER JOIN User u ON u.id = r.volunteerId INNER JOIN Endeavour en ON en.id = r.endeavourId INNER JOIN Entity ent ON ent.id = en.entityId ORDER BY r.registeredAt DESC LIMIT 200');
        return array_map(function (array $row): array {
            return [
                'id' => $row['id'],
                'status' => $row['status'],
                'registeredAt' => $row['registeredAt'],
                'volunteer' => [
                    'id' => $row['volunteerId'],
                    'name' => $row['volunteerName'],
                ],
                'entity' => [
                    'id' => $row['entityId'],
                    'name' => $row['entityName'],
                ],
                'endeavour' => [
                    'id' => $row['endeavourId'],
                    'title' => $row['endeavourTitle'],
                ],
            ];
        }, $stmt->fetchAll());
    }

    public function create(string $endeavourId, string $volunteerId): array
    {
        $id = $this->uuid();
        $stmt = $this->pdo->prepare('INSERT INTO Registration (id, endeavourId, volunteerId, status, registeredAt, updatedAt) VALUES (:id, :endeavourId, :volunteerId, "REGISTERED", NOW(3), NOW(3))');
        $stmt->execute([
            ':id' => $id,
            ':endeavourId' => $endeavourId,
            ':volunteerId' => $volunteerId,
        ]);
        (new AuditService())->log($volunteerId, 'registration.create', $id);
        return $this->find($id);
    }

    public function updateStatus(string $registrationId, string $status, string $actorId): array
    {
        $stmt = $this->pdo->prepare('UPDATE Registration SET status = :status, updatedAt = NOW(3) WHERE id = :id');
        $stmt->execute([
            ':status' => $status,
            ':id' => $registrationId,
        ]);
        (new AuditService())->log($actorId, 'registration.status', $registrationId, ['status' => $status]);
        return $this->find($registrationId);
    }

    public function find(string $id): array
    {
        $stmt = $this->pdo->prepare('SELECT r.*, en.entityId, en.title, u.email FROM Registration r INNER JOIN Endeavour en ON en.id = r.endeavourId INNER JOIN User u ON u.id = r.volunteerId WHERE r.id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new \RuntimeException('Registration not found');
        }
        return $row;
    }

    public function ensureEligibility(string $endeavourId, array $user): void
    {
        $stmt = $this->pdo->prepare('SELECT entityId, maxVolunteers FROM Endeavour WHERE id = :id');
        $stmt->execute([':id' => $endeavourId]);
        $endeavour = $stmt->fetch();
        if (!$endeavour) {
            throw new \InvalidArgumentException('Endeavour not found');
        }

        if (!in_array($endeavour['entityId'], $user['entityIds'], true) && $user['role'] === 'VOLUNTEER') {
            throw new \RuntimeException('Not a member of this entity');
        }

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM Registration WHERE endeavourId = :endeavourId');
        $countStmt->execute([':endeavourId' => $endeavourId]);
        $registeredCount = (int) $countStmt->fetchColumn();
        if ($endeavour['maxVolunteers'] && $registeredCount >= (int) $endeavour['maxVolunteers']) {
            throw new \RuntimeException('No remaining capacity');
        }

        $existingStmt = $this->pdo->prepare('SELECT id FROM Registration WHERE endeavourId = :endeavourId AND volunteerId = :volunteerId');
        $existingStmt->execute([
            ':endeavourId' => $endeavourId,
            ':volunteerId' => $user['id'],
        ]);
        if ($existingStmt->fetch()) {
            throw new \RuntimeException('Already registered');
        }
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
