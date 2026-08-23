<?php

declare(strict_types=1);

namespace OwnRun;

use PDO;

final class JobService
{
    private const JOB_SELECT = 'id, type, status, request_payload, result_payload, progress_payload,
                    error_message, created_at, queued_at, claimed_at, finished_at';

    public function __construct(private PDO $db) {}

    /** @return list<array<string, mixed>> */
    public function listForUser(int $userId): array
    {
        $this->expireLeases();
        $stmt = $this->db->prepare(
            'SELECT ' . self::JOB_SELECT . '
             FROM jobs WHERE user_id = ? ORDER BY id DESC LIMIT 200'
        );
        $stmt->execute([$userId]);
        return array_map([$this, 'formatJob'], $stmt->fetchAll());
    }

    public function getForUser(int $userId, int $jobId): ?array
    {
        $this->expireLeases();
        $stmt = $this->db->prepare(
            'SELECT ' . self::JOB_SELECT . '
             FROM jobs WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$jobId, $userId]);
        $row = $stmt->fetch();
        return $row ? $this->formatJob($row) : null;
    }

    public function create(
        int $userId,
        string $model,
        string $prompt,
        ?string $systemPrompt = null,
    ): array {
        return $this->insertJob($userId, 'ollama.chat', [
            'model' => $model,
            'prompt' => $prompt,
            'system_prompt' => $systemPrompt,
        ]);
    }

    public function createAgent(
        int $userId,
        string $model,
        string $prompt,
        string $profile,
        ?string $systemPrompt = null,
        ?int $maxToolIterations = null,
    ): array {
        $allowed = Config::get('agent_profiles', ['workspace', 'web', 'workspace_web']);
        if (!is_array($allowed) || !in_array($profile, $allowed, true)) {
            throw new \InvalidArgumentException('Unknown agent profile');
        }

        $defaultIter = (int) Config::get('agent_default_tool_iterations', 8);
        $maxIterCap = (int) Config::get('agent_max_tool_iterations', 20);
        $iterations = $maxToolIterations ?? $defaultIter;
        if ($iterations < 1 || $iterations > $maxIterCap) {
            throw new \InvalidArgumentException(
                "max_tool_iterations must be between 1 and {$maxIterCap}"
            );
        }

        return $this->insertJob($userId, 'agent.task', [
            'model' => $model,
            'prompt' => $prompt,
            'system_prompt' => $systemPrompt,
            'profile' => $profile,
            'max_tool_iterations' => $iterations,
        ]);
    }

    public function continueFrom(int $userId, int $jobId, string $prompt): ?array
    {
        $parent = $this->getForUser($userId, $jobId);
        if ($parent === null) {
            return null;
        }
        if ($parent['status'] !== 'succeeded') {
            throw new \DomainException('Only succeeded jobs can be continued');
        }

        $request = is_array($parent['request'] ?? null) ? $parent['request'] : [];
        $model = trim((string) ($request['model'] ?? ''));
        if ($model === '') {
            throw new \DomainException('Only succeeded jobs can be continued');
        }

        $type = (string) ($parent['type'] ?? 'ollama.chat');
        $systemPrompt = isset($request['system_prompt']) ? trim((string) $request['system_prompt']) : null;

        if ($type === 'agent.task') {
            $profile = trim((string) ($request['profile'] ?? ''));
            if ($profile === '') {
                throw new \DomainException('Parent agent job is missing a profile');
            }
            $maxIter = isset($request['max_tool_iterations'])
                ? (int) $request['max_tool_iterations']
                : (int) Config::get('agent_default_tool_iterations', 8);

            $messages = $this->agentContinuationMessages($parent);
            $messages[] = ['role' => 'user', 'content' => $prompt];

            return $this->insertJob($userId, 'agent.task', [
                'model' => $model,
                'prompt' => $prompt,
                'system_prompt' => $systemPrompt,
                'profile' => $profile,
                'max_tool_iterations' => $maxIter,
                'messages' => $messages,
                'parent_job_id' => (int) $parent['id'],
            ]);
        }

        $messages = $this->conversationFromJob($parent);
        $result = is_array($parent['result'] ?? null) ? $parent['result'] : [];
        $messages[] = ['role' => 'assistant', 'content' => (string) ($result['text'] ?? '')];
        $messages[] = ['role' => 'user', 'content' => $prompt];

        return $this->insertJob($userId, 'ollama.chat', [
            'model' => $model,
            'prompt' => $prompt,
            'system_prompt' => $systemPrompt,
            'messages' => $messages,
            'parent_job_id' => (int) $parent['id'],
        ]);
    }

    public function cancel(int $userId, int $jobId): ?array
    {
        $stmt = $this->db->prepare(
            "UPDATE jobs SET status = 'cancelled', finished_at = NOW(), lease_expires_at = NULL
             WHERE id = ? AND user_id = ? AND status IN ('queued', 'running')"
        );
        $stmt->execute([$jobId, $userId]);
        if ($stmt->rowCount() === 0) {
            return null;
        }
        return $this->getForUser($userId, $jobId);
    }

    public function requeue(int $userId, int $jobId): ?array
    {
        $stmt = $this->db->prepare(
            'UPDATE jobs SET status = ?, runner_id = NULL, result_payload = NULL,
                    progress_payload = NULL, error_message = NULL, claimed_at = NULL,
                    finished_at = NULL, lease_expires_at = NULL, queued_at = NOW()
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
                        lease_expires_at = ?, progress_payload = NULL
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

    /**
     * Extend lease while running, or report cancel if the job was cancelled.
     *
     * @return array{cancel_requested: bool}|null null if not owned / not found
     */
    public function heartbeat(int $runnerId, int $jobId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT status FROM jobs WHERE id = ? AND runner_id = ? LIMIT 1'
        );
        $stmt->execute([$jobId, $runnerId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $status = (string) $row['status'];
        if ($status === 'cancelled') {
            return ['cancel_requested' => true];
        }
        if ($status !== 'running') {
            return null;
        }

        $leaseSeconds = (int) Config::get('job_lease_timeout', 3600);
        $leaseExpires = date('Y-m-d H:i:s', time() + $leaseSeconds);
        $update = $this->db->prepare(
            "UPDATE jobs SET lease_expires_at = ?
             WHERE id = ? AND runner_id = ? AND status = 'running'"
        );
        $update->execute([$leaseExpires, $jobId, $runnerId]);
        if ($update->rowCount() === 0) {
            // Race: cancelled between SELECT and UPDATE
            return ['cancel_requested' => true];
        }
        return ['cancel_requested' => false];
    }

    /** @param array<string, mixed> $result */
    public function progress(int $runnerId, int $jobId, array $result): bool
    {
        $encoded = $this->encodeResult($result);
        $stmt = $this->db->prepare(
            "UPDATE jobs SET progress_payload = ?
             WHERE id = ? AND runner_id = ? AND status = 'running'"
        );
        $stmt->execute([$encoded, $jobId, $runnerId]);
        return $stmt->rowCount() > 0;
    }

    /** @param array<string, mixed> $result */
    public function complete(int $runnerId, int $jobId, array $result): bool
    {
        $encoded = $this->encodeResult($result);
        $stmt = $this->db->prepare(
            "UPDATE jobs SET status = 'succeeded', result_payload = ?,
                    progress_payload = NULL, finished_at = NOW(),
                    lease_expires_at = NULL, error_message = NULL
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

    /**
     * @param array{
     *   model: string,
     *   prompt: string,
     *   system_prompt?: ?string,
     *   messages?: list<array{role: string, content: string}>,
     *   parent_job_id?: int,
     *   profile?: string,
     *   max_tool_iterations?: int
     * } $fields
     * @return array<string, mixed>
     */
    private function insertJob(int $userId, string $type, array $fields): array
    {
        $payload = [
            'model' => $fields['model'],
            'prompt' => $fields['prompt'],
        ];
        $systemPrompt = $fields['system_prompt'] ?? null;
        if (is_string($systemPrompt) && $systemPrompt !== '') {
            $payload['system_prompt'] = $systemPrompt;
        }
        if (isset($fields['messages'])) {
            $payload['messages'] = $fields['messages'];
        }
        if (isset($fields['parent_job_id'])) {
            $payload['parent_job_id'] = $fields['parent_job_id'];
        }
        if (isset($fields['profile'])) {
            $payload['profile'] = $fields['profile'];
        }
        if (isset($fields['max_tool_iterations'])) {
            $payload['max_tool_iterations'] = $fields['max_tool_iterations'];
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
        $stmt->execute([$userId, $type, 'queued', $encoded]);
        $id = (int) $this->db->lastInsertId();

        $job = $this->getForUser($userId, $id);
        if ($job === null) {
            throw new \RuntimeException('Failed to create job');
        }
        return $job;
    }

    /** @param array<string, mixed> $result */
    private function encodeResult(array $result): string
    {
        $encoded = json_encode($result);
        $maxBytes = (int) Config::get('max_result_payload_bytes', 524288);
        if ($encoded === false) {
            throw new \InvalidArgumentException('Result payload is not valid JSON');
        }
        if (strlen($encoded) <= $maxBytes) {
            return $encoded;
        }

        // Prefer keeping final text; trim oldest trace steps.
        if (isset($result['trace']) && is_array($result['trace'])) {
            $trace = $result['trace'];
            while (count($trace) > 1) {
                array_shift($trace);
                $result['trace'] = $trace;
                $encoded = json_encode($result);
                if ($encoded !== false && strlen($encoded) <= $maxBytes) {
                    return $encoded;
                }
            }
        }

        // Last resort: drop trace entirely.
        unset($result['trace']);
        $result['trace_truncated'] = true;
        $encoded = json_encode($result);
        if ($encoded === false || strlen($encoded) > $maxBytes) {
            throw new \InvalidArgumentException('Result payload too large');
        }
        return $encoded;
    }

    /**
     * Compact history for agent continue: prior user prompts + final answers only.
     *
     * @param array<string, mixed> $job
     * @return list<array{role: string, content: string}>
     */
    private function agentContinuationMessages(array $job): array
    {
        $request = is_array($job['request'] ?? null) ? $job['request'] : [];
        $messages = [];

        $raw = $request['messages'] ?? null;
        if (is_array($raw) && $raw !== []) {
            foreach ($raw as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $role = (string) ($item['role'] ?? '');
                $content = $item['content'] ?? null;
                if (!in_array($role, ['user', 'assistant'], true)) {
                    continue;
                }
                if (!is_string($content) && !is_numeric($content)) {
                    continue;
                }
                $messages[] = ['role' => $role, 'content' => (string) $content];
            }
        }

        if ($messages === []) {
            $prompt = (string) ($request['prompt'] ?? '');
            if ($prompt !== '') {
                $messages[] = ['role' => 'user', 'content' => $prompt];
            }
        }

        $result = is_array($job['result'] ?? null) ? $job['result'] : [];
        $text = (string) ($result['text'] ?? '');
        if ($text !== '') {
            $messages[] = ['role' => 'assistant', 'content' => $text];
        }

        return $messages;
    }

    /**
     * @param array<string, mixed> $job
     * @return list<array{role: string, content: string}>
     */
    private function conversationFromJob(array $job): array
    {
        $request = is_array($job['request'] ?? null) ? $job['request'] : [];
        $raw = $request['messages'] ?? null;
        if (is_array($raw) && $raw !== []) {
            $messages = [];
            foreach ($raw as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $role = (string) ($item['role'] ?? '');
                $content = $item['content'] ?? null;
                if (!in_array($role, ['system', 'user', 'assistant'], true)) {
                    continue;
                }
                if (!is_string($content) && !is_numeric($content)) {
                    continue;
                }
                $messages[] = ['role' => $role, 'content' => (string) $content];
            }
            if ($messages !== []) {
                return $messages;
            }
        }

        $messages = [];
        $system = isset($request['system_prompt']) ? trim((string) $request['system_prompt']) : '';
        if ($system !== '') {
            $messages[] = ['role' => 'system', 'content' => $system];
        }
        $prompt = (string) ($request['prompt'] ?? '');
        if ($prompt !== '') {
            $messages[] = ['role' => 'user', 'content' => $prompt];
        }
        return $messages;
    }

    /** @return array<string, mixed>|null */
    private function getById(int $jobId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, runner_id, type, status, request_payload, result_payload,
                    progress_payload, error_message, created_at, queued_at, claimed_at, finished_at
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
        $progressRaw = $row['progress_payload'] ?? null;
        return [
            'id' => (int) $row['id'],
            'type' => $row['type'],
            'status' => $row['status'],
            'request' => json_decode($row['request_payload'], true),
            'result' => $row['result_payload'] ? json_decode($row['result_payload'], true) : null,
            'progress' => $progressRaw ? json_decode((string) $progressRaw, true) : null,
            'error_message' => $row['error_message'],
            'created_at' => $row['created_at'],
            'queued_at' => $row['queued_at'],
            'claimed_at' => $row['claimed_at'],
            'finished_at' => $row['finished_at'],
        ];
    }
}
