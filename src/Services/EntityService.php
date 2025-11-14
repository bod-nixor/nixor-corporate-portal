<?php

declare(strict_types=1);

namespace App\Services;

use App\Lib\DB;
use PDO;

final class EntityService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = DB::pdo();
    }

    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT id, name, slug, publishQuotaPer7d, createdAt, updatedAt FROM Entity ORDER BY name');
        return $stmt->fetchAll();
    }

    public function create(array $data, string $userId): array
    {
        $id = $this->uuid();
        $stmt = $this->pdo->prepare('INSERT INTO Entity (id, name, slug, publishQuotaPer7d, createdAt, updatedAt) VALUES (:id, :name, :slug, :quota, NOW(3), NOW(3))');
        $stmt->execute([
            ':id' => $id,
            ':name' => $data['name'],
            ':slug' => $data['slug'],
            ':quota' => $data['publishQuotaPer7d'],
        ]);
        (new AuditService())->log($userId, 'entity.create', $id, ['name' => $data['name']]);
        return $this->find($id);
    }

    public function update(array $data, string $userId): array
    {
        $current = $this->find($data['id']);
        $stmt = $this->pdo->prepare('UPDATE Entity SET name = :name, slug = :slug, publishQuotaPer7d = :quota, updatedAt = NOW(3) WHERE id = :id');
        $stmt->execute([
            ':id' => $data['id'],
            ':name' => $data['name'] ?? $current['name'],
            ':slug' => $data['slug'] ?? $current['slug'],
            ':quota' => $data['publishQuotaPer7d'],
        ]);
        (new AuditService())->log($userId, 'entity.update', $data['id'], ['payload' => $data]);
        return $this->find($data['id']);
    }

    public function find(string $id): array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, slug, publishQuotaPer7d, createdAt, updatedAt FROM Entity WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $entity = $stmt->fetch();
        if (!$entity) {
            throw new \RuntimeException('Entity not found');
        }
        return $entity;
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
