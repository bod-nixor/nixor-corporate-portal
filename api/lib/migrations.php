<?php
function ensure_migrations_table(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(190) NOT NULL UNIQUE,
            checksum CHAR(64) NOT NULL,
            applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )'
    );
}

function applied_migrations(PDO $pdo): array {
    ensure_migrations_table($pdo);
    $stmt = $pdo->query('SELECT filename, checksum FROM migrations');
    $applied = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $applied[$row['filename']] = $row['checksum'];
    }
    return $applied;
}

function migration_files(string $directory): array {
    if (!is_dir($directory)) {
        return [];
    }
    $files = array_values(array_filter(scandir($directory), function ($file) use ($directory) {
        return is_file($directory . '/' . $file) && str_ends_with($file, '.sql');
    }));
    sort($files, SORT_STRING);
    return $files;
}

function split_sql_statements(string $sql): array {
    $lines = preg_split('/\R/', $sql);
    $clean = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
            continue;
        }
        $clean[] = $line;
    }
    $sql = implode("\n", $clean);
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    return $statements;
}

function apply_migrations(PDO $pdo, string $directory): array {
    $applied = applied_migrations($pdo);
    $files = migration_files($directory);
    $results = [];
    foreach ($files as $file) {
        $path = $directory . '/' . $file;
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Failed to read migration {$file}");
        }
        $checksum = hash('sha256', $contents);
        if (isset($applied[$file])) {
            if ($applied[$file] !== $checksum) {
                throw new RuntimeException("Checksum mismatch for migration {$file}");
            }
            $results[] = ['file' => $file, 'status' => 'skipped'];
            continue;
        }
        $statements = split_sql_statements($contents);
        $pdo->beginTransaction();
        try {
            foreach ($statements as $statement) {
                $pdo->exec($statement);
            }
            $insert = $pdo->prepare('INSERT INTO migrations (filename, checksum) VALUES (?, ?)');
            $insert->execute([$file, $checksum]);
            $pdo->commit();
            $results[] = ['file' => $file, 'status' => 'applied'];
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Migration failed: ' . $file . ' ' . $e->getMessage());
            throw $e;
        }
    }
    return $results;
}
