#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Add users.token_version and refresh_tokens.family_id.
 *
 * Usage: php scripts/migrate_token_revocation.php
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

function indexExists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->execute([$table, $index]);
    return (int) $stmt->fetchColumn() > 0;
}

$changes = 0;

if (!columnExists($pdo, 'users', 'token_version')) {
    $pdo->exec(
        'ALTER TABLE users ADD COLUMN token_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER locked_until'
    );
    echo "Added users.token_version\n";
    $changes++;
} else {
    echo "users.token_version already present\n";
}

if (!columnExists($pdo, 'refresh_tokens', 'family_id')) {
    $pdo->exec(
        "ALTER TABLE refresh_tokens ADD COLUMN family_id CHAR(64) NOT NULL DEFAULT '' AFTER token_hash"
    );
    echo "Added refresh_tokens.family_id\n";
    $changes++;
} else {
    echo "refresh_tokens.family_id already present\n";
}

$stmt = $pdo->query("SELECT id FROM refresh_tokens WHERE family_id = ''");
$backfilled = 0;
if ($stmt !== false) {
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $update = $pdo->prepare('UPDATE refresh_tokens SET family_id = ? WHERE id = ?');
    foreach ($ids as $id) {
        $update->execute([bin2hex(random_bytes(32)), (int) $id]);
        $backfilled++;
    }
}
if ($backfilled > 0) {
    echo "Backfilled family_id on {$backfilled} refresh token(s)\n";
    $changes++;
}

if (!indexExists($pdo, 'refresh_tokens', 'idx_family_id')) {
    $pdo->exec('ALTER TABLE refresh_tokens ADD INDEX idx_family_id (family_id)');
    echo "Added refresh_tokens.idx_family_id\n";
    $changes++;
} else {
    echo "refresh_tokens.idx_family_id already present\n";
}

echo $changes === 0 ? "Nothing to migrate.\n" : "Done ({$changes} change(s)).\n";
