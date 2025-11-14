<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use App\Lib\DB;

$pdo = DB::pdo();
$sql = file_get_contents(__DIR__ . '/../sql/seed.sql');

if ($sql === false) {
    fwrite(STDERR, "Seed file missing\n");
    exit(1);
}

try {
    $pdo->beginTransaction();
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
    }
    $pdo->commit();
    echo "Seeds applied\n";
} catch (Throwable $exception) {
    $pdo->rollBack();
    fwrite(STDERR, 'Seed failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
