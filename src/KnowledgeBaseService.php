<?php

declare(strict_types=1);

namespace OwnRun;

use PDO;

final class KnowledgeBaseService
{
    public const NAME_MAX = 80;
    public const SLUG_MAX = 64;
    public const DESCRIPTION_MAX = 500;
    public const FILENAME_MAX = 255;
    public const MAX_BASES_DEFAULT = 50;
    public const MAX_DOCS_PER_KB_DEFAULT = 200;
    public const MAX_UPLOAD_BYTES_DEFAULT = 32 * 1024 * 1024;

    private const ALLOWED_FORMATS = [
        'pdf' => ['application/pdf'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
        ],
        'epub' => [
            'application/epub+zip',
            'application/zip',
            'application/octet-stream',
        ],
        'md' => [
            'text/markdown',
            'text/x-markdown',
            'text/plain',
            'application/octet-stream',
        ],
        'txt' => [
            'text/plain',
            'application/octet-stream',
        ],
    ];

    public function __construct(
        private PDO $db,
        private JobService $jobs,
    ) {}

    /** @return list<string> */
    public function listProjectsForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT DISTINCT project FROM kb_bases
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
     * @return list<array<string, mixed>>
     */
    public function listBasesForUser(int $userId, ?string $projectParam, bool $projectSpecified): array
    {
        $sql = 'SELECT b.*,
                    (SELECT COUNT(*) FROM kb_documents d
                     WHERE d.kb_id = b.id AND d.deleted_at IS NULL) AS document_count,
                    (SELECT COUNT(*) FROM kb_documents d
                     WHERE d.kb_id = b.id AND d.deleted_at IS NULL AND d.status = \'ready\') AS ready_count,
                    (SELECT COUNT(*) FROM kb_documents d
                     WHERE d.kb_id = b.id AND d.deleted_at IS NULL AND d.status IN (\'pending\',\'queued\',\'running\')) AS pending_count,
                    (SELECT COUNT(*) FROM kb_documents d
                     WHERE d.kb_id = b.id AND d.deleted_at IS NULL AND d.status = \'failed\') AS failed_count
                FROM kb_bases b WHERE b.user_id = ?';
        $params = [$userId];

        if ($projectSpecified) {
            [$filterSql, $filterParams] = $this->projectFilterSql($projectParam, 'b.');
            $sql .= $filterSql;
            $params = array_merge($params, $filterParams);
        }

        $sql .= ' ORDER BY b.name ASC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'formatBase'], $stmt->fetchAll());
    }

    public function getBaseForUser(int $userId, int $kbId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT b.*,
                    (SELECT COUNT(*) FROM kb_documents d
                     WHERE d.kb_id = b.id AND d.deleted_at IS NULL) AS document_count,
                    (SELECT COUNT(*) FROM kb_documents d
                     WHERE d.kb_id = b.id AND d.deleted_at IS NULL AND d.status = \'ready\') AS ready_count,
                    (SELECT COUNT(*) FROM kb_documents d
                     WHERE d.kb_id = b.id AND d.deleted_at IS NULL AND d.status IN (\'pending\',\'queued\',\'running\')) AS pending_count,
                    (SELECT COUNT(*) FROM kb_documents d
                     WHERE d.kb_id = b.id AND d.deleted_at IS NULL AND d.status = \'failed\') AS failed_count
             FROM kb_bases b WHERE b.id = ? AND b.user_id = ? LIMIT 1'
        );
        $stmt->execute([$kbId, $userId]);
        $row = $stmt->fetch();
        return $row ? $this->formatBase($row) : null;
    }

    /** @param array<string, mixed> $body */
    public function createBaseForUser(int $userId, array $body): array
    {
        $this->assertUnderBaseLimit($userId);
        [$name, $slug, $project, $description] = $this->validatedBaseFields($body, true);

        $stmt = $this->db->prepare(
            'SELECT id FROM kb_bases WHERE user_id = ? AND slug = ? LIMIT 1'
        );
        $stmt->execute([$userId, $slug]);
        if ($stmt->fetch()) {
            throw new \InvalidArgumentException('A knowledge base with this slug already exists');
        }

        $ins = $this->db->prepare(
            'INSERT INTO kb_bases (user_id, name, slug, project, description)
             VALUES (?, ?, ?, ?, ?)'
        );
        $ins->execute([$userId, $name, $slug, $project, $description]);
        $id = (int) $this->db->lastInsertId();
        $base = $this->getBaseForUser($userId, $id);
        if ($base === null) {
            throw new \RuntimeException('Failed to create knowledge base');
        }
        return $base;
    }

    /** @param array<string, mixed> $body */
    public function updateBaseForUser(int $userId, int $kbId, array $body): ?array
    {
        $existing = $this->getBaseRow($userId, $kbId);
        if ($existing === null) {
            return null;
        }

        $merged = [
            'name' => array_key_exists('name', $body) ? $body['name'] : $existing['name'],
            'slug' => array_key_exists('slug', $body) ? $body['slug'] : $existing['slug'],
            'project' => array_key_exists('project', $body) ? $body['project'] : $existing['project'],
            'description' => array_key_exists('description', $body)
                ? $body['description']
                : $existing['description'],
        ];
        // Empty slug on update → keep existing
        if (!array_key_exists('slug', $body) || trim((string) ($body['slug'] ?? '')) === '') {
            $merged['slug'] = $existing['slug'];
        }
        [$name, $slug, $project, $description] = $this->validatedBaseFields($merged, false);

        if ($slug !== (string) $existing['slug']) {
            $stmt = $this->db->prepare(
                'SELECT id FROM kb_bases WHERE user_id = ? AND slug = ? AND id <> ? LIMIT 1'
            );
            $stmt->execute([$userId, $slug, $kbId]);
            if ($stmt->fetch()) {
                throw new \InvalidArgumentException('A knowledge base with this slug already exists');
            }
        }

        $upd = $this->db->prepare(
            'UPDATE kb_bases SET name = ?, slug = ?, project = ?, description = ?
             WHERE id = ? AND user_id = ?'
        );
        $upd->execute([$name, $slug, $project, $description, $kbId, $userId]);
        return $this->getBaseForUser($userId, $kbId);
    }

    public function deleteBaseForUser(int $userId, int $kbId): bool
    {
        $existing = $this->getBaseRow($userId, $kbId);
        if ($existing === null) {
            return false;
        }

        $stmt = $this->db->prepare(
            'SELECT id FROM kb_documents WHERE kb_id = ? AND user_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$kbId, $userId]);
        $docIds = [];
        foreach ($stmt->fetchAll() as $row) {
            $docIds[] = (int) $row['id'];
        }

        if ($docIds === []) {
            $del = $this->db->prepare('DELETE FROM kb_bases WHERE id = ? AND user_id = ?');
            $del->execute([$kbId, $userId]);
            return true;
        }

        $this->db->prepare(
            'UPDATE kb_documents SET deleted_at = NOW(), status = \'deleting\', error_message = NULL
             WHERE kb_id = ? AND user_id = ? AND deleted_at IS NULL'
        )->execute([$kbId, $userId]);

        $job = $this->jobs->createTyped($userId, 'kb.delete', [
            'kb_id' => $kbId,
            'cascade' => true,
            'document_ids' => $docIds,
        ]);
        $this->db->prepare(
            'UPDATE kb_documents SET ingest_job_id = ? WHERE kb_id = ? AND user_id = ?
             AND id IN (' . implode(',', array_map('intval', $docIds)) . ')'
        )->execute([(int) $job['id'], $kbId, $userId]);

        return true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listDocumentsForUser(int $userId, int $kbId): ?array
    {
        if ($this->getBaseRow($userId, $kbId) === null) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT * FROM kb_documents
             WHERE kb_id = ? AND user_id = ? AND deleted_at IS NULL
             ORDER BY id DESC'
        );
        $stmt->execute([$kbId, $userId]);
        return array_map([$this, 'formatDocument'], $stmt->fetchAll());
    }

    public function getDocumentForUser(int $userId, int $docId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM kb_documents WHERE id = ? AND user_id = ? AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([$docId, $userId]);
        $row = $stmt->fetch();
        return $row ? $this->formatDocument($row) : null;
    }

    /**
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file
     */
    public function uploadDocumentForUser(int $userId, int $kbId, array $file): array
    {
        $base = $this->getBaseRow($userId, $kbId);
        if ($base === null) {
            throw new \RuntimeException('not_found');
        }
        $this->assertUnderDocLimit($userId, $kbId);

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException($this->uploadErrorMessage((int) ($file['error'] ?? 0)));
        }

        $size = (int) ($file['size'] ?? 0);
        $maxBytes = (int) Config::get('max_kb_upload_bytes', self::MAX_UPLOAD_BYTES_DEFAULT);
        if ($size <= 0 || $size > $maxBytes) {
            throw new \InvalidArgumentException(
                'File must be between 1 byte and ' . $this->formatBytes($maxBytes)
                . ' (PHP upload_max_filesize is currently '
                . (string) ini_get('upload_max_filesize') . ')'
            );
        }

        $originalName = (string) ($file['name'] ?? 'upload');
        $filename = $this->sanitizeFilename($originalName);
        $format = $this->detectFormat($filename, (string) ($file['type'] ?? ''));
        if ($format === null) {
            throw new \InvalidArgumentException('Only PDF, DOCX, EPUB, Markdown, and TXT uploads are supported');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \InvalidArgumentException('Invalid upload');
        }

        $bytes = file_get_contents($tmp);
        if ($bytes === false) {
            throw new \InvalidArgumentException('Could not read upload');
        }
        $hash = hash('sha256', $bytes);
        $contentType = $this->contentTypeForFormat($format, (string) ($file['type'] ?? ''));

        // Insert first so the storage path can include document_id (unique per doc).
        // Hash-only paths collide when the same file is uploaded twice; deleting one
        // would unlink the shared blob and break the other.
        $placeholder = sprintf('%d/%d/pending-%s.%s', $userId, $kbId, bin2hex(random_bytes(8)), $format);
        $ins = $this->db->prepare(
            'INSERT INTO kb_documents
                (kb_id, user_id, filename, content_type, format, size_bytes, storage_path,
                 status, content_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'pending\', ?)'
        );
        $ins->execute([
            $kbId,
            $userId,
            $filename,
            $contentType,
            $format,
            $size,
            $placeholder,
            $hash,
        ]);
        $docId = (int) $this->db->lastInsertId();

        $storageRel = $this->relativeStoragePath($userId, $kbId, $docId, $hash, $format);
        $storageAbs = $this->absoluteStoragePath($storageRel);
        $dir = dirname($storageAbs);
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            $this->db->prepare('DELETE FROM kb_documents WHERE id = ?')->execute([$docId]);
            throw new \RuntimeException('Could not create storage directory');
        }
        if (file_put_contents($storageAbs, $bytes) === false) {
            $this->db->prepare('DELETE FROM kb_documents WHERE id = ?')->execute([$docId]);
            throw new \RuntimeException('Could not store upload');
        }
        @chmod($storageAbs, 0660);
        $this->db->prepare(
            'UPDATE kb_documents SET storage_path = ? WHERE id = ?'
        )->execute([$storageRel, $docId]);

        $job = $this->enqueueIngest($userId, $docId, $kbId, $format);
        $this->db->prepare(
            'UPDATE kb_documents SET status = \'queued\', ingest_job_id = ?, error_message = NULL
             WHERE id = ?'
        )->execute([(int) $job['id'], $docId]);

        $doc = $this->getDocumentForUser($userId, $docId);
        if ($doc === null) {
            throw new \RuntimeException('Failed to create document');
        }
        return $doc;
    }

    public function reingestDocumentForUser(int $userId, int $docId): ?array
    {
        $row = $this->getDocumentRow($userId, $docId, false);
        if ($row === null || $row['deleted_at'] !== null) {
            return null;
        }
        if (in_array((string) $row['status'], ['deleting'], true)) {
            throw new \InvalidArgumentException('Document is being deleted');
        }

        $job = $this->enqueueIngest(
            $userId,
            $docId,
            (int) $row['kb_id'],
            (string) $row['format'],
        );
        $this->db->prepare(
            'UPDATE kb_documents SET status = \'queued\', ingest_job_id = ?, error_message = NULL,
                    chunk_count = NULL, page_count = NULL, chapter_count = NULL, embedding_model = NULL
             WHERE id = ?'
        )->execute([(int) $job['id'], $docId]);

        return $this->getDocumentForUser($userId, $docId);
    }

    public function rebuildBaseForUser(int $userId, int $kbId): ?array
    {
        if ($this->getBaseRow($userId, $kbId) === null) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT id FROM kb_documents
             WHERE kb_id = ? AND user_id = ? AND deleted_at IS NULL
               AND status IN (\'ready\', \'failed\', \'pending\')'
        );
        $stmt->execute([$kbId, $userId]);
        $count = 0;
        foreach ($stmt->fetchAll() as $row) {
            $this->reingestDocumentForUser($userId, (int) $row['id']);
            $count++;
        }
        return ['ok' => true, 'queued' => $count, 'kb_id' => $kbId];
    }

    public function deleteDocumentForUser(int $userId, int $docId): bool
    {
        return $this->requestDocumentDelete($userId, $docId);
    }

    // --- Runner ---

    /**
     * @return array{path: string, content_type: string, filename: string}|null
     */
    public function documentContentForRunner(int $runnerId, int $docId): ?array
    {
        $row = $this->getDocumentRowAnyUser($docId);
        if ($row === null) {
            throw new \RuntimeException('not_found');
        }
        // Prefer job-binding; lean fallback: any enabled runner may read this user's blobs.
        if (!$this->runnerOwnsDocJob($runnerId, $docId) && !$this->runnerTokenMayRead($runnerId)) {
            throw new \RuntimeException('not_found');
        }
        $abs = $this->absoluteStoragePath((string) $row['storage_path']);
        if (!is_file($abs)) {
            throw new \RuntimeException(
                'blob_missing: stored file is gone for document '
                . $docId
                . ' (path='
                . (string) $row['storage_path']
                . '). Re-upload the file.'
            );
        }
        return [
            'path' => $abs,
            'content_type' => (string) $row['content_type'],
            'filename' => (string) $row['filename'],
        ];
    }

    /** @param array<string, mixed> $body */
    public function updateDocumentStatusForRunner(int $runnerId, int $docId, array $body): ?array
    {
        $row = $this->getDocumentRowAnyUser($docId);
        if ($row === null) {
            return null;
        }
        if (!$this->runnerOwnsDocJob($runnerId, $docId)) {
            // Lean: allow status updates from any enabled runner (same trust as blob read).
            if (!$this->runnerTokenMayRead($runnerId)) {
                throw new \RuntimeException('not_found');
            }
        }

        $status = isset($body['status']) ? trim((string) $body['status']) : '';
        $allowed = ['pending', 'queued', 'running', 'ready', 'failed', 'deleting'];
        if ($status === '' || !in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException('Invalid status');
        }

        $sets = ['status = ?'];
        $params = [$status];

        if (array_key_exists('error_message', $body)) {
            $err = $body['error_message'];
            $sets[] = 'error_message = ?';
            $params[] = $err === null ? null : $this->substr(trim((string) $err), 0, 2000);
        }
        if (array_key_exists('chunk_count', $body)) {
            $sets[] = 'chunk_count = ?';
            $params[] = $body['chunk_count'] === null ? null : max(0, (int) $body['chunk_count']);
        }
        if (array_key_exists('page_count', $body)) {
            $sets[] = 'page_count = ?';
            $params[] = $body['page_count'] === null ? null : max(0, (int) $body['page_count']);
        }
        if (array_key_exists('chapter_count', $body)) {
            $sets[] = 'chapter_count = ?';
            $params[] = $body['chapter_count'] === null ? null : max(0, (int) $body['chapter_count']);
        }
        if (array_key_exists('embedding_model', $body)) {
            $model = $body['embedding_model'];
            $sets[] = 'embedding_model = ?';
            $params[] = $model === null ? null : $this->substr(trim((string) $model), 0, 128);
        }

        $params[] = $docId;
        $sql = 'UPDATE kb_documents SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $this->db->prepare($sql)->execute($params);

        $fresh = $this->getDocumentRowAnyUser($docId);
        return $fresh ? $this->formatDocument($fresh) : null;
    }

    public function finalizeDocumentPurgeForRunner(int $runnerId, int $docId): bool
    {
        $row = $this->getDocumentRowAnyUser($docId);
        if ($row === null) {
            return false;
        }
        if (!$this->runnerOwnsDocJob($runnerId, $docId)) {
            throw new \RuntimeException('not_found');
        }

        $abs = $this->absoluteStoragePath((string) $row['storage_path']);
        $storagePath = (string) $row['storage_path'];
        // Only unlink if no other document still references this path (legacy hash-only paths
        // could be shared across rows).
        $refs = $this->db->prepare(
            'SELECT COUNT(*) FROM kb_documents WHERE storage_path = ? AND id <> ?'
        );
        $refs->execute([$storagePath, $docId]);
        if ((int) $refs->fetchColumn() === 0 && is_file($abs)) {
            @unlink($abs);
        }

        $kbId = (int) $row['kb_id'];
        $userId = (int) $row['user_id'];
        $cascade = $this->runnerOwnsKbJob($runnerId, $kbId);
        $this->db->prepare('DELETE FROM kb_documents WHERE id = ?')->execute([$docId]);
        $this->purgeBaseIfEmpty($userId, $kbId, $cascade);
        return true;
    }

    /**
     * Visible knowledge bases for an agent job (global ∪ project).
     *
     * @return array{bases: list<array<string, mixed>>, documents: list<array<string, mixed>>}
     */
    public function visibleForAgent(int $runnerId, int $jobId, ?string $kbFilter = null): array
    {
        $ctx = $this->runningAgentContext($runnerId, $jobId);
        $userId = (int) $ctx['user_id'];
        $project = $ctx['project'];

        [$filterSql, $filterParams] = $this->agentVisibilitySql($project, 'b.');
        $sql = 'SELECT b.* FROM kb_bases b WHERE b.user_id = ?' . $filterSql . ' ORDER BY b.name ASC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge([$userId], $filterParams));
        $bases = [];
        $allowedIds = [];
        foreach ($stmt->fetchAll() as $row) {
            if ($kbFilter !== null && $kbFilter !== '') {
                if (!$this->kbMatchesFilter($row, $kbFilter)) {
                    continue;
                }
            }
            $bases[] = $this->formatBaseCompact($row);
            $allowedIds[] = (int) $row['id'];
        }

        $documents = [];
        if ($allowedIds !== []) {
            $placeholders = implode(',', array_fill(0, count($allowedIds), '?'));
            $dstmt = $this->db->prepare(
                "SELECT d.id, d.kb_id, d.filename, d.format, d.status, d.chunk_count,
                        d.page_count, d.chapter_count, d.embedding_model, d.size_bytes
                 FROM kb_documents d
                 WHERE d.user_id = ? AND d.deleted_at IS NULL AND d.kb_id IN ($placeholders)
                 ORDER BY d.id DESC"
            );
            $dstmt->execute(array_merge([$userId], $allowedIds));
            foreach ($dstmt->fetchAll() as $drow) {
                $documents[] = [
                    'id' => (int) $drow['id'],
                    'kb_id' => (int) $drow['kb_id'],
                    'filename' => (string) $drow['filename'],
                    'format' => (string) $drow['format'],
                    'status' => (string) $drow['status'],
                    'chunk_count' => $drow['chunk_count'] !== null ? (int) $drow['chunk_count'] : null,
                    'page_count' => $drow['page_count'] !== null ? (int) $drow['page_count'] : null,
                    'chapter_count' => $drow['chapter_count'] !== null ? (int) $drow['chapter_count'] : null,
                    'embedding_model' => $drow['embedding_model'],
                    'size_bytes' => (int) $drow['size_bytes'],
                ];
            }
        }

        return [
            'bases' => $bases,
            'documents' => $documents,
            'allowed_kb_ids' => $allowedIds,
            'project' => $project,
        ];
    }

    public function getDocumentForAgent(int $runnerId, int $jobId, int $docId): ?array
    {
        $visible = $this->visibleForAgent($runnerId, $jobId);
        $allowed = $visible['allowed_kb_ids'];
        $row = $this->getDocumentRowAnyUser($docId);
        if ($row === null || $row['deleted_at'] !== null) {
            return null;
        }
        if (!in_array((int) $row['kb_id'], $allowed, true)) {
            return null;
        }
        $base = $this->getBaseRow((int) $row['user_id'], (int) $row['kb_id']);
        $doc = $this->formatDocument($row);
        $doc['kb_slug'] = $base ? (string) $base['slug'] : null;
        $doc['kb_name'] = $base ? (string) $base['name'] : null;
        $doc['kb_project'] = $base && $base['project'] !== null ? (string) $base['project'] : null;
        return $doc;
    }

    // --- Internals ---

    private function requestDocumentDelete(int $userId, int $docId): bool
    {
        $row = $this->getDocumentRow($userId, $docId, false);
        if ($row === null) {
            return false;
        }
        if ($row['deleted_at'] !== null) {
            return true;
        }

        $this->db->prepare(
            'UPDATE kb_documents SET deleted_at = NOW(), status = \'deleting\', error_message = NULL
             WHERE id = ? AND user_id = ?'
        )->execute([$docId, $userId]);

        $job = $this->jobs->createTyped($userId, 'kb.delete', [
            'document_id' => $docId,
            'kb_id' => (int) $row['kb_id'],
        ]);
        $this->db->prepare(
            'UPDATE kb_documents SET ingest_job_id = ? WHERE id = ?'
        )->execute([(int) $job['id'], $docId]);

        return true;
    }

    private function enqueueIngest(int $userId, int $docId, int $kbId, string $format): array
    {
        $embedModel = (string) Config::get('kb_embed_model', 'nomic-embed-text');
        $doc = $this->getDocumentRow($userId, $docId, false);
        $base = $this->getBaseRow($userId, $kbId);
        $payload = [
            'document_id' => $docId,
            'kb_id' => $kbId,
            'format' => $format,
            'embedding_model' => $embedModel,
        ];
        if ($doc) {
            $payload['filename'] = (string) $doc['filename'];
        }
        if ($base) {
            $payload['kb_slug'] = (string) $base['slug'];
            if ($base['project'] !== null && $base['project'] !== '') {
                $payload['project'] = (string) $base['project'];
            }
        }
        return $this->jobs->createTyped($userId, 'kb.ingest', $payload);
    }

    private function purgeBaseIfEmpty(int $userId, int $kbId, bool $forceDeleteBase = false): void
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM kb_documents WHERE kb_id = ?');
        $stmt->execute([$kbId]);
        if ((int) $stmt->fetchColumn() !== 0) {
            return;
        }
        if (!$forceDeleteBase) {
            return;
        }
        $this->db->prepare('DELETE FROM kb_bases WHERE id = ? AND user_id = ?')
            ->execute([$kbId, $userId]);
    }

    /**
     * After cascade kb.delete removes all document rows, drop the empty base.
     */
    public function finalizeBasePurgeForRunner(int $runnerId, int $kbId): bool
    {
        if (!$this->runnerOwnsKbJob($runnerId, $kbId)) {
            throw new \RuntimeException('not_found');
        }
        $stmt = $this->db->prepare('SELECT user_id FROM kb_bases WHERE id = ? LIMIT 1');
        $stmt->execute([$kbId]);
        $row = $stmt->fetch();
        if (!$row) {
            return true;
        }
        $userId = (int) $row['user_id'];
        $left = $this->db->prepare('SELECT COUNT(*) FROM kb_documents WHERE kb_id = ?');
        $left->execute([$kbId]);
        if ((int) $left->fetchColumn() === 0) {
            $this->db->prepare('DELETE FROM kb_bases WHERE id = ? AND user_id = ?')
                ->execute([$kbId, $userId]);
            return true;
        }
        return false;
    }

    private function runnerOwnsDocJob(int $runnerId, int $docId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT id, request_payload FROM jobs
             WHERE runner_id = ? AND status = 'running'
               AND type IN ('kb.ingest', 'kb.delete')
             ORDER BY id DESC LIMIT 20"
        );
        $stmt->execute([$runnerId]);
        foreach ($stmt->fetchAll() as $row) {
            $req = json_decode((string) $row['request_payload'], true);
            if (!is_array($req)) {
                continue;
            }
            if (isset($req['document_id']) && (int) $req['document_id'] === $docId) {
                return true;
            }
            // Cascade delete may not list document_id; allow any running kb.delete for same kb
            if (($req['cascade'] ?? false) && isset($req['kb_id'])) {
                $d = $this->getDocumentRowAnyUser($docId);
                if ($d && (int) $d['kb_id'] === (int) $req['kb_id']) {
                    return true;
                }
            }
        }
        return false;
    }

    private function runnerOwnsKbJob(int $runnerId, int $kbId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT request_payload FROM jobs
             WHERE runner_id = ? AND status = 'running' AND type = 'kb.delete'
             ORDER BY id DESC LIMIT 20"
        );
        $stmt->execute([$runnerId]);
        foreach ($stmt->fetchAll() as $row) {
            $req = json_decode((string) $row['request_payload'], true);
            if (is_array($req) && isset($req['kb_id']) && (int) $req['kb_id'] === $kbId) {
                return true;
            }
        }
        return false;
    }

    /** Lean trust: any authenticated enabled runner of this install may read blobs. */
    private function runnerTokenMayRead(int $runnerId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM runners WHERE id = ? AND enabled = 1 LIMIT 1'
        );
        $stmt->execute([$runnerId]);
        return (bool) $stmt->fetch();
    }

    /**
     * @return array{user_id: int, project: ?string}
     */
    private function runningAgentContext(int $runnerId, int $jobId): array
    {
        $stmt = $this->db->prepare(
            "SELECT user_id, type, status, request_payload FROM jobs
             WHERE id = ? AND runner_id = ? LIMIT 1"
        );
        $stmt->execute([$jobId, $runnerId]);
        $row = $stmt->fetch();
        if (!$row || (string) $row['status'] !== 'running' || (string) $row['type'] !== 'agent.task') {
            throw new \RuntimeException('not_found');
        }
        $req = json_decode((string) $row['request_payload'], true);
        $project = null;
        if (is_array($req)) {
            $p = trim((string) ($req['project'] ?? ''));
            if ($p !== '') {
                $project = $p;
            }
        }
        return ['user_id' => (int) $row['user_id'], 'project' => $project];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function agentVisibilitySql(?string $project, string $alias = ''): array
    {
        if ($project === null || $project === '') {
            return [" AND {$alias}project IS NULL", []];
        }
        return [" AND ({$alias}project IS NULL OR {$alias}project = ?)", [$project]];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function projectFilterSql(?string $projectParam, string $alias = ''): array
    {
        $raw = $projectParam !== null ? trim($projectParam) : '';
        if ($raw === '' || $raw === '_none') {
            return [" AND {$alias}project IS NULL", []];
        }
        if (!JobService::isValidProjectName($raw)) {
            throw new \InvalidArgumentException('Invalid project name');
        }
        return [" AND {$alias}project = ?", [$raw]];
    }

    /** @param array<string, mixed> $row */
    private function kbMatchesFilter(array $row, string $filter): bool
    {
        $filter = trim($filter);
        if ($filter === '') {
            return true;
        }
        if (ctype_digit($filter) && (int) $filter === (int) $row['id']) {
            return true;
        }
        return strcasecmp((string) $row['slug'], $filter) === 0
            || strcasecmp((string) $row['name'], $filter) === 0;
    }

    private function assertUnderBaseLimit(int $userId): void
    {
        $max = (int) Config::get('max_kb_bases', self::MAX_BASES_DEFAULT);
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM kb_bases WHERE user_id = ?');
        $stmt->execute([$userId]);
        if ((int) $stmt->fetchColumn() >= $max) {
            throw new \InvalidArgumentException("Maximum of {$max} knowledge bases reached");
        }
    }

    private function assertUnderDocLimit(int $userId, int $kbId): void
    {
        $max = (int) Config::get('max_kb_documents_per_base', self::MAX_DOCS_PER_KB_DEFAULT);
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM kb_documents
             WHERE kb_id = ? AND user_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$kbId, $userId]);
        if ((int) $stmt->fetchColumn() >= $max) {
            throw new \InvalidArgumentException("Maximum of {$max} documents per knowledge base reached");
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: string, 1: string, 2: ?string, 3: ?string}
     */
    private function validatedBaseFields(array $body, bool $requireName): array
    {
        $name = trim((string) ($body['name'] ?? ''));
        if ($requireName && $name === '') {
            throw new \InvalidArgumentException('Name is required');
        }
        if ($this->charLen($name) > self::NAME_MAX) {
            throw new \InvalidArgumentException('Name is too long');
        }

        $slugRaw = trim((string) ($body['slug'] ?? ''));
        if ($slugRaw === '') {
            $slugRaw = $this->slugify($name);
        }
        if (!JobService::isValidProjectName($slugRaw) || $this->charLen($slugRaw) > self::SLUG_MAX) {
            throw new \InvalidArgumentException(
                'Invalid slug: use 1–64 chars starting with a letter or digit; '
                . "only letters, digits, '.', '_', and '-' allowed"
            );
        }

        $project = null;
        if (array_key_exists('project', $body)) {
            $p = $body['project'];
            if ($p !== null && trim((string) $p) !== '') {
                $p = trim((string) $p);
                if (!JobService::isValidProjectName($p)) {
                    throw new \InvalidArgumentException('Invalid project name');
                }
                $project = $p;
            }
        }

        $description = null;
        if (array_key_exists('description', $body) && $body['description'] !== null) {
            $d = trim((string) $body['description']);
            if ($d !== '') {
                if ($this->charLen($d) > self::DESCRIPTION_MAX) {
                    throw new \InvalidArgumentException('Description is too long');
                }
                $description = $d;
            }
        }

        return [$name, $slugRaw, $project, $description];
    }

    private function slugify(string $name): string
    {
        $s = strtolower(trim($name));
        $s = preg_replace('/[^a-z0-9._-]+/', '-', $s) ?? '';
        $s = trim($s, '-._');
        if ($s === '' || !preg_match('/^[a-z0-9]/', $s)) {
            $s = 'kb-' . substr(bin2hex(random_bytes(4)), 0, 8);
        }
        return substr($s, 0, self::SLUG_MAX);
    }

    private function sanitizeFilename(string $name): string
    {
        $base = basename(str_replace(["\0", '/', '\\'], '', $name));
        $base = trim($base);
        if ($base === '' || $base === '.' || $base === '..') {
            $base = 'document';
        }
        if ($this->charLen($base) > self::FILENAME_MAX) {
            $ext = pathinfo($base, PATHINFO_EXTENSION);
            $stem = pathinfo($base, PATHINFO_FILENAME);
            $stem = $this->substr($stem, 0, self::FILENAME_MAX - strlen($ext) - 1);
            $base = $ext !== '' ? "{$stem}.{$ext}" : $stem;
        }
        return $base;
    }

    private function detectFormat(string $filename, string $mime): ?string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === 'markdown') {
            $ext = 'md';
        }
        if (!isset(self::ALLOWED_FORMATS[$ext])) {
            return null;
        }
        $mime = strtolower(trim($mime));
        if ($mime !== '' && !in_array($mime, self::ALLOWED_FORMATS[$ext], true)
            && !str_starts_with($mime, 'application/')) {
            // Some browsers send odd types; extension allowlist is primary.
        }
        return $ext;
    }

    private function contentTypeForFormat(string $format, string $uploaded): string
    {
        return match ($format) {
            'pdf' => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'epub' => 'application/epub+zip',
            'md' => 'text/markdown',
            'txt' => 'text/plain',
            default => $uploaded !== '' ? $uploaded : 'application/octet-stream',
        };
    }

    private function storageRoot(): string
    {
        $configured = Config::get('kb_storage_path');
        if (is_string($configured) && trim($configured) !== '') {
            return rtrim($configured, '/');
        }
        return dirname(__DIR__) . '/storage/kb';
    }

    private function relativeStoragePath(
        int $userId,
        int $kbId,
        int $documentId,
        string $hash,
        string $format,
    ): string {
        return sprintf('%d/%d/%d_%s.%s', $userId, $kbId, $documentId, $hash, $format);
    }

    private function absoluteStoragePath(string $relative): string
    {
        $relative = ltrim(str_replace(['..', '\\'], '', $relative), '/');
        return $this->storageRoot() . '/' . $relative;
    }

    private function getBaseRow(int $userId, int $kbId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM kb_bases WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$kbId, $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function getDocumentRow(int $userId, int $docId, bool $liveOnly): ?array
    {
        $sql = 'SELECT * FROM kb_documents WHERE id = ? AND user_id = ?';
        if ($liveOnly) {
            $sql .= ' AND deleted_at IS NULL';
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$docId, $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function getDocumentRowAnyUser(int $docId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM kb_documents WHERE id = ? LIMIT 1');
        $stmt->execute([$docId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @param array<string, mixed> $row */
    private function formatBase(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'project' => $row['project'] !== null ? (string) $row['project'] : null,
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            'document_count' => isset($row['document_count']) ? (int) $row['document_count'] : 0,
            'ready_count' => isset($row['ready_count']) ? (int) $row['ready_count'] : 0,
            'pending_count' => isset($row['pending_count']) ? (int) $row['pending_count'] : 0,
            'failed_count' => isset($row['failed_count']) ? (int) $row['failed_count'] : 0,
            'created_at' => $this->iso($row['created_at'] ?? null),
            'updated_at' => $this->iso($row['updated_at'] ?? null),
        ];
    }

    /** @param array<string, mixed> $row */
    private function formatBaseCompact(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'project' => $row['project'] !== null ? (string) $row['project'] : null,
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
        ];
    }

    /** @param array<string, mixed> $row */
    private function formatDocument(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'kb_id' => (int) $row['kb_id'],
            'filename' => (string) $row['filename'],
            'content_type' => (string) $row['content_type'],
            'format' => (string) $row['format'],
            'size_bytes' => (int) $row['size_bytes'],
            'status' => (string) $row['status'],
            'error_message' => $row['error_message'] !== null ? (string) $row['error_message'] : null,
            'chunk_count' => $row['chunk_count'] !== null ? (int) $row['chunk_count'] : null,
            'page_count' => $row['page_count'] !== null ? (int) $row['page_count'] : null,
            'chapter_count' => $row['chapter_count'] !== null ? (int) $row['chapter_count'] : null,
            'content_hash' => (string) $row['content_hash'],
            'ingest_job_id' => $row['ingest_job_id'] !== null ? (int) $row['ingest_job_id'] : null,
            'embedding_model' => $row['embedding_model'] !== null ? (string) $row['embedding_model'] : null,
            'created_at' => $this->iso($row['created_at'] ?? null),
            'updated_at' => $this->iso($row['updated_at'] ?? null),
        ];
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $s = (string) $value;
        if (str_ends_with($s, 'Z') || str_contains($s, '+')) {
            return $s;
        }
        return str_replace(' ', 'T', $s) . 'Z';
    }

    private function charLen(string $s): int
    {
        return function_exists('mb_strlen') ? (int) mb_strlen($s, 'UTF-8') : strlen($s);
    }

    private function substr(string $s, int $start, ?int $length = null): string
    {
        if (function_exists('mb_substr')) {
            return $length === null
                ? (string) mb_substr($s, $start, null, 'UTF-8')
                : (string) mb_substr($s, $start, $length, 'UTF-8');
        }
        return $length === null ? substr($s, $start) : substr($s, $start, $length);
    }

    private function uploadErrorMessage(int $code): string
    {
        $uploadMax = (string) ini_get('upload_max_filesize');
        $postMax = (string) ini_get('post_max_size');
        return match ($code) {
            UPLOAD_ERR_INI_SIZE =>
                "File exceeds PHP upload_max_filesize ({$uploadMax}). "
                . 'Raise upload_max_filesize / post_max_size for the API (see api/.htaccess).',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds the form size limit.',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded; try again.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server missing a temp directory for uploads.',
            UPLOAD_ERR_CANT_WRITE => 'Server failed to write the uploaded file.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension blocked the upload.',
            default => "Upload failed (error {$code}; upload_max_filesize={$uploadMax}, post_max_size={$postMax})",
        };
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' MiB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KiB';
        }
        return $bytes . ' bytes';
    }
}
