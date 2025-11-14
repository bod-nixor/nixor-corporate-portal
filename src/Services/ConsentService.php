<?php

declare(strict_types=1);

namespace App\Services;

use App\Lib\DB;
use PDO;

final class ConsentService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = DB::pdo();
    }

    public function store(array $data, string $registrationId, string $userId): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO ConsentForm (id, registrationId, formSnapshotJson, submittedAt) VALUES (:id, :registrationId, :snapshot, NOW(3)) ON DUPLICATE KEY UPDATE formSnapshotJson = VALUES(formSnapshotJson), submittedAt = NOW(3)');
        $stmt->execute([
            ':id' => $this->uuid(),
            ':registrationId' => $registrationId,
            ':snapshot' => json_encode($data, JSON_THROW_ON_ERROR),
        ]);
        (new AuditService())->log($userId, 'consent.submit', $registrationId);
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
