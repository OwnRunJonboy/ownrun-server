<?php

declare(strict_types=1);

namespace OwnRun;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class CalendarService
{
    public const TITLE_MAX = 200;
    public const DESC_MAX_BYTES = 8192;
    public const MAX_WINDOW_SECONDS = 92 * 86400;
    public const WEB_LIST_CAP = 500;
    public const AGENT_LIST_CAP = 50;

    public function __construct(private PDO $db) {}

    /**
     * @return list<string>
     */
    public function listProjectsForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT DISTINCT project FROM calendar_entries
             WHERE user_id = ? AND project IS NOT NULL AND project <> \'\'
             ORDER BY project ASC'
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
     * @return array{entries: list<array<string, mixed>>, truncated: bool}
     */
    public function listForUser(int $userId, string $fromRaw, string $toRaw, ?string $projectParam, bool $projectSpecified): array
    {
        [$from, $to] = $this->parseRange($fromRaw, $toRaw);
        $filterSql = '';
        $filterParams = [];
        if ($projectSpecified) {
            $project = trim((string) $projectParam);
            if ($project === '' || $project === '_none') {
                $filterSql = ' AND project IS NULL';
            } else {
                if (!JobService::isValidProjectName($project)) {
                    throw new \InvalidArgumentException('Invalid project name');
                }
                $filterSql = ' AND project = ?';
                $filterParams[] = $project;
            }
        }

        return $this->listIntersecting(
            $userId,
            $from,
            $to,
            $filterSql,
            $filterParams,
            self::WEB_LIST_CAP,
            false,
            null,
        );
    }

