<?php

declare(strict_types=1);

namespace OwnRun;

use PDO;

final class SystemPromptService
{
    public const NAME_MAX = 80;
    public const BODY_MAX_BYTES = 8192;
    public const MAX_ROWS = 100;

    public function __construct(private PDO $db) {}

    /**
     * @return array{
     *   prompts: list<array<string, mixed>>,
     *   defaults: array{chat_prompt_id: ?int, agent_prompt_id: ?int},
     *   project_defaults: list<array<string, mixed>>
     * }
     */
    public function bundleForUser(int $userId): array
    {
        return [
            'prompts' => $this->listPromptsForUser($userId),
            'defaults' => $this->globalDefaultsForUser($userId),
            'project_defaults' => $this->listProjectDefaultsForUser($userId),
        ];
    }

    /**
     * @return list<string>
     */
    public function listProjectsForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT project FROM project_prompt_defaults
             WHERE user_id = ? ORDER BY project ASC'
        );
        $stmt->execute([$userId]);
        $names = [];
        foreach ($stmt->fetchAll() as $row) {
            $name = (string) $row['project'];
            if ($name !== '') {
                $names[] = $name;
            }
        }
        return $names;
    }

    public function getForUser(int $userId, int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM system_prompts WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();
        return $row ? $this->formatPrompt($row) : null;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createForUser(int $userId, array $body): array
    {
        $this->assertUnderCap($userId);
        $fields = $this->validatedWriteFields($body, true);
        $stmt = $this->db->prepare(
            'INSERT INTO system_prompts (user_id, name, body, applies_to)
             VALUES (?, ?, ?, ?)'
        );
        try {
            $stmt->execute([
                $userId,
                $fields['name'],
                $fields['body'],
                $fields['applies_to'],
            ]);
        } catch (\PDOException $e) {
            if ($this->isDuplicateName($e)) {
                throw new \InvalidArgumentException('A prompt with that name already exists');
            }
            throw $e;
        }
        $id = (int) $this->db->lastInsertId();
        $row = $this->getForUser($userId, $id);
        if ($row === null) {
            throw new \RuntimeException('Failed to create prompt');
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null
     */
    public function updateForUser(int $userId, int $id, array $body): ?array
    {
        if ($this->getRaw($id, $userId) === null) {
            return null;
        }
        $fields = $this->validatedWriteFields($body, false);
        $sets = [];
        $params = [];
        foreach (['name', 'body', 'applies_to'] as $key) {
            if (array_key_exists($key, $fields)) {
                $sets[] = "{$key} = ?";
                $params[] = $fields[$key];
            }
        }
        if ($sets === []) {
            return $this->getForUser($userId, $id);
        }
        $params[] = $id;
        $params[] = $userId;
        $sql = 'UPDATE system_prompts SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?';
        try {
            $this->db->prepare($sql)->execute($params);
        } catch (\PDOException $e) {
            if ($this->isDuplicateName($e)) {
                throw new \InvalidArgumentException('A prompt with that name already exists');
            }
            throw $e;
        }
        return $this->getForUser($userId, $id);
    }

    public function deleteForUser(int $userId, int $id): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM system_prompts WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $body
     * @return array{chat_prompt_id: ?int, agent_prompt_id: ?int}
     */
    public function setGlobalDefaults(int $userId, array $body): array
    {
        if (!array_key_exists('chat_prompt_id', $body) && !array_key_exists('agent_prompt_id', $body)) {
            throw new \InvalidArgumentException('At least one default field is required');
        }

        $existing = $this->globalDefaultsForUser($userId);
        $chatId = $existing['chat_prompt_id'];
        $agentId = $existing['agent_prompt_id'];

        if (array_key_exists('chat_prompt_id', $body)) {
            $chatId = $this->resolveDefaultSlotValue($userId, $body['chat_prompt_id'], 'chat');
        }
        if (array_key_exists('agent_prompt_id', $body)) {
            $agentId = $this->resolveDefaultSlotValue($userId, $body['agent_prompt_id'], 'agent');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO user_prompt_defaults (user_id, chat_prompt_id, agent_prompt_id)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                chat_prompt_id = VALUES(chat_prompt_id),
                agent_prompt_id = VALUES(agent_prompt_id)'
        );
        $stmt->execute([$userId, $chatId, $agentId]);

        return $this->globalDefaultsForUser($userId);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function setProjectDefaults(int $userId, string $project, array $body): array
    {
        if (!array_key_exists('chat_prompt_id', $body) && !array_key_exists('agent_prompt_id', $body)) {
            throw new \InvalidArgumentException('At least one default field is required');
        }

        $project = trim($project);
        if (!JobService::isValidProjectName($project)) {
            throw new \InvalidArgumentException(
                'Invalid project name: use 1–64 chars starting with a letter or digit; '
                . "only letters, digits, '.', '_', and '-' allowed"
            );
        }

        $existing = $this->getProjectDefaultsRow($userId, $project);
        $chatId = $existing !== null ? $existing['chat_prompt_id'] : null;
        $agentId = $existing !== null ? $existing['agent_prompt_id'] : null;

        if (array_key_exists('chat_prompt_id', $body)) {
            $chatId = $this->resolveDefaultSlotValue($userId, $body['chat_prompt_id'], 'chat');
        }
        if (array_key_exists('agent_prompt_id', $body)) {
            $agentId = $this->resolveDefaultSlotValue($userId, $body['agent_prompt_id'], 'agent');
        }

        if ($chatId === null && $agentId === null) {
            $this->deleteProjectDefaults($userId, $project);
            return [
                'project' => $project,
                'chat_prompt_id' => null,
                'agent_prompt_id' => null,
            ];
        }

        $stmt = $this->db->prepare(
            'INSERT INTO project_prompt_defaults (user_id, project, chat_prompt_id, agent_prompt_id)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                chat_prompt_id = VALUES(chat_prompt_id),
                agent_prompt_id = VALUES(agent_prompt_id)'
        );
        $stmt->execute([$userId, $project, $chatId, $agentId]);

        $row = $this->getProjectDefaultsRow($userId, $project);
        return $row ?? [
            'project' => $project,
            'chat_prompt_id' => $chatId,
            'agent_prompt_id' => $agentId,
        ];
    }

    public function deleteProjectDefaults(int $userId, string $project): bool
    {
        $project = trim($project);
        if (!JobService::isValidProjectName($project)) {
            throw new \InvalidArgumentException('Invalid project name');
        }
        $stmt = $this->db->prepare(
            'DELETE FROM project_prompt_defaults WHERE user_id = ? AND project = ?'
        );
        $stmt->execute([$userId, $project]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listPromptsForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM system_prompts WHERE user_id = ? ORDER BY name ASC'
        );
        $stmt->execute([$userId]);
        return array_map([$this, 'formatPrompt'], $stmt->fetchAll());
    }

    /**
     * @return array{chat_prompt_id: ?int, agent_prompt_id: ?int}
     */
    private function globalDefaultsForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT chat_prompt_id, agent_prompt_id
             FROM user_prompt_defaults WHERE user_id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['chat_prompt_id' => null, 'agent_prompt_id' => null];
        }
        return [
            'chat_prompt_id' => $row['chat_prompt_id'] !== null ? (int) $row['chat_prompt_id'] : null,
            'agent_prompt_id' => $row['agent_prompt_id'] !== null ? (int) $row['agent_prompt_id'] : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listProjectDefaultsForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT project, chat_prompt_id, agent_prompt_id
             FROM project_prompt_defaults WHERE user_id = ? ORDER BY project ASC'
        );
        $stmt->execute([$userId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'project' => (string) $row['project'],
                'chat_prompt_id' => $row['chat_prompt_id'] !== null ? (int) $row['chat_prompt_id'] : null,
                'agent_prompt_id' => $row['agent_prompt_id'] !== null ? (int) $row['agent_prompt_id'] : null,
            ];
        }
        return $rows;
    }

    /**
     * @return array{project: string, chat_prompt_id: ?int, agent_prompt_id: ?int}|null
     */
    private function getProjectDefaultsRow(int $userId, string $project): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT project, chat_prompt_id, agent_prompt_id
             FROM project_prompt_defaults WHERE user_id = ? AND project = ? LIMIT 1'
        );
        $stmt->execute([$userId, $project]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            'project' => (string) $row['project'],
            'chat_prompt_id' => $row['chat_prompt_id'] !== null ? (int) $row['chat_prompt_id'] : null,
            'agent_prompt_id' => $row['agent_prompt_id'] !== null ? (int) $row['agent_prompt_id'] : null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function getRaw(int $id, int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM system_prompts WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function assertUnderCap(int $userId): void
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM system_prompts WHERE user_id = ?');
        $stmt->execute([$userId]);
        if ((int) $stmt->fetchColumn() >= self::MAX_ROWS) {
            throw new \InvalidArgumentException(
                'Prompt library is full (' . self::MAX_ROWS . ' prompts maximum)'
            );
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, string>
     */
    private function validatedWriteFields(array $body, bool $isCreate): array
    {
        $out = [];
        if ($isCreate || array_key_exists('name', $body)) {
            $name = trim((string) ($body['name'] ?? ''));
            if ($name === '') {
                throw new \InvalidArgumentException('Name is required');
            }
            if (strlen($name) > self::NAME_MAX) {
                throw new \InvalidArgumentException('Name is too long (max ' . self::NAME_MAX . ' chars)');
            }
            $out['name'] = $name;
        }
        if ($isCreate || array_key_exists('body', $body)) {
            $text = (string) ($body['body'] ?? '');
            if (trim($text) === '') {
                throw new \InvalidArgumentException('Body is required');
            }
            if (strlen($text) > self::BODY_MAX_BYTES) {
                throw new \InvalidArgumentException(
                    'Body is too large (max ' . self::BODY_MAX_BYTES . ' bytes)'
                );
            }
            $out['body'] = $text;
        }
        if ($isCreate || array_key_exists('applies_to', $body)) {
            $applies = (string) ($body['applies_to'] ?? 'both');
            if (!in_array($applies, ['chat', 'agent', 'both'], true)) {
                throw new \InvalidArgumentException('applies_to must be chat, agent, or both');
            }
            $out['applies_to'] = $applies;
        } elseif ($isCreate) {
            $out['applies_to'] = 'both';
        }
        return $out;
    }

    /**
     * @param mixed $value
     */
    private function resolveDefaultSlotValue(int $userId, mixed $value, string $slot): ?int
    {
        if ($value === null) {
            return null;
        }
        $id = (int) $value;
        if ($id < 1) {
            throw new \InvalidArgumentException('Invalid prompt id');
        }
        $prompt = $this->getForUser($userId, $id);
        if ($prompt === null) {
            throw new \InvalidArgumentException('Prompt not found');
        }
        $applies = (string) $prompt['applies_to'];
        if ($slot === 'chat' && !in_array($applies, ['chat', 'both'], true)) {
            throw new \InvalidArgumentException('That prompt cannot be used as a chat default');
        }
        if ($slot === 'agent' && !in_array($applies, ['agent', 'both'], true)) {
            throw new \InvalidArgumentException('That prompt cannot be used as an agent default');
        }
        return $id;
    }

    private function isDuplicateName(\PDOException $e): bool
    {
        return str_contains($e->getMessage(), 'uk_user_name')
            || $e->getCode() === '23000';
    }

    /** @param array<string, mixed> $row */
    private function formatPrompt(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'body' => (string) $row['body'],
            'applies_to' => (string) $row['applies_to'],
            'created_at' => self::toIsoUtc((string) $row['created_at']),
            'updated_at' => self::toIsoUtc((string) $row['updated_at']),
        ];
    }

    private static function toIsoUtc(string $mysql): string
    {
        $dt = new \DateTimeImmutable($mysql, new \DateTimeZone('UTC'));
        return $dt->format('Y-m-d\TH:i:s\Z');
    }
}
