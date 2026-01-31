<?php
require_once __DIR__ . '/../api/lib/env.php';
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/migrations.php';

$directory = __DIR__ . '/../sql/migrations';
try {
    $results = apply_migrations(db(), $directory);
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: {$e->getMessage()}\n");
    exit(1);
}

foreach ($results as $result) {
    echo "{$result['file']}: {$result['status']}\n";
}
