<?php

declare(strict_types=1);

namespace OwnRun;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class JournalService
{
    public const TITLE_MAX = 200;
    public const BODY_MAX_BYTES = 65536;
    public const QUERY_MAX = 200;
    public const MAX_WINDOW_DAYS = 366;
    public const WEB_LIST_DEFAULT = 30;
    public const WEB_LIST_MAX = 100;
    public const AGENT_LIST_CAP = 50;
    public const AGENT_BODY_TRUNCATE = 500;
    public const DEFAULT_TIMEZONE = 'America/New_York';

    public function __construct(private PDO $db) {}

    /**
     * @return list<string>
     */
    public function listProjectsForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT DISTINCT project FROM journal_entries
             WHERE user_id = ? AND deleted_at IS NULL
               AND project IS NOT NULL AND project <> \'\'
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
     * @return array{
     *   entries: list<array<string, mixed>>,
     *   truncated: bool,
     *   next?: array{before_date: string, before_id: int}
     * }
     */
    public function listForUser(
        int $userId,
        ?string $projectParam,
        bool $projectSpecified,
        ?string $query,
        int $limit,
        ?string $beforeDate,
        ?int $beforeId,
        ?string $fromRaw,
        ?string $toRaw,
    ): array {
        $limit = max(1, min($limit, self::WEB_LIST_MAX));
        $filterSql = '';
        $filterParams = [];

        if ($projectSpecified) {
            [$filterSql, $filterParams] = $this->projectFilterSql($projectParam);
        }

        $q = $query !== null ? trim($query) : '';
        if ($q !== '') {
            if ($this->charLen($q) > self::QUERY_MAX) {
                throw new \InvalidArgumentException('Query is too long');
            }
            $like = $this->likeContains($q);
            $filterSql .= " AND (title LIKE ? ESCAPE '\\\\' OR body LIKE ? ESCAPE '\\\\')";
            $filterParams[] = $like;
            $filterParams[] = $like;
        }

        if ($fromRaw !== null || $toRaw !== null) {
            if ($fromRaw === null || $toRaw === null || trim($fromRaw) === '' || trim($toRaw) === '') {
                throw new \InvalidArgumentException('from and to are both required when filtering by date');
            }
            [$from, $to] = $this->parseDateRange($fromRaw, $toRaw);
            $filterSql .= ' AND entry_date >= ? AND entry_date <= ?';
            $filterParams[] = $from;
            $filterParams[] = $to;
        }

        if (($beforeDate !== null && $beforeDate !== '') || $beforeId !== null) {
            if ($beforeDate === null || $beforeDate === '' || $beforeId === null) {
                throw new \InvalidArgumentException('before_date and before_id are both required for pagination');
            }
            $cursorDate = $this->parseDate($beforeDate);
            $filterSql .= ' AND (entry_date < ? OR (entry_date = ? AND id < ?))';
            $filterParams[] = $cursorDate;
            $filterParams[] = $cursorDate;
            $filterParams[] = $beforeId;
        }

        return $this->listLive(
            $userId,
            $filterSql,
            $filterParams,
            $limit,
            false,
            true,
        );
    }

