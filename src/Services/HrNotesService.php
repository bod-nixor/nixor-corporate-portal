<?php

declare(strict_types=1);

namespace App\Services;

use App\Lib\DB;
use PDO;

final class HrNotesService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = DB::pdo();
    }

    public function list(string $volunteerId, string $entityId): array
    {
        $stmt = $this->pdo->prepare('SELECT n.*, u.name AS authorName FROM HRNote n INNER JOIN User u ON u.id = n.authorId WHERE n.volunteerId = :volunteerId AND n.entityId = :entityId ORDER BY n.createdAt DESC');
        $stmt->execute([
            ':volunteerId' => $volunteerId,
            ':entityId' => $entityId,
        ]);
        return array_map(fn ($row) => [
            'id' => $row['id'],
            'note' => $row['note'],
            'createdAt' => $row['createdAt'],
            'author' => [
                'id' => $row['authorId'],
                'name' => $row['authorName'],
            ],
        ], $stmt->fetchAll());
    }

    public function create(string $volunteerId, string $entityId, string $note, string $authorId): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO HRNote (id, volunteerId, entityId, authorId, note, createdAt) VALUES (:id, :volunteerId, :entityId, :authorId, :note, NOW(3))');
        $stmt->execute([
            ':id' => $this->uuid(),
            ':volunteerId' => $volunteerId,
            ':entityId' => $entityId,
            ':authorId' => $authorId,
            ':note' => $note,
        ]);
        (new AuditService())->log($authorId, 'hr.note.create', $volunteerId, ['entityId' => $entityId]);
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
