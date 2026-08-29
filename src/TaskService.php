<?php

declare(strict_types=1);

namespace OwnRun;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class TaskService
{
    public const NAME_MAX = 200;
    public const DESC_MAX_BYTES = 8192;
    public const QUERY_MAX = 200;
    public const MAX_WINDOW_DAYS = 366;
    public const WEB_LIST_DEFAULT = 50;
    public const WEB_LIST_MAX = 100;
    public const AGENT_LIST_CAP = 50;
    public const AGENT_DESC_TRUNCATE = 200;
    public const DUE_CARD_CAP = 10;
    public const DEFAULT_TIMEZONE = 'America/New_York';

    private const PRIORITIES = ['high', 'normal', 'low'];
    private const LIVE_STATUSES = ['todo', 'in_progress', 'finished'];
    private const ALL_STATUSES = ['todo', 'in_progress', 'finished', 'deleted'];
    private const OPEN_STATUSES = ['todo', 'in_progress'];

    public function __construct(private PDO $db) {}

    /**
     * @return list<string>
     */
    public function listProjectsForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT project FROM task_entries
             WHERE user_id = ? AND status <> 'deleted'
               AND project IS NOT NULL AND project <> ''
             ORDER BY project ASC"
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

    /**
     * @return array{tasks: list<array<string, mixed>>, truncated: bool, total: int}
     */
    public function listForUser(
        int $userId,
        ?string $projectParam,
        bool $projectSpecified,
        ?string $statusRaw,
        ?string $priorityRaw,
        ?string $query,
        ?string $dueFromRaw,
        ?string $dueToRaw,
        int $limit,
        int $offset,
    ): array {
        $limit = max(1, min($limit, self::WEB_LIST_MAX));
        $offset = max(0, $offset);
        $statuses = $this->parseStatuses($statusRaw, true, true);
        [$filterSql, $filterParams] = $this->listFilters(
            $projectSpecified,
            $projectParam,
            $statuses,
            $priorityRaw,
            $query,
            $dueFromRaw,
            $dueToRaw,
        );
        return $this->listRows($userId, $filterSql, $filterParams, $limit, $offset, false);
    }

    public function getForUser(int $userId, int $id): ?array
    {
        $row = $this->getRaw($id, $userId);
        return $row ? $this->formatTask($row, false) : null;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createForUser(int $userId, array $body): array
    {
        $fields = $this->validatedWriteFields($body, true, true);
        $project = $this->optionalProjectFromBody($body, true);
        return $this->insert($userId, $fields, $project, 'user', null, null);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null
     */
    public function updateForUser(int $userId, int $id, array $body): ?array
    {
        $existing = $this->getRaw($id, $userId);
        if ($existing === null) {
            return null;
        }
        $fields = $this->validatedWriteFields($body, false, true);
        $sets = [];
        $params = [];
        $this->applyFieldUpdates($sets, $params, $fields);

        if (array_key_exists('project', $body)) {
            $project = $this->optionalProjectFromBody($body, false);
            $sets[] = 'project = ?';
            $params[] = $project;
        }

        if ($sets === []) {
            return $this->formatTask($existing, false);
        }

        $params[] = $id;
        $params[] = $userId;
        $sql = 'UPDATE task_entries SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?';
        $this->db->prepare($sql)->execute($params);
        return $this->getForUser($userId, $id);
    }

    public function deleteForUser(int $userId, int $id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE task_entries
             SET status = 'deleted'
             WHERE id = ? AND user_id = ? AND status <> 'deleted'"
        );
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @return array{
     *   today: string,
     *   overdue: int,
     *   due_today: int,
     *   open: int,
     *   tasks: list<array<string, mixed>>
     * }
     */
    public function dueForUser(int $userId, string $timezone): array
    {
        $today = $this->civilToday($timezone);
        $countStmt = $this->db->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN due_date < ? THEN 1 ELSE 0 END), 0) AS overdue,
                COALESCE(SUM(CASE WHEN due_date = ? THEN 1 ELSE 0 END), 0) AS due_today,
                COUNT(*) AS open_count
             FROM task_entries
             WHERE user_id = ? AND status IN ('todo', 'in_progress')"
        );
        $countStmt->execute([$today, $today, $userId]);
        $counts = $countStmt->fetch() ?: [];

