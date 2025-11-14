<?php

declare(strict_types=1);

namespace App\Auth;

use App\Lib\DB;
use App\Lib\Env;
use App\Lib\JWT;
use App\Lib\Session;
use App\Lib\Response;
use PDO;
use Throwable;

final class AuthService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = DB::pdo();
        Session::start();
    }

    public function currentUser(): ?array
    {
        if (!isset($_SESSION['user_id'])) {
            return null;
        }
        return $this->findUserById($_SESSION['user_id']);
    }

    public function requireUser(): array
    {
        $user = $this->currentUser();
        if (!$user) {
            Response::json(['message' => 'Unauthorized'], 401);
            exit;
        }
        return $user;
    }

    public function loginWithGoogle(array $googleProfile): array
    {
        $email = strtolower($googleProfile['email']);
        $user = $this->findUserByEmail($email);

        if (!$user) {
            $user = $this->createUser($googleProfile);
        } else {
            $user = $this->updateUserProfile($user['id'], $googleProfile);
        }

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];

        return $this->enrichUser($user['id']);
    }

    public function logout(): void
    {
        Session::destroy();
    }

    public function issueJwt(array $user): string
    {
        $secret = Env::get('JWT_SECRET') ?? '';
        $issuer = Env::get('APP_URL') ?? 'nixor-dashboard';
        return JWT::issueForUser($user, 1800, $secret, $issuer);
    }

    public function enforceRoles(array $user, array $roles): void
    {
        if (!in_array($user['role'], $roles, true)) {
            Response::json(['message' => 'Forbidden'], 403);
            exit;
        }
    }

    private function createUser(array $profile): array
    {
        $id = $this->uuid();
        $stmt = $this->pdo->prepare('INSERT INTO User (id, googleId, email, name, role, createdAt, updatedAt) VALUES (:id, :googleId, :email, :name, :role, NOW(3), NOW(3))');
        $stmt->execute([
            ':id' => $id,
            ':googleId' => $profile['sub'],
            ':email' => strtolower($profile['email']),
            ':name' => $profile['name'] ?? $profile['email'],
            ':role' => 'VOLUNTEER',
        ]);
        return $this->findUserById($id);
    }

    private function updateUserProfile(string $id, array $profile): array
    {
        $stmt = $this->pdo->prepare('UPDATE User SET name = :name, updatedAt = NOW(3) WHERE id = :id');
        $stmt->execute([
            ':name' => $profile['name'] ?? $profile['email'],
            ':id' => $id,
        ]);
        return $this->findUserById($id);
    }

    private function findUserByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM User WHERE email = :email');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    private function findUserById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM User WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch();
        return $user ? $this->enrichUser($user['id']) : null;
    }

    private function enrichUser(string $id): array
    {
        $stmt = $this->pdo->prepare('SELECT u.*, GROUP_CONCAT(em.entityId) AS entities FROM User u LEFT JOIN EntityMembership em ON em.userId = u.id WHERE u.id = :id GROUP BY u.id');
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch();
        if (!$user) {
            throw new \RuntimeException('User not found');
        }
        $entityIds = $this->fetchEntityMemberships($id);
        return [
            'id' => $user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'role' => $user['role'],
            'entityIds' => $entityIds,
        ];
    }

    private function fetchEntityMemberships(string $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT entityId FROM EntityMembership WHERE userId = :userId');
        $stmt->execute([':userId' => $userId]);
        return array_map(fn ($row) => $row['entityId'], $stmt->fetchAll());
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
