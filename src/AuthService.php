<?php

declare(strict_types=1);

namespace OwnRun;

use PDO;

final class AuthService
{
    public function __construct(private PDO $db) {}

    /** @return array{access_token: string, refresh_token: string, expires_in: int}|null */
    public function login(string $username, string $password, bool $rememberMe): ?array
    {
        $stmt = $this->db->prepare('SELECT id, password_hash FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return null;
        }

        return $this->issueTokens((int) $user['id'], $rememberMe);
    }

    /** @return array{access_token: string, refresh_token: string, expires_in: int}|null */
    public function refresh(string $refreshToken): ?array
    {
        $hash = hash('sha256', $refreshToken);
        $stmt = $this->db->prepare(
            'SELECT id, user_id, remember_me, expires_at, revoked_at
             FROM refresh_tokens WHERE token_hash = ? LIMIT 1'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        if (!$row || $row['revoked_at'] !== null) {
            return null;
        }
        if (strtotime($row['expires_at']) < time()) {
            return null;
        }

        $this->db->prepare('UPDATE refresh_tokens SET revoked_at = NOW() WHERE id = ?')
            ->execute([$row['id']]);

        return $this->issueTokens((int) $row['user_id'], (bool) $row['remember_me']);
    }

    public function logout(string $refreshToken): void
    {
        $hash = hash('sha256', $refreshToken);
        $this->db->prepare(
            'UPDATE refresh_tokens SET revoked_at = NOW() WHERE token_hash = ? AND revoked_at IS NULL'
        )->execute([$hash]);
    }

    public function validateAccessToken(?string $token): ?int
    {
        if ($token === null) {
            return null;
        }
        $payload = Jwt::decode($token, Config::get('jwt_secret'));
        if ($payload === null || !isset($payload['sub'])) {
            return null;
        }
        return (int) $payload['sub'];
    }

    public function validateRunnerToken(?string $token): ?int
    {
        if ($token === null) {
            return null;
        }
        $hash = hash('sha256', $token);
        $stmt = $this->db->prepare(
            'SELECT id FROM runners WHERE token_hash = ? AND enabled = 1 LIMIT 1'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        return $row ? (int) $row['id'] : null;
    }

    /** @return array{access_token: string, refresh_token: string, expires_in: int} */
    private function issueTokens(int $userId, bool $rememberMe): array
    {
        $accessTtl = (int) Config::get('access_jwt_ttl', 604800);
        $refreshTtl = $rememberMe
            ? (int) Config::get('remember_me_ttl', 31536000)
            : (int) Config::get('refresh_ttl', 2592000);

        $now = time();
        $accessToken = Jwt::encode([
            'sub' => $userId,
            'iat' => $now,
            'exp' => $now + $accessTtl,
        ], Config::get('jwt_secret'));

        $refreshToken = bin2hex(random_bytes(32));
        $refreshHash = hash('sha256', $refreshToken);
        $expiresAt = date('Y-m-d H:i:s', $now + $refreshTtl);

        $this->db->prepare(
            'INSERT INTO refresh_tokens (user_id, token_hash, expires_at, remember_me, last_used_at)
             VALUES (?, ?, ?, ?, NOW())'
        )->execute([$userId, $refreshHash, $expiresAt, $rememberMe ? 1 : 0]);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $accessTtl,
        ];
    }
}