        $listStmt = $this->db->prepare(
            "SELECT * FROM task_entries
             WHERE user_id = ? AND status IN ('todo', 'in_progress') AND due_date IS NOT NULL AND due_date <= ?
             ORDER BY due_date IS NULL, due_date ASC, FIELD(priority, 'high', 'normal', 'low'), id ASC
             LIMIT " . self::DUE_CARD_CAP
        );
        $listStmt->execute([$userId, $today]);
        $tasks = [];
        foreach ($listStmt->fetchAll() as $row) {
            $tasks[] = $this->formatTask($row, false);
        }

        return [
            'today' => $today,
            'overdue' => (int) ($counts['overdue'] ?? 0),
            'due_today' => (int) ($counts['due_today'] ?? 0),
            'open' => (int) ($counts['open_count'] ?? 0),
            'tasks' => $tasks,
        ];
    }

    /**
     * @return array{tasks: list<array<string, mixed>>, truncated: bool}
     */
    public function listForAgent(
        int $runnerId,
        int $jobId,
        ?string $statusRaw,
        ?string $priorityRaw,
        ?string $query,
        ?string $dueFromRaw,
        ?string $dueToRaw,
    ): array {
        $ctx = $this->runningAgentContext($runnerId, $jobId);
        if ($ctx === null) {
            throw new \RuntimeException('not_found');
        }
        $statuses = $this->parseStatuses($statusRaw, false, true);
        [$readSql, $readParams] = $this->agentReadFilter($ctx['project']);
        [$extraSql, $extraParams] = $this->listFilters(
            false,
            null,
            $statuses,
            $priorityRaw,
            $query,
            $dueFromRaw,
            $dueToRaw,
            false,
        );
        $result = $this->listRows(
            $ctx['user_id'],
            $readSql . $extraSql,
            array_merge($readParams, $extraParams),
            self::AGENT_LIST_CAP,
            0,
            true,
        );
        return [
            'tasks' => $result['tasks'],
            'truncated' => $result['truncated'],
        ];
    }

    public function getForAgent(int $runnerId, int $jobId, int $id): ?array
    {
        $ctx = $this->runningAgentContext($runnerId, $jobId);
        if ($ctx === null) {
            throw new \RuntimeException('not_found');
        }
        $row = $this->getLiveRaw($id, $ctx['user_id']);
        if ($row === null || !$this->inReadSet($row, $ctx['project'])) {
            return null;
        }
        return $this->formatTask($row, false);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createForAgent(int $runnerId, int $jobId, array $body): array
    {
        $ctx = $this->runningAgentContext($runnerId, $jobId);
        if ($ctx === null) {
            throw new \RuntimeException('not_found');
        }
        if ($ctx['project'] === null) {
            throw new \RuntimeException('forbidden');
        }
        if (array_key_exists('project', $body)) {
            throw new \InvalidArgumentException('project cannot be set by the agent');
        }
        $fields = $this->validatedWriteFields($body, true, false);
        return $this->insert(
            $ctx['user_id'],
            $fields,
            $ctx['project'],
            'agent',
            $ctx['id'],
            null,
        );
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null
     */
    public function updateForAgent(int $runnerId, int $jobId, int $id, array $body): ?array
    {
        $ctx = $this->runningAgentContext($runnerId, $jobId);
        if ($ctx === null) {
            throw new \RuntimeException('not_found');
        }
        if (array_key_exists('project', $body)) {
            throw new \InvalidArgumentException('project cannot be set by the agent');
        }
        $existing = $this->getLiveRaw($id, $ctx['user_id']);
        if ($existing === null || !$this->inWriteSet($existing, $ctx['project'])) {
            return null;
        }
        $fields = $this->validatedWriteFields($body, false, false);
        $sets = [];
        $params = [];
        $this->applyFieldUpdates($sets, $params, $fields);
        $sets[] = 'updated_job_id = ?';
        $params[] = $ctx['id'];
        if (count($sets) === 1) {
            return $this->formatTask($existing, false);
        }
        $params[] = $id;
        $params[] = $ctx['user_id'];
        $sql = "UPDATE task_entries SET " . implode(', ', $sets)
            . " WHERE id = ? AND user_id = ? AND status <> 'deleted'";
        $this->db->prepare($sql)->execute($params);
        return $this->getForUser($ctx['user_id'], $id);
    }

    public function deleteForAgent(int $runnerId, int $jobId, int $id): bool
    {
        $ctx = $this->runningAgentContext($runnerId, $jobId);
        if ($ctx === null) {
            throw new \RuntimeException('not_found');
        }
        $existing = $this->getLiveRaw($id, $ctx['user_id']);
        if ($existing === null || !$this->inWriteSet($existing, $ctx['project'])) {
            return false;
        }
        $stmt = $this->db->prepare(
            "UPDATE task_entries
             SET status = 'deleted', updated_job_id = ?
             WHERE id = ? AND user_id = ? AND status <> 'deleted'"
        );
        $stmt->execute([$ctx['id'], $id, $ctx['user_id']]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @return array{id: int, user_id: int, project: ?string}|null
     */
    private function runningAgentContext(int $runnerId, int $jobId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, user_id, request_payload FROM jobs
             WHERE id = ? AND runner_id = ? AND status = 'running' AND type = 'agent.task'
             LIMIT 1"
        );
        $stmt->execute([$jobId, $runnerId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $request = json_decode((string) $row['request_payload'], true);
        $project = '';
        if (is_array($request)) {
            $project = trim((string) ($request['project'] ?? ''));
        }
        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'project' => $project !== '' ? $project : null,
        ];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function agentReadFilter(?string $project): array
    {
        if ($project === null) {
            return [' AND project IS NULL', []];
        }
        return [' AND (project IS NULL OR project = ?)', [$project]];
    }

    /**
     * @param list<string> $statuses
     * @return array{0: string, 1: list<mixed>}
     */
    private function listFilters(
        bool $projectSpecified,
        ?string $projectParam,
        array $statuses,
        ?string $priorityRaw,
        ?string $query,
        ?string $dueFromRaw,
        ?string $dueToRaw,
        bool $includeProject = true,
    ): array {
        $sql = '';
        $params = [];

        if ($includeProject && $projectSpecified) {
            [$pSql, $pParams] = $this->projectFilterSql($projectParam);
            $sql .= $pSql;
            $params = array_merge($params, $pParams);
        }

        if ($statuses !== []) {
            $placeholders = implode(', ', array_fill(0, count($statuses), '?'));
            $sql .= " AND status IN ({$placeholders})";
            foreach ($statuses as $status) {
                $params[] = $status;
            }
        }

        $priority = $this->parsePriority($priorityRaw, false);
        if ($priority !== null) {
            $sql .= ' AND priority = ?';
            $params[] = $priority;
        }

        $q = $query !== null ? trim($query) : '';
        if ($q !== '') {
            if ($this->charLen($q) > self::QUERY_MAX) {
                throw new \InvalidArgumentException('Query is too long');
            }
            $like = $this->likeContains($q);
            $sql .= " AND (`name` LIKE ? ESCAPE '\\\\' OR description LIKE ? ESCAPE '\\\\')";
            $params[] = $like;
            $params[] = $like;
        }

        if ($dueFromRaw !== null || $dueToRaw !== null) {
            if ($dueFromRaw === null || $dueToRaw === null || trim($dueFromRaw) === '' || trim($dueToRaw) === '') {
                throw new \InvalidArgumentException('due_from and due_to are both required when filtering by due date');
            }
            [$from, $to] = $this->parseDateRange($dueFromRaw, $dueToRaw);
            $sql .= ' AND due_date IS NOT NULL AND due_date >= ? AND due_date <= ?';
            $params[] = $from;
            $params[] = $to;
        }

        return [$sql, $params];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function projectFilterSql(?string $projectParam): array
    {
        $project = trim((string) $projectParam);
        if ($project === '' || $project === '_none') {
            return [' AND project IS NULL', []];
        }
        if (!JobService::isValidProjectName($project)) {
            throw new \InvalidArgumentException('Invalid project name');
        }
        return [' AND project = ?', [$project]];
    }

    private function inReadSet(array $row, ?string $jobProject): bool
    {
        $entryProject = $this->rowProject($row);
        if ($entryProject === null) {
            return true;
        }
        return $jobProject !== null && $entryProject === $jobProject;
    }

    private function inWriteSet(array $row, ?string $jobProject): bool
    {
        if ($jobProject === null) {
            return false;
        }
        return $this->rowProject($row) === $jobProject;
    }

    private function rowProject(array $row): ?string
    {
        $p = $row['project'] ?? null;
        if ($p === null || $p === '') {
            return null;
        }
        return (string) $p;
    }

    /**
     * @param list<mixed> $filterParams
     * @return array{tasks: list<array<string, mixed>>, truncated: bool, total: int}
     */
    private function listRows(
        int $userId,
        string $filterSql,
        array $filterParams,
        int $cap,
        int $offset,
        bool $compact,
    ): array {
        $countSql = 'SELECT COUNT(*) FROM task_entries WHERE user_id = ?' . $filterSql;
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute(array_merge([$userId], $filterParams));
        $total = (int) $countStmt->fetchColumn();

        $sql = 'SELECT * FROM task_entries WHERE user_id = ?' . $filterSql
            . " ORDER BY due_date IS NULL, due_date ASC, FIELD(priority, 'high', 'normal', 'low'), id ASC"
            . ' LIMIT ' . $cap . ' OFFSET ' . $offset;
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge([$userId], $filterParams));
        $tasks = [];
        foreach ($stmt->fetchAll() as $row) {
            $tasks[] = $this->formatTask($row, $compact);
        }
        return [
            'tasks' => $tasks,
            'truncated' => ($offset + count($tasks)) < $total,
            'total' => $total,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getRaw(int $id, int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM task_entries WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getLiveRaw(int $id, int $userId): ?array
    {
        $row = $this->getRaw($id, $userId);
        if ($row === null || (string) $row['status'] === 'deleted') {
            return null;
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function insert(
        int $userId,
        array $fields,
        ?string $project,
        string $createdBy,
        ?int $createdJobId,
        ?int $updatedJobId,
    ): array {
        $stmt = $this->db->prepare(
            'INSERT INTO task_entries
                (user_id, name, description, due_date, project, priority, status,
                 created_by, created_job_id, updated_job_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $fields['name'],
            $fields['description'],
            $fields['due_date'],
            $project,
            $fields['priority'],
            $fields['status'],
            $createdBy,
            $createdJobId,
            $updatedJobId,
        ]);
        $id = (int) $this->db->lastInsertId();
        $task = $this->getForUser($userId, $id);
        if ($task === null) {
            throw new \RuntimeException('Failed to load created task');
        }
        return $task;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function validatedWriteFields(array $body, bool $creating, bool $allowDeletedStatus): array
    {
        $out = [];

        if ($creating || array_key_exists('name', $body)) {
            $name = trim((string) ($body['name'] ?? ''));
            if ($name === '') {
                throw new \InvalidArgumentException('Name is required');
            }
            if ($this->charLen($name) > self::NAME_MAX) {
                throw new \InvalidArgumentException('Name is too long');
            }
            $out['name'] = $name;
        }

        if ($creating || array_key_exists('description', $body)) {
            $raw = $body['description'] ?? null;
            if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                $out['description'] = null;
            } else {
                $text = (string) $raw;
                if (strlen($text) > self::DESC_MAX_BYTES) {
                    throw new \InvalidArgumentException('Description is too long');
                }
                $out['description'] = $text;
            }
        }

        if ($creating || array_key_exists('due_date', $body)) {
            $rawDate = $body['due_date'] ?? null;
            if ($rawDate === null || (is_string($rawDate) && trim($rawDate) === '')) {
                $out['due_date'] = null;
            } else {
                $out['due_date'] = $this->parseDate((string) $rawDate);
            }
        }

        if ($creating || array_key_exists('priority', $body)) {
            $raw = $body['priority'] ?? null;
            if ($creating && ($raw === null || (is_string($raw) && trim($raw) === ''))) {
                $out['priority'] = 'normal';
            } else {
                $priority = $this->parsePriority(is_string($raw) || $raw === null ? $raw : (string) $raw, true);
                if ($priority === null) {
                    throw new \InvalidArgumentException('Invalid priority');
                }
                $out['priority'] = $priority;
            }
        }

        if ($creating || array_key_exists('status', $body)) {
            $raw = $body['status'] ?? null;
            if ($creating && ($raw === null || (is_string($raw) && trim($raw) === ''))) {
                $out['status'] = 'todo';
            } else {
                $status = trim((string) $raw);
                $allowed = $allowDeletedStatus ? self::ALL_STATUSES : self::LIVE_STATUSES;
                if (!in_array($status, $allowed, true)) {
                    if ($status === 'deleted' && !$allowDeletedStatus) {
                        throw new \InvalidArgumentException('Use delete to set status deleted');
                    }
                    throw new \InvalidArgumentException('Invalid status');
                }
                if ($creating && $status === 'deleted') {
                    throw new \InvalidArgumentException('Cannot create a deleted task');
                }
                $out['status'] = $status;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $sets
     * @param list<mixed> $params
     * @param array<string, mixed> $fields
     */
    private function applyFieldUpdates(array &$sets, array &$params, array $fields): void
    {
        foreach (['name', 'description', 'due_date', 'priority', 'status'] as $key) {
            if (array_key_exists($key, $fields)) {
                $sets[] = "{$key} = ?";
                $params[] = $fields[$key];
            }
        }
    }

    /**
     * @return list<string>
     */
    private function parseStatuses(?string $raw, bool $allowDeleted, bool $defaultOpen): array
    {
        if ($raw === null || trim($raw) === '') {
            return $defaultOpen ? self::OPEN_STATUSES : [];
        }
        $parts = preg_split('/\s*,\s*/', trim($raw)) ?: [];
        $allowed = $allowDeleted ? self::ALL_STATUSES : self::LIVE_STATUSES;
        $out = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (!in_array($part, $allowed, true)) {
                if ($part === 'deleted' && !$allowDeleted) {
                    throw new \InvalidArgumentException('deleted tasks cannot be listed here');
                }
                throw new \InvalidArgumentException('Invalid status');
            }
            $out[] = $part;
        }
        if ($out === []) {
            throw new \InvalidArgumentException('Invalid status');
        }
        return array_values(array_unique($out));
    }

    private function parsePriority(?string $raw, bool $required): ?string
    {
        if ($raw === null || trim($raw) === '') {
            if ($required) {
                throw new \InvalidArgumentException('Invalid priority');
            }
            return null;
        }
        $priority = trim($raw);
        if (!in_array($priority, self::PRIORITIES, true)) {
            throw new \InvalidArgumentException('Invalid priority');
        }
        return $priority;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function optionalProjectFromBody(array $body, bool $creating): ?string
    {
        unset($creating);
        if (!array_key_exists('project', $body)) {
            return null;
        }
        $raw = $body['project'];
        if ($raw === null) {
            return null;
        }
        $project = trim((string) $raw);
        if ($project === '') {
            return null;
        }
        if (!JobService::isValidProjectName($project)) {
            throw new \InvalidArgumentException(
                'Invalid project name: use 1–64 chars starting with a letter or digit; '
                . "only letters, digits, '.', '_', and '-' allowed"
            );
        }
        return $project;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseDateRange(string $fromRaw, string $toRaw): array
    {
        $from = $this->parseDate($fromRaw);
        $to = $this->parseDate($toRaw);
        if ($to < $from) {
            throw new \InvalidArgumentException('due_to must be greater than or equal to due_from');
        }
        $fromDt = DateTimeImmutable::createFromFormat('Y-m-d', $from, new DateTimeZone('UTC'));
        $toDt = DateTimeImmutable::createFromFormat('Y-m-d', $to, new DateTimeZone('UTC'));
        if ($fromDt === false || $toDt === false) {
            throw new \InvalidArgumentException('Invalid date');
        }
        $days = (int) $fromDt->diff($toDt)->format('%a');
        if ($days > self::MAX_WINDOW_DAYS) {
            throw new \InvalidArgumentException('Range cannot exceed 366 days');
        }
        return [$from, $to];
    }

    private function parseDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException('Invalid date');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $m)) {
            $value = $m[0];
        }
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if ($dt === false || $dt->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Invalid date');
        }
        return $value;
    }

    private function civilToday(string $timezone): string
    {
        $tz = $this->safeTimezone($timezone);
        return (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    }

    private function safeTimezone(string $timezone): DateTimeZone
    {
        $name = trim($timezone);
        if ($name === '') {
            $name = self::DEFAULT_TIMEZONE;
        }
        try {
            return new DateTimeZone($name);
        } catch (\Exception $e) {
            return new DateTimeZone(self::DEFAULT_TIMEZONE);
        }
    }

    private function formatStoredUtc(string $mysql): string
    {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $mysql, new DateTimeZone('UTC'));
        if ($dt === false) {
            $dt = new DateTimeImmutable($mysql, new DateTimeZone('UTC'));
        }
        return $dt->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatTask(array $row, bool $compact): array
    {
        $task = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'due_date' => $row['due_date'] !== null && $row['due_date'] !== '' ? (string) $row['due_date'] : null,
            'project' => $this->rowProject($row),
            'priority' => (string) $row['priority'],
            'status' => (string) $row['status'],
        ];
        $description = $row['description'] !== null && $row['description'] !== ''
            ? (string) $row['description']
            : null;
        if ($compact) {
            if ($description !== null && strlen($description) > self::AGENT_DESC_TRUNCATE) {
                $task['description'] = substr($description, 0, self::AGENT_DESC_TRUNCATE);
                $task['description_truncated'] = true;
            } else {
                $task['description'] = $description;
                $task['description_truncated'] = false;
            }
            return $task;
        }
        $task['description'] = $description;
        $task['created_by'] = (string) $row['created_by'];
        $task['created_job_id'] = $row['created_job_id'] !== null ? (int) $row['created_job_id'] : null;
        $task['updated_job_id'] = $row['updated_job_id'] !== null ? (int) $row['updated_job_id'] : null;
        $task['created_at'] = $this->formatStoredUtc((string) $row['created_at']);
        $task['updated_at'] = $this->formatStoredUtc((string) $row['updated_at']);
        return $task;
    }

    private function likeContains(string $q): string
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
        return '%' . $escaped . '%';
    }

    private function charLen(string $s): int
    {
        return function_exists('mb_strlen') ? (int) mb_strlen($s, 'UTF-8') : strlen($s);
    }
}
