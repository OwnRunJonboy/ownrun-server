#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Add project tool policy table and runner capability columns.
 *
 * Usage: php scripts/migrate_tool_policies.php
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

if (!tableExists($pdo, 'project_tool_policies')) {
    $sql = file_get_contents(dirname(__DIR__) . '/sql/migrate_tool_policies.sql');
    if ($sql === false) {
        fwrite(STDERR, "Could not read sql/migrate_tool_policies.sql\n");
        exit(1);
    }
    $pdo->exec($sql);
    echo "Created project_tool_policies table\n";
    $changes++;
}

if (!columnExists($pdo, 'runners', 'ollama_web_search')) {
    $pdo->exec('ALTER TABLE runners ADD COLUMN ollama_web_search TINYINT(1) NULL');
    echo "Added runners.ollama_web_search\n";
    $changes++;
}

if (!columnExists($pdo, 'runners', 'agent_tool_ids')) {
    $pdo->exec('ALTER TABLE runners ADD COLUMN agent_tool_ids JSON NULL');
    echo "Added runners.agent_tool_ids\n";
    $changes++;
}

echo $changes === 0 ? "Nothing to migrate.\n" : "Done ({$changes} change(s)).\n";
