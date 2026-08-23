#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Add calendar tables/columns to an existing OwnRun database.
 *
 * Usage: php scripts/migrate_calendar.php
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

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

$changes = 0;

if (!tableExists($pdo, 'calendar_entries')) {
    $sql = file_get_contents(dirname(__DIR__) . '/sql/migrate_calendar.sql');
    if ($sql === false) {
        fwrite(STDERR, "Could not read sql/migrate_calendar.sql\n");
        exit(1);
    }
    $pdo->exec($sql);
    echo "Created calendar_entries\n";
    $changes++;
} else {
    echo "calendar_entries already present\n";
}

if (!columnExists($pdo, 'runners', 'timezone')) {
    $pdo->exec('ALTER TABLE runners ADD COLUMN timezone VARCHAR(64) NULL AFTER agent_projects');
    echo "Added runners.timezone\n";
    $changes++;
} else {
    echo "runners.timezone already present\n";
}

echo $changes === 0 ? "Nothing to migrate.\n" : "Done ({$changes} change(s)).\n";