    public function getForUser(int $userId, int $id): ?array
    {
        $row = $this->getLiveRaw($id, $userId);
        return $row ? $this->formatEntry($row, false) : null;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createForUser(int $userId, array $body, string $timezone): array
    {
        $fields = $this->validatedWriteFields($body, true, $timezone);
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
    public function updateForUser(int $userId, int $id, array $body, string $timezone): ?array
    {
        $existing = $this->getLiveRaw($id, $userId);
        if ($existing === null) {
            return null;
        }
        $fields = $this->validatedWriteFields($body, false, $timezone);
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
        $sql = 'UPDATE journal_entries SET ' . implode(', ', $sets)
            . ' WHERE id = ? AND user_id = ? AND deleted_at IS NULL';
        $this->db->prepare($sql)->execute($params);
        return $this->getForUser($userId, $id);
    }

    public function deleteForUser(int $userId, int $id): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE journal_entries
             SET deleted_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE id = ? AND user_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @return array{entry: ?array<string, mixed>, memory_kind: ?string, memory_label: ?string}
     */
    public function memoryForUser(int $userId, string $timezone): array
    {
        $tz = $this->safeTimezone($timezone);
        $today = new DateTimeImmutable('now', $tz);
        $t = $today->format('Y-m-d');
        $seed = $this->dailySeed($userId, $t);

        $anniversaries = [
            ['kind' => 'anniversary_10y', 'label' => '10 years ago today', 'date' => $today->sub(new DateInterval('P10Y'))->format('Y-m-d')],
            ['kind' => 'anniversary_1y', 'label' => '1 year ago today', 'date' => $today->sub(new DateInterval('P1Y'))->format('Y-m-d')],
            ['kind' => 'anniversary_1m', 'label' => '1 month ago today', 'date' => $today->sub(new DateInterval('P1M'))->format('Y-m-d')],
        ];

        foreach ($anniversaries as $ann) {
            $candidates = $this->liveRowsOnDate($userId, $ann['date']);
            if ($candidates === []) {
                continue;
            }
            $picked = $candidates[$seed % count($candidates)];
            return [
                'entry' => $this->formatEntry($picked, false),
                'memory_kind' => $ann['kind'],
                'memory_label' => $ann['label'],
            ];
        }

        $countStmt = $this->db->prepare(
            'SELECT COUNT(*) FROM journal_entries
             WHERE user_id = ? AND deleted_at IS NULL AND entry_date < ?'
        );
        $countStmt->execute([$userId, $t]);
        $count = (int) $countStmt->fetchColumn();
        if ($count === 0) {
            return ['entry' => null, 'memory_kind' => null, 'memory_label' => null];
        }

        $offset = $seed % $count;
        $stmt = $this->db->prepare(
            'SELECT * FROM journal_entries
             WHERE user_id = ? AND deleted_at IS NULL AND entry_date < ?
             ORDER BY id ASC
             LIMIT 1 OFFSET ' . $offset
        );
        $stmt->execute([$userId, $t]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['entry' => null, 'memory_kind' => null, 'memory_label' => null];
        }

        $entryDate = (string) $row['entry_date'];
        $labelDate = DateTimeImmutable::createFromFormat('Y-m-d', $entryDate, $tz);
        $label = $labelDate !== false
            ? $labelDate->format('F j, Y')
            : 'From your journal';

        return [
            'entry' => $this->formatEntry($row, false),
            'memory_kind' => 'random',
            'memory_label' => $label,
        ];
    }

    /**
     * @return array{entries: list<array<string, mixed>>, truncated: bool}
     */
    public function listForAgent(
        int $runnerId,
        int $jobId,
        string $fromRaw,
        string $toRaw,
        ?string $query,
    ): array {
        $ctx = $this->runningAgentContext($runnerId, $jobId);
        if ($ctx === null) {
            throw new \RuntimeException('not_found');
        }
        [$from, $to] = $this->parseDateRange($fromRaw, $toRaw);
        [$filterSql, $filterParams] = $this->agentReadFilter($ctx['project']);
        $filterSql .= ' AND entry_date >= ? AND entry_date <= ?';
        $filterParams[] = $from;
        $filterParams[] = $to;

        $q = $query !== null ? trim($query) : '';
        if ($q !== '') {
            if ($this->charLen($q) > self::QUERY_MAX) {
                throw new \InvalidArgumentException('Query is too long');
            }
            $like = $this->likeContains($q);
            $filterSql .= " AND (title LIKE ? ESCAPE '\\\\' OR body LIKE ? ESCAPE '\\\\')";
            $filterParams[] = $like;
            $filterParams[] = $like;
        }

        $result = $this->listLive(
            $ctx['user_id'],
            $filterSql,
            $filterParams,
            self::AGENT_LIST_CAP,
            true,
            false,
        );
        return [
            'entries' => $result['entries'],
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
        return $this->formatEntry($row, false);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createForAgent(int $runnerId, int $jobId, array $body, string $timezone): array
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
        $fields = $this->validatedWriteFields($body, true, $timezone);
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
    public function updateForAgent(int $runnerId, int $jobId, int $id, array $body, string $timezone): ?array
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
        $fields = $this->validatedWriteFields($body, false, $timezone);
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
        $sql = 'UPDATE journal_entries SET ' . implode(', ', $sets)
            . ' WHERE id = ? AND user_id = ? AND deleted_at IS NULL';
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
            'UPDATE journal_entries
             SET deleted_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE id = ? AND user_id = ? AND deleted_at IS NULL'
        );
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
     * @return array{
     *   entries: list<array<string, mixed>>,
     *   truncated: bool,
     *   next?: array{before_date: string, before_id: int}
     * }
     */
    private function listLive(
        int $userId,
        string $filterSql,
        array $filterParams,
        int $cap,
        bool $compact,
        bool $withNext,
    ): array {
        $limit = $cap + 1;
        $sql = 'SELECT * FROM journal_entries
                WHERE user_id = ? AND deleted_at IS NULL'
            . $filterSql
            . ' ORDER BY entry_date DESC, id DESC LIMIT ' . $limit;
        $params = array_merge([$userId], $filterParams);
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
        $out = ['entries' => $entries, 'truncated' => $truncated];
        if ($withNext && $truncated && $rows !== []) {
            $last = $rows[count($rows) - 1];
            $out['next'] = [
                'before_date' => (string) $last['entry_date'],
                'before_id' => (int) $last['id'],
            ];
        }
        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function liveRowsOnDate(int $userId, string $entryDate): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM journal_entries
             WHERE user_id = ? AND deleted_at IS NULL AND entry_date = ?
             ORDER BY id ASC'
        );
        $stmt->execute([$userId, $entryDate]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getLiveRaw(int $id, int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM journal_entries
             WHERE id = ? AND user_id = ? AND deleted_at IS NULL
             LIMIT 1'
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
            'INSERT INTO journal_entries
                (user_id, title, body, entry_date, project,
                 created_by, created_job_id, updated_job_id, deleted_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL)'
        );
        $stmt->execute([
            $userId,
            $fields['title'],
            $fields['body'],
            $fields['entry_date'],
            $project,
            $createdBy,
            $createdJobId,
            $updatedJobId,
        ]);
        $id = (int) $this->db->lastInsertId();
        $entry = $this->getForUser($userId, $id);
        if ($entry === null) {
            throw new \RuntimeException('Failed to load created journal entry');
        }
        return $entry;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function validatedWriteFields(array $body, bool $creating, string $timezone): array
    {
        $out = [];

        if ($creating || array_key_exists('title', $body)) {
            $raw = $body['title'] ?? null;
            if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                $out['title'] = null;
            } else {
                $title = trim((string) $raw);
                if ($this->charLen($title) > self::TITLE_MAX) {
                    throw new \InvalidArgumentException('Title is too long');
                }
                $out['title'] = $title;
            }
        }

        if ($creating || array_key_exists('body', $body)) {
            $bodyText = (string) ($body['body'] ?? '');
            if (trim($bodyText) === '') {
                throw new \InvalidArgumentException('Body is required');
            }
            if (strlen($bodyText) > self::BODY_MAX_BYTES) {
                throw new \InvalidArgumentException('Body is too long');
            }
            $out['body'] = $bodyText;
        }

        if ($creating || array_key_exists('entry_date', $body)) {
            $rawDate = $body['entry_date'] ?? null;
            if ($rawDate === null || (is_string($rawDate) && trim($rawDate) === '')) {
                if ($creating) {
                    $out['entry_date'] = $this->civilToday($timezone);
                }
            } else {
                $out['entry_date'] = $this->parseDate((string) $rawDate);
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
        if (array_key_exists('title', $fields)) {
            $sets[] = 'title = ?';
            $params[] = $fields['title'];
        }
        if (array_key_exists('body', $fields)) {
            $sets[] = 'body = ?';
            $params[] = $fields['body'];
        }
        if (array_key_exists('entry_date', $fields)) {
            $sets[] = 'entry_date = ?';
            $params[] = $fields['entry_date'];
        }
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
            throw new \InvalidArgumentException('to must be greater than or equal to from');
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

    private function dailySeed(int $userId, string $ymd): int
    {
        return crc32((string) $userId . $ymd) & 0x7fffffff;
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
            'title' => $row['title'] !== null && $row['title'] !== '' ? (string) $row['title'] : null,
            'entry_date' => (string) $row['entry_date'],
            'project' => $this->rowProject($row),
        ];
        $body = (string) $row['body'];
        if ($compact) {
            if (strlen($body) > self::AGENT_BODY_TRUNCATE) {
                $entry['body'] = substr($body, 0, self::AGENT_BODY_TRUNCATE);
                $entry['body_truncated'] = true;
            } else {
                $entry['body'] = $body;
                $entry['body_truncated'] = false;
            }
            return $entry;
        }
        $entry['body'] = $body;
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

    private function charLen(string $s): int
    {
        return function_exists('mb_strlen') ? (int) mb_strlen($s, 'UTF-8') : strlen($s);
    }
}
