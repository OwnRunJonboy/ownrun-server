<?php

declare(strict_types=1);

namespace OwnRun;

use PDO;

final class AuthService
{
    /**
     * Precomputed Argon2id hash so missing-user logins take about the same
     * time as a real password_verify().
     */
    private const DUMMY_PASSWORD_HASH = '$argon2id$v=19$m=65536,t=4,p=1$TlhTek5jajd3WFFJZXk3OQ$QFDId1gmFITM8lDpXffYvBjH18HKiVNi1ONcNBtdPnA';

    public function __construct(private PDO $db) {}

    /** @return array{access_token: string, refresh_token: string, expires_in: int}|null */
    public function login(string $username, string $password, bool $rememberMe, string $ip): ?array
    {
        $usernameKey = $this->attemptKey($username);
        $ip = $this->normalizeIp($ip);

        $stmt = $this->db->prepare(
            'SELECT id, password_hash, failed_login_count, locked_until
             FROM users WHERE username = ? LIMIT 1'
        );
        $stmt->execute([$username]);
        $user = $stmt->fetch() ?: null;

        $this->assertNotLocked($user);
        $this->decayStaleFailureCount($user, $usernameKey);
        $this->assertUnderAttemptCap('ip', $ip, (int) Config::get('login_max_attempts_per_ip', 20));
        $this->assertUnderAttemptCap(
            'username',
            $usernameKey,
            (int) Config::get('login_max_attempts_per_username', 5)
        );

        $hash = is_array($user) ? (string) $user['password_hash'] : self::DUMMY_PASSWORD_HASH;
        $verified = password_verify($password, $hash);
        $ok = is_array($user) && $verified;

        if (!$ok) {
            $this->recordFailure($usernameKey, $ip, is_array($user) ? (int) $user['id'] : null);
            return null;
        }

        $this->recordSuccess((int) $user['id'], $usernameKey, $ip);
        return $this->issueTokens((int) $user['id'], $rememberMe);
    }