    public function getForUser(int $userId, int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM calendar_entries WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();
        return $row ? $this->formatEntry($row, false) : null;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createForUser(int $userId, array $body): array
    {
        $fields = $this->validatedWriteFields($body, true, false);
        $project = $this->optionalProjectFromBody($body, true);
        return $this->insert(
            $userId,
            $fields,
            $project,
            'user',
            null,
            null,
        );
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
        $fields = $this->validatedWriteFields($body, false, false);
        $this->assertRange($existing, $fields);
        $sets = [];
        $params = [];
        $this->applyFieldUpdates($sets, $params, $fields);

        if (array_key_exists('project', $body)) {
            $project = $this->optionalProjectFromBody($body, false);
            $sets[] = 'project = ?';
            $params[] = $project;
        }

        if ($sets === []) {
            return $this->formatEntry($existing, false);
        }

        $params[] = $id;
        $params[] = $userId;
        $sql = 'UPDATE calendar_entries SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?';
        $this->db->prepare($sql)->execute($params);
        return $this->getForUser($userId, $id);
    }

    public function deleteForUser(int $userId, int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM calendar_entries WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @return array{entries: list<array<string, mixed>>, truncated: bool}
     */
    public function listForAgent(int $runnerId, int $jobId, string $fromRaw, string $toRaw, ?string $query): array
    {
        $ctx = $this->runningAgentContext($runnerId, $jobId);
        if ($ctx === null) {
            throw new \RuntimeException('not_found');
        }
        [$from, $to] = $this->parseRange($fromRaw, $toRaw);
        [$filterSql, $filterParams] = $this->agentReadFilter($ctx['project']);
        $q = $query !== null ? trim($query) : '';
        if ($q !== '') {
            $like = $this->likeContains($q);
            $filterSql .= " AND (title LIKE ? ESCAPE '\\\\' OR description LIKE ? ESCAPE '\\\\')";
            $filterParams[] = $like;
            $filterParams[] = $like;
        }
        return $this->listIntersecting(
            $ctx['user_id'],
            $from,
            $to,
            $filterSql,
            $filterParams,
            self::AGENT_LIST_CAP,
            true,
            null,
        );
    }

    public function getForAgent(int $runnerId, int $jobId, int $id): ?array
    {
        $ctx = $this->runningAgentContext($runnerId, $jobId);
        if ($ctx === null) {
            throw new \RuntimeException('not_found');
        }
        $row = $this->getRaw($id, $ctx['user_id']);
        if ($row === null || !$this->inReadSet($row, $ctx['project'])) {
            return null;
        }
        return $this->formatEntry($row, false);
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
        if (array_key_exists('project', $body)) {
            throw new \InvalidArgumentException('project cannot be set by the agent');
        }
        $fields = $this->validatedWriteFields($body, true, true);
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
        $existing = $this->getRaw($id, $ctx['user_id']);
        if ($existing === null || !$this->inWriteSet($existing, $ctx['project'])) {
            return null;
        }
        $fields = $this->validatedWriteFields($body, false, true);
        $this->assertRange($existing, $fields);
        $sets = [];
        $params = [];
        $this->applyFieldUpdates($sets, $params, $fields);
        $sets[] = 'updated_job_id = ?';
        $params[] = $ctx['id'];
        if (count($sets) === 1) {
            return $this->formatEntry($existing, false);
        }
        $params[] = $id;
        $params[] = $ctx['user_id'];
        $sql = 'UPDATE calendar_entries SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?';
        $this->db->prepare($sql)->execute($params);
        return $this->getForUser($ctx['user_id'], $id);
    }

    public function deleteForAgent(int $runnerId, int $jobId, int $id): bool
    {
        $ctx = $this->runningAgentContext($runnerId, $jobId);
        if ($ctx === null) {
            throw new \RuntimeException('not_found');
        }
        $existing = $this->getRaw($id, $ctx['user_id']);
        if ($existing === null || !$this->inWriteSet($existing, $ctx['project'])) {
            return false;
        }
        $stmt = $this->db->prepare('DELETE FROM calendar_entries WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $ctx['user_id']]);
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
        $entryProject = $this->rowProject($row);
        if ($jobProject === null) {
            return $entryProject === null;
        }
        return $entryProject === $jobProject;
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
     * @return array{entries: list<array<string, mixed>>, truncated: bool}
     */
    private function listIntersecting(
        int $userId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        string $filterSql,
        array $filterParams,
        int $cap,
        bool $compact,
        ?string $unused,
    ): array {
        unset($unused);
        $fromSql = $this->toMysql($from);
        $toSql = $this->toMysql($to);
        $limit = $cap + 1;
        $sql = 'SELECT * FROM calendar_entries
                WHERE user_id = ?
                  AND starts_at <= ?
                  AND COALESCE(ends_at, starts_at) >= ?'
            . $filterSql
            . ' ORDER BY starts_at ASC, id ASC LIMIT ' . $limit;
        $params = array_merge([$userId, $toSql, $fromSql], $filterParams);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $truncated = count($rows) > $cap;
        if ($truncated) {
            $rows = array_slice($rows, 0, $cap);
        }
        $entries = [];
        foreach ($rows as $row) {
            $entries[] = $this->formatEntry($row, $compact);
        }
        return ['entries' => $entries, 'truncated' => $truncated];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getRaw(int $id, int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM calendar_entries WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
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
            'INSERT INTO calendar_entries
                (user_id, title, description, starts_at, ends_at, all_day, project,
                 created_by, created_job_id, updated_job_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $fields['title'],
            $fields['description'],
            $this->toMysql($fields['starts_at']),
            $fields['ends_at'] !== null ? $this->toMysql($fields['ends_at']) : null,
            $fields['all_day'] ? 1 : 0,
            $project,
            $createdBy,
            $createdJobId,
            $updatedJobId,
        ]);
        $id = (int) $this->db->lastInsertId();
        $entry = $this->getForUser($userId, $id);
        if ($entry === null) {
            throw new \RuntimeException('Failed to load created calendar entry');
        }
        return $entry;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function validatedWriteFields(array $body, bool $creating, bool $agent): array
    {
        unset($agent);
        $out = [];

        if ($creating || array_key_exists('title', $body)) {
            $title = trim((string) ($body['title'] ?? ''));
            if ($title === '') {
                throw new \InvalidArgumentException('Title is required');
            }
            if ($this->charLen($title) > self::TITLE_MAX) {
                throw new \InvalidArgumentException('Title is too long');
            }
            $out['title'] = $title;
        }

        if ($creating || array_key_exists('description', $body)) {
            $desc = $body['description'] ?? null;
            if ($desc === null || (is_string($desc) && trim($desc) === '')) {
                $out['description'] = null;
            } else {
                $text = (string) $desc;
                if (strlen($text) > self::DESC_MAX_BYTES) {
                    throw new \InvalidArgumentException('Description is too long');
                }
                $out['description'] = $text;
            }
        }

        if ($creating || array_key_exists('starts_at', $body)) {
            $raw = trim((string) ($body['starts_at'] ?? ''));
            if ($raw === '') {
                throw new \InvalidArgumentException('starts_at is required');
            }
            $out['starts_at'] = $this->parseUtc($raw);
        }

        if ($creating || array_key_exists('ends_at', $body)) {
            $endRaw = $body['ends_at'] ?? null;
            if ($endRaw === null || (is_string($endRaw) && trim($endRaw) === '')) {
                $out['ends_at'] = null;
            } else {
                $out['ends_at'] = $this->parseUtc((string) $endRaw);
            }
        }

        if ($creating || array_key_exists('all_day', $body)) {
            $out['all_day'] = $this->asBool($body['all_day'] ?? false);
        }

        if (isset($out['starts_at'], $out['ends_at']) && $out['ends_at'] !== null) {
            if ($out['ends_at'] < $out['starts_at']) {
                throw new \InvalidArgumentException('ends_at must be greater than or equal to starts_at');
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $fields
     */
    private function assertRange(array $existing, array $fields): void
    {
        $start = $fields['starts_at'] ?? $this->parseStored((string) $existing['starts_at']);
        if (array_key_exists('ends_at', $fields)) {
            $end = $fields['ends_at'];
        } elseif (!empty($existing['ends_at'])) {
            $end = $this->parseStored((string) $existing['ends_at']);
        } else {
            $end = null;
        }
        if ($end !== null && $end < $start) {
            throw new \InvalidArgumentException('ends_at must be greater than or equal to starts_at');
        }
    }

    private function parseStored(string $mysql): DateTimeImmutable
    {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $mysql, new DateTimeZone('UTC'));
        if ($dt === false) {
            $dt = new DateTimeImmutable($mysql, new DateTimeZone('UTC'));
        }
        return $dt;
    }

    /**
     * @param list<string> $sets
     * @param list<mixed> $params
     * @param array<string, mixed> $fields
     */
    private function applyFieldUpdates(array &$sets, array &$params, array $fields): void
    {
        if (array_key_exists('title', $fields)) {
            $sets[] = 'title = ?';
            $params[] = $fields['title'];
        }
        if (array_key_exists('description', $fields)) {
            $sets[] = 'description = ?';
            $params[] = $fields['description'];
        }
        if (array_key_exists('starts_at', $fields)) {
            $sets[] = 'starts_at = ?';
            $params[] = $this->toMysql($fields['starts_at']);
        }
        if (array_key_exists('ends_at', $fields)) {
            $sets[] = 'ends_at = ?';
            $params[] = $fields['ends_at'] !== null ? $this->toMysql($fields['ends_at']) : null;
        }
        if (array_key_exists('all_day', $fields)) {
            $sets[] = 'all_day = ?';
            $params[] = $fields['all_day'] ? 1 : 0;
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function optionalProjectFromBody(array $body, bool $creating): ?string
    {
        if (!array_key_exists('project', $body)) {
            return $creating ? null : null;
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
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private function parseRange(string $fromRaw, string $toRaw): array
    {
        $fromRaw = trim($fromRaw);
        $toRaw = trim($toRaw);
        if ($fromRaw === '' || $toRaw === '') {
            throw new \InvalidArgumentException('from and to are required');
        }
        $from = $this->parseUtc($fromRaw);
        $to = $this->parseUtc($toRaw);
        if ($to < $from) {
            throw new \InvalidArgumentException('to must be greater than or equal to from');
        }
        if (($to->getTimestamp() - $from->getTimestamp()) > self::MAX_WINDOW_SECONDS) {
            throw new \InvalidArgumentException('Range cannot exceed 92 days');
        }
        return [$from, $to];
    }

    private function parseUtc(string $value): DateTimeImmutable
    {
        try {
            $dt = new DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('Invalid datetime');
        }
        return $dt->setTimezone(new DateTimeZone('UTC'));
    }

    private function toMysql(DateTimeImmutable $dt): string
    {
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
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
    private function formatEntry(array $row, bool $compact): array
    {
        $entry = [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'starts_at' => $this->formatStoredUtc((string) $row['starts_at']),
            'ends_at' => !empty($row['ends_at']) ? $this->formatStoredUtc((string) $row['ends_at']) : null,
            'all_day' => (int) $row['all_day'] === 1,
            'project' => $this->rowProject($row),
        ];
        if ($compact) {
            return $entry;
        }
        $entry['description'] = $row['description'] !== null && $row['description'] !== ''
            ? (string) $row['description']
            : null;
        $entry['created_by'] = (string) $row['created_by'];
        $entry['created_job_id'] = $row['created_job_id'] !== null ? (int) $row['created_job_id'] : null;
        $entry['updated_job_id'] = $row['updated_job_id'] !== null ? (int) $row['updated_job_id'] : null;
        $entry['created_at'] = $this->formatStoredUtc((string) $row['created_at']);
        $entry['updated_at'] = $this->formatStoredUtc((string) $row['updated_at']);
        return $entry;
    }

    private function likeContains(string $q): string
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
        return '%' . $escaped . '%';
    }

    private function asBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }
        $s = strtolower(trim((string) $value));
        return in_array($s, ['1', 'true', 'yes', 'on'], true);
    }

    private function charLen(string $s): int
    {
        return function_exists('mb_strlen') ? (int) mb_strlen($s, 'UTF-8') : strlen($s);
    }
}
