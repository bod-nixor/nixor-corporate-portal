<?php
require_once __DIR__ . '/../api/lib/env.php';
require_once __DIR__ . '/../api/lib/db.php';

$appEnv = env_value('APP_ENV', 'production');
$allowSeed = env_value('ALLOW_DEV_SEED', 'false');

if ($appEnv !== 'development' || !filter_var($allowSeed, FILTER_VALIDATE_BOOLEAN)) {
    fwrite(STDERR, "Dev seed is disabled. Set APP_ENV=development and ALLOW_DEV_SEED=true.\n");
    exit(1);
}

$seedFiles = [
    __DIR__ . '/../sql/dev/seed_reference_data.sql',
    __DIR__ . '/../sql/dev/seed_sample_data.sql'
];

$pdo = db();
foreach ($seedFiles as $file) {
    if (!file_exists($file)) {
        fwrite(STDERR, "Seed file missing: {$file}\n");
        exit(1);
    }
    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "Failed to read {$file}\n");
        exit(1);
    }
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    $pdo->beginTransaction();
    try {
        foreach ($statements as $statement) {
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }
        $pdo->commit();
        echo "Applied seed file: {$file}\n";
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "Seed failed: {$file} {$e->getMessage()}\n");
        exit(1);
    }
}