    /** @return array{access_token: string, refresh_token: string, expires_in: int}|null */
    public function refresh(string $refreshToken): ?array
    {
        $hash = hash('sha256', $refreshToken);
        $stmt = $this->db->prepare(
            'SELECT id, user_id, remember_me, expires_at, revoked_at, family_id
             FROM refresh_tokens WHERE token_hash = ? LIMIT 1'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $familyId = (string) $row['family_id'];
        $userId = (int) $row['user_id'];

        if ($row['revoked_at'] !== null) {
            // Reuse of a rotated token. Concurrent tabs may refresh the
            // same token within a short window; only treat older reuse as theft.
            $live = $this->db->prepare(
                'SELECT created_at FROM refresh_tokens
                 WHERE family_id = ? AND revoked_at IS NULL
                 ORDER BY id DESC LIMIT 1'
            );
            $live->execute([$familyId]);
            $siblingCreated = $live->fetchColumn();
            if ($siblingCreated !== false && strtotime((string) $siblingCreated) >= time() - 60) {
                return $this->issueTokens($userId, (bool) $row['remember_me'], $familyId);
            }
            $this->revokeFamily($familyId);
            $this->bumpTokenVersion($userId);
            return null;
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            return null;
        }

        $this->db->prepare(
            'UPDATE refresh_tokens SET revoked_at = NOW(), last_used_at = NOW() WHERE id = ?'
        )->execute([(int) $row['id']]);

        return $this->issueTokens($userId, (bool) $row['remember_me'], $familyId);
    }

    public function logout(string $refreshToken): void
    {
        $hash = hash('sha256', $refreshToken);
        $stmt = $this->db->prepare(
            'SELECT user_id, family_id FROM refresh_tokens WHERE token_hash = ? LIMIT 1'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        if (!$row) {
            return;
        }

        $this->revokeFamily((string) $row['family_id']);
        $this->bumpTokenVersion((int) $row['user_id']);
    }

    public function validateAccessToken(?string $token): ?int
    {
        if ($token === null) {
            return null;
        }
        $payload = Jwt::decode($token, Config::get('jwt_secret'));
        if ($payload === null || !isset($payload['sub'], $payload['ver'])) {
            return null;
        }

        $userId = (int) $payload['sub'];
        $stmt = $this->db->prepare('SELECT token_version FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $version = $stmt->fetchColumn();
        if ($version === false || (int) $version !== (int) $payload['ver']) {
            return null;
        }

        return $userId;
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
    private function issueTokens(int $userId, bool $rememberMe, ?string $familyId = null): array
    {
        $accessTtl = (int) Config::get('access_jwt_ttl', 900);
        $refreshTtl = $rememberMe
            ? (int) Config::get('remember_me_ttl', 2592000)
            : (int) Config::get('refresh_ttl', 604800);

        $verStmt = $this->db->prepare('SELECT token_version FROM users WHERE id = ? LIMIT 1');
        $verStmt->execute([$userId]);
        $version = (int) $verStmt->fetchColumn();
        if ($version < 1) {
            $version = 1;
        }

        $now = time();
        $accessToken = Jwt::encode([
            'sub' => $userId,
            'ver' => $version,
            'iat' => $now,
            'exp' => $now + $accessTtl,
        ], Config::get('jwt_secret'));

        $refreshToken = bin2hex(random_bytes(32));
        $refreshHash = hash('sha256', $refreshToken);
        $expiresAt = date('Y-m-d H:i:s', $now + $refreshTtl);
        $familyId = ($familyId !== null && $familyId !== '')
            ? $familyId
            : bin2hex(random_bytes(32));

        $this->db->prepare(
            'INSERT INTO refresh_tokens (user_id, token_hash, family_id, expires_at, remember_me, last_used_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        )->execute([$userId, $refreshHash, $familyId, $expiresAt, $rememberMe ? 1 : 0]);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $accessTtl,
        ];
    }

    private function revokeFamily(string $familyId): void
    {
        if ($familyId === '') {
            return;
        }
        $this->db->prepare(
            'UPDATE refresh_tokens SET revoked_at = NOW()
             WHERE family_id = ? AND revoked_at IS NULL'
        )->execute([$familyId]);
    }

    private function bumpTokenVersion(int $userId): void
    {
        $this->db->prepare(
            'UPDATE users SET token_version = token_version + 1 WHERE id = ?'
        )->execute([$userId]);
    }

    /** @param array<string, mixed>|null $user */
    private function assertNotLocked(?array $user): void
    {
        if ($user === null || $user['locked_until'] === null) {
            return;
        }
        $until = strtotime((string) $user['locked_until']);
        if ($until === false || $until <= time()) {
            return;
        }
        $this->throttle($until - time());
    }

    /**
     * Reset the backoff counter after a quiet window so old typos do not
     * immediately re-lock the account.
     *
     * @param array<string, mixed>|null $user
     */
    private function decayStaleFailureCount(?array &$user, string $usernameKey): void
    {
        if ($user === null || (int) $user['failed_login_count'] === 0) {
            return;
        }
        if ($this->failureCount('username', $usernameKey) > 0) {
            return;
        }
        $this->db->prepare(
            'UPDATE users SET failed_login_count = 0, locked_until = NULL WHERE id = ?'
        )->execute([(int) $user['id']]);
        $user['failed_login_count'] = 0;
        $user['locked_until'] = null;
    }

    private function assertUnderAttemptCap(string $field, string $value, int $max): void
    {
        if ($max < 1) {
            return;
        }
        $count = $this->failureCount($field, $value);
        if ($count < $max) {
            return;
        }
        if ($field === 'ip') {
            error_log("OwnRun login IP throttle: ip={$value} failures={$count}");
        } else {
            error_log("OwnRun login username throttle: username={$value} failures={$count}");
        }
        $this->throttle($this->retryAfterFor($field, $value));
    }

    private function recordFailure(string $usernameKey, string $ip, ?int $userId): void
    {
        $this->insertAttempt($usernameKey, $ip, false);
        $this->pruneAttempts();

        if ($userId === null) {
            return;
        }

        $threshold = (int) Config::get('login_max_attempts_per_username', 5);
        $stmt = $this->db->prepare(
            'UPDATE users SET failed_login_count = failed_login_count + 1 WHERE id = ?'
        );
        $stmt->execute([$userId]);

        $countStmt = $this->db->prepare('SELECT failed_login_count FROM users WHERE id = ?');
        $countStmt->execute([$userId]);
        $failures = (int) $countStmt->fetchColumn();
        if ($failures < $threshold) {
            return;
        }

        $seconds = $this->lockoutSeconds($failures);
        $this->db->prepare(
            'UPDATE users SET locked_until = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id = ?'
        )->execute([$seconds, $userId]);
        error_log(
            "OwnRun account locked: username={$usernameKey} failures={$failures} lockout={$seconds}s"
        );
    }

    private function recordSuccess(int $userId, string $usernameKey, string $ip): void
    {
        $this->insertAttempt($usernameKey, $ip, true);
        $this->db->prepare(
            'UPDATE users SET failed_login_count = 0, locked_until = NULL WHERE id = ?'
        )->execute([$userId]);
        $this->pruneAttempts();
    }

    private function insertAttempt(string $usernameKey, string $ip, bool $succeeded): void
    {
        $this->db->prepare(
            'INSERT INTO login_attempts (username, ip, succeeded) VALUES (?, ?, ?)'
        )->execute([$usernameKey, $ip, $succeeded ? 1 : 0]);
    }

    private function failureCount(string $field, string $value): int
    {
        $column = $field === 'ip' ? 'ip' : 'username';
        $window = (int) Config::get('login_attempt_window', 900);
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE {$column} = ? AND succeeded = 0
               AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)"
        );
        $stmt->execute([$value, $window]);
        return (int) $stmt->fetchColumn();
    }

    private function retryAfterFor(string $field, string $value): int
    {
        $column = $field === 'ip' ? 'ip' : 'username';
        $window = (int) Config::get('login_attempt_window', 900);
        $stmt = $this->db->prepare(
            "SELECT UNIX_TIMESTAMP(MIN(created_at)) FROM login_attempts
             WHERE {$column} = ? AND succeeded = 0
               AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)"
        );
        $stmt->execute([$value, $window]);
        $oldest = (int) $stmt->fetchColumn();
        if ($oldest <= 0) {
            return $window;
        }
        return max(1, $oldest + $window - time());
    }

    private function lockoutSeconds(int $failures): int
    {
        $threshold = (int) Config::get('login_max_attempts_per_username', 5);
        $base = (int) Config::get('login_lockout_base', 300);
        $cap = (int) Config::get('login_lockout_cap', 3600);
        $exp = max(0, $failures - $threshold);
        $seconds = $base * (2 ** min($exp, 8));
        return max(1, min($cap, $seconds));
    }

    private function pruneAttempts(): void
    {
        $this->db->exec(
            'DELETE FROM login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)'
        );
    }

    private function throttle(int $retryAfterSeconds): void
    {
        $retryAfterSeconds = max(1, $retryAfterSeconds);
        throw new LoginThrottledException(
            $retryAfterSeconds,
            'Too many login attempts. Try again in ' . $this->formatWait($retryAfterSeconds) . '.'
        );
    }

    private function formatWait(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' second' . ($seconds === 1 ? '' : 's');
        }
        $minutes = (int) ceil($seconds / 60);
        if ($minutes < 60) {
            return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
        }
        $hours = (int) ceil($minutes / 60);
        return $hours . ' hour' . ($hours === 1 ? '' : 's');
    }

    private function attemptKey(string $username): string
    {
        $key = strtolower($username);
        if (function_exists('mb_strtolower')) {
            $key = mb_strtolower($username, 'UTF-8');
        }
        return substr($key, 0, 64);
    }

    private function normalizeIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
        return '0.0.0.0';
    }
}
