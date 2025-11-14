<?php

declare(strict_types=1);

namespace App\Services;

use App\Lib\DB;
use PDO;

final class PaymentService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = DB::pdo();
    }

    public function createIntent(string $registrationId, int $amount, string $currency = 'PKR'): array
    {
        $id = $this->uuid();
        $stmt = $this->pdo->prepare('INSERT INTO Payment (id, registrationId, amountCents, currency, status, createdAt, updatedAt) VALUES (:id, :registrationId, :amount, :currency, "INITIATED", NOW(3), NOW(3))');
        $stmt->execute([
            ':id' => $id,
            ':registrationId' => $registrationId,
            ':amount' => $amount,
            ':currency' => $currency,
        ]);
        (new AuditService())->log($registrationId, 'payment.intent', $id, ['amount' => $amount]);
        return [
            'id' => $id,
            'registrationId' => $registrationId,
            'amountCents' => $amount,
            'currency' => $currency,
            'status' => 'INITIATED',
            'providerRef' => null,
        ];
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
