<?php

declare(strict_types=1);

namespace App\Services;

use App\Lib\DB;
use PDO;

final class EndeavourService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = DB::pdo();
    }

    public function list(array $filters, array $user): array
    {
        $visibility = getenv('VISIBILITY_MODE') ?: 'RESTRICTED';
        $params = [];
        $conditions = [];

        if ($visibility === 'RESTRICTED' && $user['role'] === 'VOLUNTEER') {
            if (empty($user['entityIds'])) {
                return [];
            }
            $conditions[] = 'e.entityId IN (' . implode(',', array_fill(0, count($user['entityIds']), '?')) . ')';
            $params = array_merge($params, $user['entityIds']);
        } elseif ($visibility === 'OPEN' && $user['role'] === 'VOLUNTEER') {
            // allow viewing all but we will block registration enforcement elsewhere
        } elseif ($user['role'] === 'ENTITY_MANAGER') {
            if (empty($user['entityIds'])) {
                return [];
            }
            $conditions[] = 'e.entityId IN (' . implode(',', array_fill(0, count($user['entityIds']), '?')) . ')';
            $params = array_merge($params, $user['entityIds']);
        }

        if (!empty($filters['entityId'])) {
            $conditions[] = 'e.entityId = ?';
            $params[] = $filters['entityId'];
        }

        if (!empty($filters['startFrom'])) {
            $conditions[] = 'e.startAt >= ?';
            $params[] = $filters['startFrom'];
        }

        if (!empty($filters['endTo'])) {
            $conditions[] = 'e.endAt <= ?';
            $params[] = $filters['endTo'];
        }

        $sql = 'SELECT e.*, en.name AS entityName FROM Endeavour e INNER JOIN Entity en ON en.id = e.entityId';
        if ($conditions) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY e.startAt DESC LIMIT 100';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll();
        return array_map(function (array $row) use ($user): array {
            return [
                'id' => $row['id'],
                'title' => $row['title'],
                'description' => $row['description'],
                'venue' => $row['venue'],
                'startAt' => $row['startAt'],
                'endAt' => $row['endAt'],
                'entity' => [
                    'id' => $row['entityId'],
                    'name' => $row['entityName'],
                ],
                'requiresTransportPayment' => (bool) $row['requiresTransportPayment'],
                'registrationStatus' => 'OPEN',
                'tags' => $this->tagsForEndeavour($row['id']),
            ];
        }, $items);
    }

    public function create(array $data, array $user): array
    {
        $id = $this->uuid();
        $stmt = $this->pdo->prepare('INSERT INTO Endeavour (id, entityId, title, description, venue, startAt, endAt, maxVolunteers, requiresTransportPayment, createdByUserId, createdAt, updatedAt) VALUES (:id, :entityId, :title, :description, :venue, :startAt, :endAt, :maxVolunteers, :requiresTransportPayment, :createdBy, NOW(3), NOW(3))');
        $stmt->execute([
            ':id' => $id,
            ':entityId' => $data['entityId'],
            ':title' => $data['title'],
            ':description' => $data['description'],
            ':venue' => $data['venue'],
            ':startAt' => $data['startAt'],
            ':endAt' => $data['endAt'],
            ':maxVolunteers' => $data['maxVolunteers'],
            ':requiresTransportPayment' => $data['requiresTransportPayment'] ? 1 : 0,
            ':createdBy' => $user['id'],
        ]);
        $this->syncTags($id, $data['tags'] ?? []);
        (new AuditService())->log($user['id'], 'endeavour.create', $id, ['entityId' => $data['entityId']]);
        (new RateLimitService())->record($data['entityId'], 'endeavour.publish');
        return $this->find($id);
    }

    public function update(array $data, array $user): array
    {
        $stmt = $this->pdo->prepare('UPDATE Endeavour SET title = :title, description = :description, venue = :venue, startAt = :startAt, endAt = :endAt, maxVolunteers = :maxVolunteers, requiresTransportPayment = :requiresTransportPayment, updatedAt = NOW(3) WHERE id = :id');
        $stmt->execute([
            ':id' => $data['id'],
            ':title' => $data['title'],
            ':description' => $data['description'],
            ':venue' => $data['venue'],
            ':startAt' => $data['startAt'],
            ':endAt' => $data['endAt'],
            ':maxVolunteers' => $data['maxVolunteers'],
            ':requiresTransportPayment' => $data['requiresTransportPayment'] ? 1 : 0,
        ]);
        $this->syncTags($data['id'], $data['tags'] ?? []);
        (new AuditService())->log($user['id'], 'endeavour.update', $data['id'], ['entityId' => $data['entityId']]);
        return $this->find($data['id']);
    }

    public function find(string $id): array
    {
        $stmt = $this->pdo->prepare('SELECT e.*, en.name AS entityName FROM Endeavour e INNER JOIN Entity en ON en.id = e.entityId WHERE e.id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new \RuntimeException('Endeavour not found');
        }
        return [
            'id' => $row['id'],
            'title' => $row['title'],
            'description' => $row['description'],
            'venue' => $row['venue'],
            'startAt' => $row['startAt'],
            'endAt' => $row['endAt'],
            'entityId' => $row['entityId'],
            'entityName' => $row['entityName'],
            'requiresTransportPayment' => (bool) $row['requiresTransportPayment'],
            'tags' => $this->tagsForEndeavour($row['id']),
        ];
    }

    private function tagsForEndeavour(string $id): array
    {
        $stmt = $this->pdo->prepare('SELECT t.id, t.name FROM EndeavourTag et INNER JOIN Tag t ON t.id = et.tagId WHERE et.endeavourId = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetchAll();
    }

    private function syncTags(string $endeavourId, array $tags): void
    {
        $this->pdo->prepare('DELETE FROM EndeavourTag WHERE endeavourId = :id')->execute([':id' => $endeavourId]);
        foreach ($tags as $tagId) {
            $stmt = $this->pdo->prepare('INSERT INTO EndeavourTag (endeavourId, tagId) VALUES (:endeavourId, :tagId)');
            $stmt->execute([
                ':endeavourId' => $endeavourId,
                ':tagId' => $tagId,
            ]);
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
