#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Add knowledge base tables.
 *
 * Usage: php scripts/migrate_kb.php
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$configPath = dirname(__DIR__) . '/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Copy config.example.php to config.php first.\n");
    exit(1);
}

$config = require $configPath;

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $config['db']['host'],
    $config['db']['port'] ?? 3306,
    $config['db']['name'],
    $config['db']['charset'] ?? 'utf8mb4'
);

try {
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

$changes = 0;

if (!tableExists($pdo, 'kb_bases') || !tableExists($pdo, 'kb_documents')) {
    $sql = file_get_contents(dirname(__DIR__) . '/sql/migrate_kb.sql');
    if ($sql === false) {
        fwrite(STDERR, "Could not read sql/migrate_kb.sql\n");
        exit(1);
    }
    $pdo->exec($sql);
    echo "Created kb_bases / kb_documents tables\n";
    $changes++;
}

if (tableExists($pdo, 'kb_documents')) {
    $stmt = $pdo->prepare(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute(['kb_documents', 'format']);
    $formatType = (string) $stmt->fetchColumn();
    $wanted = ["'pdf'", "'docx'", "'epub'", "'md'", "'txt'"];
    $missing = array_filter($wanted, static fn(string $v): bool => !str_contains($formatType, $v));
    if ($formatType !== '' && $missing !== []) {
        $pdo->exec(
            "ALTER TABLE kb_documents
             MODIFY COLUMN format ENUM('pdf', 'docx', 'epub', 'md', 'txt') NOT NULL"
        );
        echo 'Updated kb_documents.format (' . implode(', ', $missing) . ")\n";
        $changes++;
    }
}

$storage = $config['kb_storage_path'] ?? (dirname(__DIR__) . '/storage/kb');
if (!is_dir($storage)) {
    if (!mkdir($storage, 0750, true) && !is_dir($storage)) {
        fwrite(STDERR, "Could not create kb storage dir: {$storage}\n");
        exit(1);
    }
    echo "Created storage dir {$storage}\n";
    $changes++;
}

echo $changes === 0 ? "Nothing to migrate.\n" : "Done ({$changes} change(s)).\n";
