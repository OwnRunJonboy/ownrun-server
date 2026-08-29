#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Add system prompt tables to an existing OwnRun database.
 *
 * Usage: php scripts/migrate_system_prompts.php
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
$sqlPath = dirname(__DIR__) . '/sql/migrate_system_prompts.sql';
$sql = file_get_contents($sqlPath);
if ($sql === false) {
    fwrite(STDERR, "Could not read sql/migrate_system_prompts.sql\n");
    exit(1);
}

$tables = ['system_prompts', 'user_prompt_defaults', 'project_prompt_defaults'];
foreach ($tables as $table) {
    if (!tableExists($pdo, $table)) {
        $pdo->exec($sql);
        echo "Created system prompt tables\n";
        $changes++;
        break;
    }
}

if ($changes === 0) {
    echo "system_prompts tables already present\n";
}

echo $changes === 0 ? "Nothing to migrate.\n" : "Done ({$changes} change(s)).\n";
