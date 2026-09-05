<?php

declare(strict_types=1);

namespace OwnRun;

use PDO;

final class ToolPolicyService
{
    public const MAX_DISABLED_PER_JOB = 20;

    /** @var list<string> */
    private const DEFAULT_TOOL_IDS = [
        'now',
        'list_dir',
        'read_file',
        'write_file',
        'http_fetch',
        'web_search',
        'calendar_search',
        'calendar_get',
        'calendar_add',
        'calendar_edit',
        'calendar_delete',
        'journal_search',
        'journal_get',
        'journal_add',
        'journal_edit',
        'journal_delete',
        'task_search',
        'task_get',
        'task_add',
        'task_edit',
        'task_delete',
        'kb_list',
        'kb_search',
        'kb_get',
    ];

    public function __construct(private PDO $db) {}

    /** @return list<string> */
    public static function catalog(): array
    {
        $ids = Config::get('agent_tool_ids');
        if (!is_array($ids) || $ids === []) {
            return self::DEFAULT_TOOL_IDS;
        }
        return array_values(array_map('strval', $ids));
    }

    /**
     * @return list<array{project: string, disabled_tools: list<string>, updated_at: string}>
     */
    public function listForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT project, disabled_tools, updated_at FROM project_tool_policies
             WHERE user_id = ? ORDER BY project ASC'
        );
        $stmt->execute([$userId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = $this->formatRow($row);
        }
        return $out;
    }

    /**
     * @return list<string>
     */
    public function listProjectsForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT project FROM project_tool_policies WHERE user_id = ? ORDER BY project ASC'
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

    /** @return array{project: string, disabled_tools: list<string>, updated_at: string}|null */
    public function getForUser(int $userId, string $project): ?array
    {
        if (!JobService::isValidProjectName($project)) {
            throw new \InvalidArgumentException(
                'Invalid project name: use 1–64 chars starting with a letter or digit; '
                . "only letters, digits, '.', '_', and '-' allowed"
            );
        }
        $stmt = $this->db->prepare(
            'SELECT project, disabled_tools, updated_at FROM project_tool_policies
             WHERE user_id = ? AND project = ? LIMIT 1'
        );
        $stmt->execute([$userId, $project]);
        $row = $stmt->fetch();
        return $row ? $this->formatRow($row) : null;
    }

    /**
     * @param array<string, mixed> $body
     * @return array{project: string, disabled_tools: list<string>, updated_at: string}
     */
    public function setForUser(int $userId, string $project, array $body): array
    {
        if (!JobService::isValidProjectName($project)) {
            throw new \InvalidArgumentException(
                'Invalid project name: use 1–64 chars starting with a letter or digit; '
                . "only letters, digits, '.', '_', and '-' allowed"
            );
        }
        $disabled = $this->validatedDisabledTools($body['disabled_tools'] ?? []);
        $json = json_encode(array_values($disabled));
        $stmt = $this->db->prepare(
            'INSERT INTO project_tool_policies (user_id, project, disabled_tools)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE disabled_tools = VALUES(disabled_tools)'
        );
        $stmt->execute([$userId, $project, $json]);
        $row = $this->getForUser($userId, $project);
        if ($row === null) {
            throw new \RuntimeException('Failed to save tool policy');
        }
        return $row;
    }

    public function deleteForUser(int $userId, string $project): bool
    {
        if (!JobService::isValidProjectName($project)) {
            throw new \InvalidArgumentException(
                'Invalid project name: use 1–64 chars starting with a letter or digit; '
                . "only letters, digits, '.', '_', and '-' allowed"
            );
        }
        $stmt = $this->db->prepare(
            'DELETE FROM project_tool_policies WHERE user_id = ? AND project = ?'
        );
        $stmt->execute([$userId, $project]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @return list<string>
     */
    public function disabledForProject(int $userId, ?string $project): array
    {
        if ($project === null || $project === '') {
            return [];
        }
        $stmt = $this->db->prepare(
            'SELECT disabled_tools FROM project_tool_policies
             WHERE user_id = ? AND project = ? LIMIT 1'
        );
        $stmt->execute([$userId, $project]);
        $row = $stmt->fetch();
        if (!$row) {
            return [];
        }
        return $this->decodeDisabledList($row['disabled_tools'] ?? null);
    }

    /**
     * Project-level disables for a running agent job (runner auth).
     *
     * @return list<string>
     */
    public function disabledForRunningJob(int $runnerId, int $jobId): array
    {
        $stmt = $this->db->prepare(
            "SELECT user_id, request_payload FROM jobs
             WHERE id = ? AND runner_id = ? AND status = 'running' AND type = 'agent.task'
             LIMIT 1"
        );
        $stmt->execute([$jobId, $runnerId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new \RuntimeException('not_found');
        }
        $request = json_decode((string) $row['request_payload'], true);
        $project = '';
        if (is_array($request)) {
            $project = trim((string) ($request['project'] ?? ''));
        }
        if ($project === '') {
            return [];
        }
        return $this->disabledForProject((int) $row['user_id'], $project);
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    public function validatedDisabledTools(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('disabled_tools must be an array');
        }
        if (count($raw) > self::MAX_DISABLED_PER_JOB) {
            throw new \InvalidArgumentException(
                'At most ' . self::MAX_DISABLED_PER_JOB . ' disabled tools allowed'
            );
        }
        $catalog = self::catalog();
        $catalogSet = array_fill_keys($catalog, true);
        $out = [];
        $seen = [];
        foreach ($raw as $item) {
            $id = trim((string) $item);
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            if ($catalog !== [] && !isset($catalogSet[$id])) {
                throw new \InvalidArgumentException("Unknown tool id: {$id}");
            }
            $seen[$id] = true;
            $out[] = $id;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $body
     * @return list<string>
     */
    public function parseJobDisabledTools(array $body): array
    {
        $disabled = [];
        if (array_key_exists('disabled_tools', $body)) {
            $disabled = $this->validatedDisabledTools($body['disabled_tools']);
        }
        if (array_key_exists('web_search', $body) && $body['web_search'] === false) {
            if (!in_array('web_search', $disabled, true)) {
                $disabled[] = 'web_search';
            }
        }
        return $disabled;
    }

    /** @param array<string, mixed> $row */
    private function formatRow(array $row): array
    {
        return [
            'project' => (string) $row['project'],
            'disabled_tools' => $this->decodeDisabledList($row['disabled_tools'] ?? null),
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /** @return list<string> */
    private function decodeDisabledList(mixed $raw): array
    {
        if (!$raw) {
            return [];
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_map('strval', $decoded));
    }
}
