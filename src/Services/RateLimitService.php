<?php

declare(strict_types=1);

namespace App\Services;

use App\Lib\DB;
use PDO;

final class RateLimitService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = DB::pdo();
    }

    public function checkQuota(string $entityId, string $action, int $limit, int $windowSeconds): bool
    {
        $boundary = date('Y-m-d H:i:s', time() - $windowSeconds);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) AS total FROM RateLimit WHERE entityId = :entityId AND action = :action AND occurredAt >= :boundary');
        $stmt->bindValue(':entityId', $entityId);
        $stmt->bindValue(':action', $action);
        $stmt->bindValue(':boundary', $boundary);
        $stmt->execute();
        $count = (int) $stmt->fetchColumn();
        return $count < $limit;
    }

    public function record(string $entityId, string $action): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO RateLimit (id, entityId, action, occurredAt) VALUES (:id, :entityId, :action, NOW(3))');
        $stmt->execute([
            ':id' => $this->uuid(),
            ':entityId' => $entityId,
            ':action' => $action,
        ]);
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
