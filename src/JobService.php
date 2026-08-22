<?php

declare(strict_types=1);

namespace OwnRun;

use PDO;

final class JobService
{
    public function __construct(private PDO $db) {}

    /** @return list<array<string, mixed>> */
    public function listForUser(int $userId): array
    {
        $this->expireLeases();
        $stmt = $this->db->prepare(
            'SELECT id, type, status, request_payload, result_payload, error_message,
                    created_at, queued_at, claimed_at, finished_at
             FROM jobs WHERE user_id = ? ORDER BY id DESC LIMIT 200'
        );
        $stmt->execute([$userId]);
        return array_map([$this, 'formatJob'], $stmt->fetchAll());
    }

    public function getForUser(int $userId, int $jobId): ?array
    {
        $this->expireLeases();
        $stmt = $this->db->prepare(
            'SELECT id, type, status, request_payload, result_payload, error_message,
                    created_at, queued_at, claimed_at, finished_at
             FROM jobs WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$jobId, $userId]);
        $row = $stmt->fetch();
        return $row ? $this->formatJob($row) : null;
    }

    public function create(int $userId, string $model, string $prompt, ?string $systemPrompt = null): array
    {
        $payload = [
            'model' => $model,
            'prompt' => $prompt,
        ];
        if ($systemPrompt !== null && $systemPrompt !== '') {
            $payload['system_prompt'] = $systemPrompt;
        }

        $encoded = json_encode($payload);
        $maxBytes = (int) Config::get('max_request_payload_bytes', 65536);
        if ($encoded === false || strlen($encoded) > $maxBytes) {
            throw new \InvalidArgumentException('Request payload too large');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO jobs (user_id, type, status, request_payload, queued_at)
             VALUES (?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$userId, 'ollama.chat', 'queued', $encoded]);
        $id = (int) $this->db->lastInsertId();

        $job = $this->getForUser($userId, $id);
        if ($job === null) {
            throw new \RuntimeException('Failed to create job');
        }
        return $job;
    }

    public function cancel(int $userId, int $jobId): ?array
    {
        $stmt = $this->db->prepare(
            'UPDATE jobs SET status = ?, finished_at = NOW()
             WHERE id = ? AND user_id = ? AND status = ?'
        );
        $stmt->execute(['cancelled', $jobId, $userId, 'queued']);
        if ($stmt->rowCount() === 0) {
            return null;
        }
        return $this->getForUser($userId, $jobId);
    }

    public function requeue(int $userId, int $jobId): ?array
    {
        $stmt = $this->db->prepare(
            'UPDATE jobs SET status = ?, runner_id = NULL, result_payload = NULL,
                    error_message = NULL, claimed_at = NULL, finished_at = NULL,
                    lease_expires_at = NULL, queued_at = NOW()
             WHERE id = ? AND user_id = ? AND status = ?'
        );
        $stmt->execute(['queued', $jobId, $userId, 'failed']);
        if ($stmt->rowCount() === 0) {
            return null;
        }
        return $this->getForUser($userId, $jobId);
    }

    /** @return array<string, mixed>|null */
    public function claim(int $runnerId): ?array
    {
        $this->expireLeases();
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->query(
                "SELECT id FROM jobs WHERE status = 'queued' ORDER BY id ASC LIMIT 1 FOR UPDATE"
            );
            $row = $stmt->fetch();
            if (!$row) {
                $this->db->commit();
                return null;
            }

            $jobId = (int) $row['id'];
            $leaseSeconds = (int) Config::get('job_lease_timeout', 3600);
            $leaseExpires = date('Y-m-d H:i:s', time() + $leaseSeconds);

            $update = $this->db->prepare(
                "UPDATE jobs SET status = 'running', runner_id = ?, claimed_at = NOW(),
                        lease_expires_at = ?
                 WHERE id = ? AND status = 'queued'"
            );
            $update->execute([$runnerId, $leaseExpires, $jobId]);
            if ($update->rowCount() === 0) {
                $this->db->rollBack();
                return null;
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $this->getById($jobId);
    }

    public function heartbeat(int $runnerId, int $jobId): bool
    {
        $leaseSeconds = (int) Config::get('job_lease_timeout', 3600);
        $leaseExpires = date('Y-m-d H:i:s', time() + $leaseSeconds);
        $stmt = $this->db->prepare(
            "UPDATE jobs SET lease_expires_at = ?
             WHERE id = ? AND runner_id = ? AND status = 'running'"
        );
        $stmt->execute([$leaseExpires, $jobId, $runnerId]);
        return $stmt->rowCount() > 0;
    }

    /** @param array<string, mixed> $result */
    public function complete(int $runnerId, int $jobId, array $result): bool
    {
        $encoded = json_encode($result);
        $stmt = $this->db->prepare(
            "UPDATE jobs SET status = 'succeeded', result_payload = ?,
                    finished_at = NOW(), lease_expires_at = NULL, error_message = NULL
             WHERE id = ? AND runner_id = ? AND status = 'running'"
        );
        $stmt->execute([$encoded, $jobId, $runnerId]);
        return $stmt->rowCount() > 0;
    }

    public function fail(int $runnerId, int $jobId, string $errorMessage): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE jobs SET status = 'failed', error_message = ?,
                    finished_at = NOW(), lease_expires_at = NULL
             WHERE id = ? AND runner_id = ? AND status = 'running'"
        );
        $stmt->execute([$errorMessage, $jobId, $runnerId]);
        return $stmt->rowCount() > 0;
    }

    /** @return array<string, mixed>|null */
    private function getById(int $jobId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, runner_id, type, status, request_payload, result_payload,
                    error_message, created_at, queued_at, claimed_at, finished_at
             FROM jobs WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$jobId]);
        $row = $stmt->fetch();
        return $row ? $this->formatJob($row) : null;
    }

    private function expireLeases(): void
    {
        $this->db->exec(
            "UPDATE jobs SET status = 'failed',
                    error_message = 'Runner lease expired',
                    finished_at = NOW(), lease_expires_at = NULL
             WHERE status = 'running'
               AND lease_expires_at IS NOT NULL
               AND lease_expires_at < NOW()"
        );
    }

    /** @param array<string, mixed> $row */
    private function formatJob(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'type' => $row['type'],
            'status' => $row['status'],
            'request' => json_decode($row['request_payload'], true),
            'result' => $row['result_payload'] ? json_decode($row['result_payload'], true) : null,
            'error_message' => $row['error_message'],
            'created_at' => $row['created_at'],
            'queued_at' => $row['queued_at'],
            'claimed_at' => $row['claimed_at'],
            'finished_at' => $row['finished_at'],
        ];
    }
}
