<?php
require_once __DIR__ . '/../api/lib/env.php';
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/migrations.php';

$directory = __DIR__ . '/../sql/migrations';
$args = array_values(array_slice($_SERVER['argv'] ?? [], 1));
$only = null;
if ($args) {
    if (count($args) === 1 && str_starts_with($args[0], '--only=')) {
        $only = substr($args[0], strlen('--only='));
    } elseif (count($args) === 2 && $args[0] === '--only') {
        $only = $args[1];
    } else {
        fwrite(STDERR, "Usage: php scripts/migrate.php [--only <migration.sql>]\n");
        exit(2);
    }
}
try {
    $results = $only !== null
        ? [apply_migration_file(db(), $directory, $only)]
        : apply_migrations(db(), $directory);
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: {$e->getMessage()}\n");
    exit(1);
}

foreach ($results as $result) {
    echo "{$result['file']}: {$result['status']}\n";
}
