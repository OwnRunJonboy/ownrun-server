#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Add agent job columns to an existing OwnRun database.
 *
 * Usage: php scripts/migrate_agent.php
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

if (!columnExists($pdo, 'jobs', 'progress_payload')) {
    $pdo->exec('ALTER TABLE jobs ADD COLUMN progress_payload JSON NULL AFTER result_payload');
    echo "Added jobs.progress_payload\n";
    $changes++;
} else {
    echo "jobs.progress_payload already present\n";
}

if (!columnExists($pdo, 'runners', 'agent_profiles')) {
    $pdo->exec('ALTER TABLE runners ADD COLUMN agent_profiles JSON NULL AFTER available_models');
    echo "Added runners.agent_profiles\n";
    $changes++;
} else {
    echo "runners.agent_profiles already present\n";
}

if (!columnExists($pdo, 'runners', 'agent_models')) {
    $pdo->exec('ALTER TABLE runners ADD COLUMN agent_models JSON NULL AFTER agent_profiles');
    echo "Added runners.agent_models\n";
    $changes++;
} else {
    echo "runners.agent_models already present\n";
}

echo $changes === 0 ? "Nothing to migrate.\n" : "Done ({$changes} change(s)).\n";
