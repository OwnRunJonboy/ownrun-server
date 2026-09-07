#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Add job_artifacts table and runner SD/backend columns.
 *
 * Usage: php scripts/migrate_artifacts.php
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

if (!tableExists($pdo, 'job_artifacts')) {
    $pdo->exec(
        'CREATE TABLE job_artifacts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            job_id INT UNSIGNED NOT NULL,
            kind VARCHAR(32) NOT NULL DEFAULT \'output\',
            filename VARCHAR(255) NOT NULL,
            content_type VARCHAR(128) NOT NULL,
            storage_path VARCHAR(512) NOT NULL,
            size_bytes INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_job (job_id),
            FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    echo "Created job_artifacts table\n";
    $changes++;
}

$cols = [
    'backend_state' => 'VARCHAR(32) NULL',
    'sd_available' => 'TINYINT(1) NULL',
    'sd_models' => 'JSON NULL',
    'ollama_models_stale' => 'TINYINT(1) NULL',
];
$after = 'agent_tool_ids';
foreach ($cols as $name => $type) {
    if (!columnExists($pdo, 'runners', $name)) {
        $pdo->exec("ALTER TABLE runners ADD COLUMN {$name} {$type} AFTER {$after}");
        echo "Added runners.{$name}\n";
        $changes++;
    }
    $after = $name;
}

$storage = $config['artifacts_storage_path'] ?? (dirname(__DIR__) . '/storage/artifacts');
if (!is_dir($storage)) {
    if (!mkdir($storage, 0770, true) && !is_dir($storage)) {
        fwrite(STDERR, "Could not create artifacts storage dir: {$storage}\n");
        exit(1);
    }
    echo "Created storage dir {$storage}\n";
    $changes++;
}
// mkdir() is umask-masked, so 0770 can land as 0750. Apache needs group-write
// (same as storage/kb) to create per-job subdirectories.
$mode = fileperms($storage) & 07777;
if (($mode & 0020) === 0) {
    if (chmod($storage, 02770)) {
        echo "Set group-write on {$storage}\n";
        $changes++;
    } else {
        fwrite(STDERR, "Warning: {$storage} is not group-writable; Apache may fail to store artifacts. Run: chmod 2770 {$storage}\n");
    }
}

echo $changes === 0 ? "Nothing to migrate.\n" : "Done ({$changes} change(s)).\n";
