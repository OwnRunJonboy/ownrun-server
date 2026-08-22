#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * One-time setup: create database tables, admin user, and runner token.
 *
 * Usage:
 *   php scripts/setup.php --username=admin --password=secret
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

$options = getopt('', ['username:', 'password:', 'runner-name::']);
$username = $options['username'] ?? null;
$password = $options['password'] ?? null;
$runnerName = $options['runner-name'] ?? 'home';

if (!$username || !$password) {
    fwrite(STDERR, "Usage: php scripts/setup.php --username=USER --password=PASS [--runner-name=home]\n");
    exit(1);
}

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

$schema = file_get_contents(dirname(__DIR__) . '/sql/schema.sql');
if ($schema === false) {
    fwrite(STDERR, "Could not read schema.sql\n");
    exit(1);
}

foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
    if ($statement !== '') {
        $pdo->exec($statement);
    }
}
echo "Schema applied.\n";

$hash = password_hash($password, PASSWORD_ARGON2ID);
$stmt = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)');
try {
    $stmt->execute([$username, $hash]);
    echo "User created: {$username}\n";
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), 'Duplicate')) {
        echo "User already exists: {$username}\n";
    } else {
        throw $e;
    }
}

$runnerToken = bin2hex(random_bytes(32));
$runnerHash = hash('sha256', $runnerToken);
$stmt = $pdo->prepare('INSERT INTO runners (name, token_hash) VALUES (?, ?)');
try {
    $stmt->execute([$runnerName, $runnerHash]);
    echo "Runner created: {$runnerName}\n";
    echo "Runner token (save this — shown once):\n{$runnerToken}\n";
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), 'Duplicate')) {
        echo "Runner already exists: {$runnerName}\n";
        echo "Generate a new token manually if needed.\n";
    } else {
        throw $e;
    }
}

echo "Done.\n";
