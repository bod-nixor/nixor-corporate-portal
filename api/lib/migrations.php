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
    $length = strlen($sql);
    $statements = [];
    $buffer = '';
    $delimiter = ';';
    $inSingle = false;
    $inDouble = false;
    $inLineComment = false;
    $inBlockComment = false;
    $lineStart = true;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($lineStart && !$inSingle && !$inDouble && !$inBlockComment && !$inLineComment) {
            $lineEnd = strpos($sql, "\n", $i);
            $line = $lineEnd === false ? substr($sql, $i) : substr($sql, $i, $lineEnd - $i);
            if (stripos(trim($line), 'DELIMITER ') === 0) {
                $parts = preg_split('/\s+/', trim($line), 2);
                $delimiter = $parts[1] ?? ';';
                $i += strlen($line);
                $lineStart = true;
                continue;
            }
        }

        if (!$inSingle && !$inDouble && !$inBlockComment && !$inLineComment) {
            if ($char === '-' && $next === '-') {
                $inLineComment = true;
            } elseif ($char === '#') {
                $inLineComment = true;
            } elseif ($char === '/' && $next === '*') {
                $inBlockComment = true;
                $i++;
                continue;
            }
        }

        if ($inLineComment) {
            if ($char === "\n") {
                $inLineComment = false;
                $lineStart = true;
            }
            continue;
        }

        if ($inBlockComment) {
            if ($char === '*' && $next === '/') {
                $inBlockComment = false;
                $i++;
            }
            continue;
        }

        if (!$inDouble && $char === "'") {
            if ($next === "'" && $inSingle) {
                $buffer .= $char;
                $i++;
            } else {
                $backslashes = 0;
                for ($j = $i - 1; $j >= 0 && $sql[$j] === '\\'; $j--) {
                    $backslashes++;
                }
                if ($backslashes % 2 === 0) {
                    $inSingle = !$inSingle;
                }
            }
        } elseif (!$inSingle && $char === '"') {
            if ($next === '"' && $inDouble) {
                $buffer .= $char;
                $i++;
            } else {
                $backslashes = 0;
                for ($j = $i - 1; $j >= 0 && $sql[$j] === '\\'; $j--) {
                    $backslashes++;
                }
                if ($backslashes % 2 === 0) {
                    $inDouble = !$inDouble;
                }
            }
        }

        if (!$inSingle && !$inDouble && $delimiter !== '' && substr($sql, $i, strlen($delimiter)) === $delimiter) {
            $statement = trim($buffer);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
            $i += strlen($delimiter) - 1;
            $lineStart = true;
            continue;
        }

        $buffer .= $char;
        $lineStart = $char === "\n";
    }

    $statement = trim($buffer);
    if ($statement !== '') {
        $statements[] = $statement;
    }
    return $statements;
}

function split_alter_table_operations(string $operations): array {
    $length = strlen($operations);
    $buffer = '';
    $parts = [];
    $depth = 0;
    $inSingle = false;
    $inDouble = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $operations[$i];
        $next = $i + 1 < $length ? $operations[$i + 1] : '';

        if ($char === "'" && !$inDouble) {
            if ($inSingle && $next === "'") {
                $buffer .= $char . $next;
                $i++;
                continue;
            }
            $backslashes = 0;
            for ($j = $i - 1; $j >= 0 && $operations[$j] === '\\'; $j--) {
                $backslashes++;
            }
            $escaped = $backslashes % 2 === 1;
            if (!$escaped) {
                $inSingle = !$inSingle;
            }
        } elseif ($char === '"' && !$inSingle) {
            if ($inDouble && $next === '"') {
                $buffer .= $char . $next;
                $i++;
                continue;
            }
            $backslashes = 0;
            for ($j = $i - 1; $j >= 0 && $operations[$j] === '\\'; $j--) {
                $backslashes++;
            }
            $escaped = $backslashes % 2 === 1;
            if (!$escaped) {
                $inDouble = !$inDouble;
            }
        }

        if (!$inSingle && !$inDouble) {
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')' && $depth > 0) {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $part = trim($buffer);
                if ($part !== '') {
                    $parts[] = $part;
                }
                $buffer = '';
                continue;
            }
        }

        $buffer .= $char;
    }

    $tail = trim($buffer);
    if ($tail !== '') {
        $parts[] = $tail;
    }

    return $parts;
}

function migration_database_name(PDO $pdo): string {
    static $databaseNames = [];
    $connectionKey = spl_object_hash($pdo);
    if (array_key_exists($connectionKey, $databaseNames)) {
        return $databaseNames[$connectionKey];
    }
    $databaseNames[$connectionKey] = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    return $databaseNames[$connectionKey];
}

function migration_column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $stmt->execute([migration_database_name($pdo), $table, $column]);
    return (bool)$stmt->fetchColumn();
}

function migration_index_exists(PDO $pdo, string $table, string $index): bool {
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1'
    );
    $stmt->execute([migration_database_name($pdo), $table, $index]);
    return (bool)$stmt->fetchColumn();
}

function execute_migration_statement(PDO $pdo, string $statement): void {
    if (stripos($statement, 'ALTER TABLE') !== 0 || stripos($statement, 'IF NOT EXISTS') === false) {
        $pdo->exec($statement);
        return;
    }

    if (!preg_match('/^ALTER\s+TABLE\s+`?([A-Za-z0-9_]+)`?\s+(.+)$/is', trim($statement), $matches)) {
        $pdo->exec($statement);
        return;
    }

    $table = $matches[1];
    $operations = split_alter_table_operations($matches[2]);
    $pendingOperations = [];
    foreach ($operations as $operation) {
        if (preg_match('/^ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\s+`?([A-Za-z0-9_]+)`?\s+(.+)$/is', $operation, $columnMatch)) {
            if (!migration_column_exists($pdo, $table, $columnMatch[1])) {
                $pendingOperations[] = sprintf('ADD COLUMN `%s` %s', $columnMatch[1], $columnMatch[2]);
            }
            continue;
        }

        if (preg_match('/^ADD\s+(UNIQUE\s+)?(?:INDEX|KEY)\s+IF\s+NOT\s+EXISTS\s+`?([A-Za-z0-9_]+)`?\s*(.+)$/is', $operation, $indexMatch)) {
            $indexModifier = trim((string)($indexMatch[1] ?? ''));
            if (!migration_index_exists($pdo, $table, $indexMatch[2])) {
                $pendingOperations[] = sprintf(
                    'ADD %sINDEX `%s` %s',
                    $indexModifier !== '' ? $indexModifier . ' ' : '',
                    $indexMatch[2],
                    $indexMatch[3]
                );
            }
            continue;
        }

        $pendingOperations[] = $operation;
    }

    if ($pendingOperations) {
        $pdo->exec(sprintf('ALTER TABLE `%s` %s', $table, implode(', ', $pendingOperations)));
    }
}

function apply_migrations(PDO $pdo, string $directory): array {
    $applied = applied_migrations($pdo);
    $files = migration_files($directory);
    $results = [];
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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
                execute_migration_statement($pdo, $statement);
            }
            $insert = $pdo->prepare('INSERT INTO migrations (filename, checksum) VALUES (?, ?)');
            $insert->execute([$file, $checksum]);
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            $results[] = ['file' => $file, 'status' => 'applied'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Migration failed: ' . $file . ' ' . $e->getMessage());
            throw $e;
        }
    }
    return $results;
}
