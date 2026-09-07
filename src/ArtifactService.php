<?php

declare(strict_types=1);

namespace OwnRun;

use PDO;

final class ArtifactService
{
    private const ALLOWED_TYPES = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    public function __construct(private PDO $db) {}

    /**
     * Runner uploads an output image for a running job it owns.
     *
     * @param array<string, mixed> $file from $_FILES['file']
     * @return array<string, mixed>
     */
    public function uploadForRunner(int $runnerId, int $jobId, array $file): array
    {
        $job = $this->runningJobForRunner($runnerId, $jobId);
        if ($job === null) {
            throw new \RuntimeException('not_found');
        }

        $maxPerJob = (int) Config::get('max_artifacts_per_job', 4);
        $countStmt = $this->db->prepare(
            'SELECT COUNT(*) FROM job_artifacts WHERE job_id = ?'
        );
        $countStmt->execute([$jobId]);
        if ((int) $countStmt->fetchColumn() >= $maxPerJob) {
            throw new \InvalidArgumentException(
                "This job already has the maximum of {$maxPerJob} artifacts"
            );
        }

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException($this->uploadErrorMessage((int) ($file['error'] ?? 0)));
        }

        $size = (int) ($file['size'] ?? 0);
        $maxBytes = (int) Config::get('max_artifact_bytes', 15 * 1024 * 1024);
        if ($size <= 0 || $size > $maxBytes) {
            throw new \InvalidArgumentException(
                'File must be between 1 byte and ' . $this->formatBytes($maxBytes)
            );
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \InvalidArgumentException('Invalid upload');
        }

        $bytes = file_get_contents($tmp);
        if ($bytes === false) {
            throw new \InvalidArgumentException('Could not read upload');
        }

        $declaredType = strtolower(trim((string) ($file['type'] ?? '')));
        $detected = $this->detectContentType($bytes, $declaredType);
        if ($detected === null) {
            throw new \InvalidArgumentException(
                'Only PNG, JPEG, and WebP image uploads are supported'
            );
        }

        $ext = self::ALLOWED_TYPES[$detected];
        $originalName = (string) ($file['name'] ?? "image.{$ext}");
        $filename = $this->sanitizeFilename($originalName, $ext);
        $userId = (int) $job['user_id'];
        $hash = hash('sha256', $bytes);

        $placeholder = sprintf('%d/%d/pending-%s.%s', $userId, $jobId, bin2hex(random_bytes(8)), $ext);
        $ins = $this->db->prepare(
            'INSERT INTO job_artifacts
                (job_id, kind, filename, content_type, storage_path, size_bytes)
             VALUES (?, \'output\', ?, ?, ?, ?)'
        );
        $ins->execute([$jobId, $filename, $detected, $placeholder, $size]);
        $artifactId = (int) $this->db->lastInsertId();

        $storageRel = sprintf('%d/%d/%d_%s.%s', $userId, $jobId, $artifactId, substr($hash, 0, 16), $ext);
        $storageAbs = $this->absoluteStoragePath($storageRel);
        $dir = dirname($storageAbs);
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            $this->db->prepare('DELETE FROM job_artifacts WHERE id = ?')->execute([$artifactId]);
            throw new \RuntimeException('Could not create storage directory: ' . $dir);
        }
        if (file_put_contents($storageAbs, $bytes) === false) {
            $this->db->prepare('DELETE FROM job_artifacts WHERE id = ?')->execute([$artifactId]);
            throw new \RuntimeException('Could not store upload');
        }
        @chmod($storageAbs, 0660);
        $this->db->prepare(
            'UPDATE job_artifacts SET storage_path = ? WHERE id = ?'
        )->execute([$storageRel, $artifactId]);

        $row = $this->getArtifactRow($artifactId);
        if ($row === null) {
            throw new \RuntimeException('Failed to create artifact');
        }
        return $this->formatArtifact($row);
    }

    /** @return list<array<string, mixed>> */
    public function listForUser(int $userId, int $jobId): array
    {
        if (!$this->userOwnsJob($userId, $jobId)) {
            return [];
        }
        $stmt = $this->db->prepare(
            'SELECT id, job_id, kind, filename, content_type, size_bytes, created_at
             FROM job_artifacts WHERE job_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$jobId]);
        return array_map([$this, 'formatArtifact'], $stmt->fetchAll());
    }

    /**
     * @return array{path: string, content_type: string, filename: string}|null
     */
    public function absoluteFileForUser(int $userId, int $jobId, int $artifactId): ?array
    {
        if (!$this->userOwnsJob($userId, $jobId)) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT filename, content_type, storage_path
             FROM job_artifacts WHERE id = ? AND job_id = ? LIMIT 1'
        );
        $stmt->execute([$artifactId, $jobId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $abs = $this->absoluteStoragePath((string) $row['storage_path']);
        if (!is_file($abs) || !is_readable($abs)) {
            return null;
        }
        return [
            'path' => $abs,
            'content_type' => (string) $row['content_type'],
            'filename' => (string) $row['filename'],
        ];
    }

    private function runningJobForRunner(int $runnerId, int $jobId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, status, runner_id FROM jobs WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$jobId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        if ((string) $row['status'] !== 'running' || (int) $row['runner_id'] !== $runnerId) {
            return null;
        }
        return $row;
    }

    private function userOwnsJob(int $userId, int $jobId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM jobs WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$jobId, $userId]);
        return (bool) $stmt->fetchColumn();
    }

    private function getArtifactRow(int $artifactId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, job_id, kind, filename, content_type, size_bytes, created_at
             FROM job_artifacts WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$artifactId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @param array<string, mixed> $row */
    private function formatArtifact(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'job_id' => (int) $row['job_id'],
            'kind' => (string) $row['kind'],
            'filename' => (string) $row['filename'],
            'content_type' => (string) $row['content_type'],
            'size_bytes' => (int) $row['size_bytes'],
            'created_at' => $row['created_at'] ?? null,
        ];
    }

    private function detectContentType(string $bytes, string $declared): ?string
    {
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }
        if (str_starts_with($bytes, "\xff\xd8\xff")) {
            return 'image/jpeg';
        }
        if (strlen($bytes) >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        if (isset(self::ALLOWED_TYPES[$declared])) {
            return $declared;
        }
        return null;
    }

    private function sanitizeFilename(string $name, string $ext): string
    {
        $base = basename(str_replace(["\0", '/', '\\'], '', $name));
        $base = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $base) ?? 'image';
        $base = trim($base, '._-');
        if ($base === '') {
            $base = 'image';
        }
        if (!str_ends_with(strtolower($base), '.' . $ext)) {
            $base .= '.' . $ext;
        }
        return substr($base, 0, 200);
    }

    private function storageRoot(): string
    {
        $configured = Config::get('artifacts_storage_path');
        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }
        return dirname(__DIR__) . '/storage/artifacts';
    }

    private function absoluteStoragePath(string $relative): string
    {
        $relative = str_replace('\\', '/', $relative);
        if ($relative === '' || str_contains($relative, '..')) {
            throw new \RuntimeException('Invalid storage path');
        }
        return $this->storageRoot() . '/' . ltrim($relative, '/');
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' MiB';
        }
        return $bytes . ' bytes';
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Upload exceeds size limit',
            UPLOAD_ERR_PARTIAL => 'Upload was incomplete',
            UPLOAD_ERR_NO_FILE => 'No file uploaded',
            default => 'Upload failed',
        };
    }
}
