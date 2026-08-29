#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Add task tables to an existing OwnRun database.
 *
 * Usage: php scripts/migrate_tasks.php
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

if (!tableExists($pdo, 'task_entries')) {
    $sql = file_get_contents(dirname(__DIR__) . '/sql/migrate_tasks.sql');
    if ($sql === false) {
        fwrite(STDERR, "Could not read sql/migrate_tasks.sql\n");
        exit(1);
    }
    $pdo->exec($sql);
    echo "Created task_entries\n";
    $changes++;
} else {
    echo "task_entries already present\n";
}

echo $changes === 0 ? "Nothing to migrate.\n" : "Done ({$changes} change(s)).\n";
