<?php

declare(strict_types=1);

namespace App\Services;

use App\Lib\DB;
use PDO;

final class AuditService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = DB::pdo();
    }

    public function log(string $actorId, string $action, ?string $subjectId = null, array $metadata = []): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO AuditLog (id, actorUserId, action, subjectId, metadataJson, createdAt) VALUES (:id, :actor, :action, :subject, :meta, NOW(3))');
        $stmt->execute([
            ':id' => $this->uuid(),
            ':actor' => $actorId,
            ':action' => $action,
            ':subject' => $subjectId,
            ':meta' => json_encode($metadata, JSON_THROW_ON_ERROR),
        ]);
    }

    public function recent(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare('SELECT al.*, u.name AS actorName FROM AuditLog al LEFT JOIN User u ON u.id = al.actorUserId ORDER BY createdAt DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
