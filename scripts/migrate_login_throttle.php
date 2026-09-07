#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Add login_attempts and users lockout columns.
 *
 * Usage: php scripts/migrate_login_throttle.php
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

if (!tableExists($pdo, 'login_attempts')) {
    $sql = file_get_contents(dirname(__DIR__) . '/sql/migrate_login_throttle.sql');
    if ($sql === false) {
        fwrite(STDERR, "Could not read sql/migrate_login_throttle.sql\n");
        exit(1);
    }
    $pdo->exec($sql);
    echo "Created login_attempts\n";
    $changes++;
} else {
    echo "login_attempts already present\n";
}

if (!columnExists($pdo, 'users', 'failed_login_count')) {
    $pdo->exec(
        'ALTER TABLE users ADD COLUMN failed_login_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER password_hash'
    );
    echo "Added users.failed_login_count\n";
    $changes++;
}

if (!columnExists($pdo, 'users', 'locked_until')) {
    $pdo->exec(
        'ALTER TABLE users ADD COLUMN locked_until DATETIME NULL AFTER failed_login_count'
    );
    echo "Added users.locked_until\n";
    $changes++;
}

echo $changes === 0 ? "Nothing to migrate.\n" : "Done ({$changes} change(s)).\n";
