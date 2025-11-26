<?php

declare(strict_types=1);

namespace App\Lib;

use PDO;
use PDOException;
use App\Lib\Logger;

final class DB
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            Env::get('DB_HOST', '127.0.0.1'),
            Env::get('DB_PORT', '3306'),
            Env::get('DB_NAME', 'nixor')
        );

        try {
            $pdo = new PDO($dsn, Env::get('DB_USER', ''), Env::get('DB_PASS', ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            Logger::exception($exception, ['dsn' => $dsn]);
            http_response_code(500);
            echo json_encode(['error' => 'Database connection failed']);
            exit;
        }

        self::$pdo = $pdo;
        return self::$pdo;
    }
}
