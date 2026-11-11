<?php
declare(strict_types=1);

final class TmRepository
{
    private const MAX_UPLOAD_BYTES = 5_000_000;

    public function __construct(
        private $connection,
        private string $storageRoot
    ) {
        if (!is_dir($storageRoot) && !mkdir($storageRoot, 0750, true) && !is_dir($storageRoot)) {
            throw new RuntimeException('TM_REPOSITORY_STORAGE_UNAVAILABLE');
        }
        if (!is_writable($storageRoot)) {
            throw new RuntimeException('TM_REPOSITORY_STORAGE_NOT_WRITABLE');
        }
    }

    public function exportProject(int $projectId, string $projectName): ?string
    {
        $result = queryParams(
            $this->connection,
            "SELECT l.locale, s.source_text, t.translated_text
             FROM translations t
             JOIN strings s ON s.id = t.string_id
             JOIN source_versions v ON v.id = s.source_version_id
             JOIN locales l ON l.code = t.locale
             WHERE v.project_id = $1
               AND t.status = 'approved'
               AND NULLIF(BTRIM(t.translated_text), '') IS NOT NULL
             ORDER BY t.locale, s.key_path, t.id",
            [$projectId]
        );
        $rows = [];
        while ($row = pg_fetch_assoc($result)) {
            $rows[] = $row;
        }
        if ($rows === []) {
            return null;
        }
        $filename = $this->projectFilename($projectName, $projectId);
        $this->writeCsv($filename, $rows);
        return $filename;
    }

    public function listFiles(): array
    {
        if (!is_dir($this->storageRoot) || !is_readable($this->storageRoot)) {
            throw new RuntimeException('TM_REPOSITORY_STORAGE_UNAVAILABLE');
        }
        $paths = glob($this->storageRoot . '/*.csv');
        if ($paths === false) {
            throw new RuntimeException('TM_REPOSITORY_LIST_FAILED');
        }
        $files = [];
        foreach ($paths as $path) {
            $stat = stat($path);
            if ($stat === false) {
                throw new RuntimeException('TM_REPOSITORY_FILE_STAT_FAILED');
            }
            $files[] = [
                'name' => basename($path),
                'size' => (int)$stat['size'],
                'modified_at' => (int)$stat['mtime'],
            ];
        }
        usort($files, static fn(array $a, array $b): int => $b['modified_at'] <=> $a['modified_at']);
        return $files;
    }

    public function resolveFilename(string $filename): string
    {
        if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,119}\.csv\z/', $filename)) {
            throw new InvalidArgumentException('TM_REPOSITORY_FILENAME_INVALID');
        }
        $path = $this->storageRoot . DIRECTORY_SEPARATOR . $filename;
        $realRoot = realpath($this->storageRoot);
        $parent = realpath(dirname($path));
        if ($realRoot === false || $parent !== $realRoot) {
            throw new InvalidArgumentException('TM_REPOSITORY_PATH_INVALID');
        }
        return $path;
    }

    public function delete(string $filename): void
    {
        $path = $this->resolveFilename($filename);
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('TM_REPOSITORY_DELETE_FAILED');
        }
        $this->invalidateIndex();
    }

    public function importUploaded(string $tmpPath, string $originalName): array
    {
        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'csv') {
            throw new InvalidArgumentException('TM_REPOSITORY_CSV_EXTENSION_REQUIRED');
        }
        if (!is_uploaded_file($tmpPath) && PHP_SAPI !== 'cli') {
            throw new InvalidArgumentException('TM_REPOSITORY_UPLOAD_INVALID');
        }
        $size = is_file($tmpPath) ? filesize($tmpPath) : false;
        if ($size === false || $size < 1 || $size > self::MAX_UPLOAD_BYTES) {
            throw new InvalidArgumentException('TM_REPOSITORY_UPLOAD_TOO_LARGE');
        }
        $safeName = $this->safeUploadFilename($originalName);
        $rows = $this->readCsv($tmpPath);
        $storedPath = $this->resolveFilename($safeName);
        $transactionStarted = false;
        try {
            $this->writeCsv($safeName, $rows);
            if (pg_query($this->connection, 'BEGIN') === false) {
                throw new RuntimeException('TM_REPOSITORY_DATABASE_TRANSACTION_FAILED');
            }
            $transactionStarted = true;
            $inserted = 0;
            foreach ($rows as $row) {
                $existing = queryParams(
                    $this->connection,
                    'SELECT id FROM translation_memory
                     WHERE project_id IS NULL AND locale = $1 AND source_text = $2
                     LIMIT 1',
                    [$row['locale'], $row['source_text']]
                );
                $existingId = pg_fetch_result($existing, 0, 'id');
                if ($existingId !== false) {
                    queryParams(
                        $this->connection,
                        'UPDATE translation_memory SET translated_text = $1 WHERE id = $2',
                        [$row['translated_text'], (int)$existingId]
                    );
                } else {
                    queryParams(
                        $this->connection,
                        'INSERT INTO translation_memory (project_id, locale, source_text, translated_text)
                         VALUES (NULL, $1, $2, $3)',
                        [$row['locale'], $row['source_text'], $row['translated_text']]
                    );
                }
                $inserted++;
            }
            if (pg_query($this->connection, 'COMMIT') === false) {
                throw new RuntimeException('TM_REPOSITORY_DATABASE_TRANSACTION_FAILED');
            }
            $transactionStarted = false;
        } catch (Throwable $exception) {
            if ($transactionStarted && pg_query($this->connection, 'ROLLBACK') === false) {
                error_log('TM_REPOSITORY_ROLLBACK_FAILED: ' . pg_last_error($this->connection));
            }
            if (is_file($storedPath) && !unlink($storedPath)) {
                error_log('TM_REPOSITORY_UPLOAD_CLEANUP_FAILED: ' . $storedPath);
            }
            $this->invalidateIndex();
            throw $exception;
        }
        return ['filename' => $safeName, 'rows' => $inserted];
    }

    public function findSuggestions(string $locale, string $sourceText, int $limit = 50): array
    {
        $suggestions = [];
        foreach ($this->readRepositoryIndex() as $row) {
                if ($row['locale'] !== $locale) {
                    continue;
                }
                similar_text(mb_strtolower($sourceText), mb_strtolower($row['source_text']), $score);
                $suggestions[] = [
                    'source_text' => $row['source_text'],
                    'translated_text' => $row['translated_text'],
                    'score' => $row['source_text'] === $sourceText ? 1.0 : $score / 100,
                    'repository_file' => $row['repository_file'],
                ];
        }
        usort($suggestions, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        return array_slice($suggestions, 0, max(1, min($limit, 100)));
    }

    private function projectFilename(string $projectName, int $projectId): string
    {
        $name = preg_replace('/[^a-zA-Z0-9_-]+/', '-', trim($projectName)) ?? '';
        $name = trim($name, '-_');
        return ($name !== '' ? substr($name, 0, 90) : 'project') . '-' . $projectId . '.csv';
    }

    /** @param list<array{locale:string,source_text:string,translated_text:string}> $rows */
    private function writeCsv(string $filename, array $rows): void
    {
        $path = $this->resolveFilename($filename);
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('TM_REPOSITORY_WRITE_FAILED');
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['locale', 'source_text', 'target_text']);
        foreach ($rows as $row) {
            fputcsv($handle, [$row['locale'], $row['source_text'], $row['translated_text']]);
        }
        if (fclose($handle) === false) {
            throw new RuntimeException('TM_REPOSITORY_WRITE_FAILED');
        }
        $this->invalidateIndex();
    }

    /** @return list<array{locale:string,source_text:string,translated_text:string,repository_file:string}> */
    private function readRepositoryIndex(): array
    {
        $indexPath = $this->storageRoot . DIRECTORY_SEPARATOR . '.index.json';
        $files = $this->listFiles();
        $signature = hash('sha256', json_encode(array_map(
            static fn(array $file): array => [$file['name'], $file['modified_at'], $file['size']],
            $files
        ), JSON_THROW_ON_ERROR));
        if (is_file($indexPath)) {
            $cached = json_decode((string)file_get_contents($indexPath), true);
            if (is_array($cached) && ($cached['signature'] ?? '') === $signature && is_array($cached['rows'] ?? null)) {
                return $cached['rows'];
            }
        }
        $rows = [];
        foreach ($files as $file) {
            foreach ($this->readCsv($this->resolveFilename($file['name'])) as $row) {
                $row['repository_file'] = $file['name'];
                $rows[] = $row;
            }
        }
        file_put_contents(
            $indexPath,
            json_encode(['signature' => $signature, 'rows' => $rows], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            LOCK_EX
        );
        @chmod($indexPath, 0640);
        return $rows;
    }

    private function invalidateIndex(): void
    {
        $indexPath = $this->storageRoot . DIRECTORY_SEPARATOR . '.index.json';
        if (is_file($indexPath)) {
            unlink($indexPath);
        }
    }

    /** @return list<array{locale:string,source_text:string,translated_text:string}> */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('TM_REPOSITORY_READ_FAILED');
        }
        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            error_log(sprintf('[TM repository CSV] Empty or unreadable header: %s', $path));
            throw new InvalidArgumentException('TM_REPOSITORY_CSV_EMPTY');
        }
        $semicolonCount = substr_count($firstLine, ';');
        $commaCount = substr_count($firstLine, ',');
        $delimiter = $semicolonCount > $commaCount ? ';' : ',';
        rewind($handle);
        $header = fgetcsv($handle, 1000, $delimiter);
        if (!is_array($header)) {
            fclose($handle);
            error_log(sprintf('[TM repository CSV] Invalid header in %s (delimiter: %s)', $path, $delimiter));
            throw new InvalidArgumentException('TM_REPOSITORY_CSV_EMPTY');
        }
        $header = array_map(static fn($value): string => strtolower(trim((string)$value, "\xEF\xBB\xBF \t\n\r")), $header);
        $indexes = array_flip($header);
        $targetIndex = $indexes['target_text'] ?? $indexes['translated_text'] ?? null;
        if ($targetIndex === null) {
            fclose($handle);
            error_log(sprintf(
                '[TM repository CSV] Missing target column in %s. Delimiter: %s. Read columns: %s',
                $path,
                $delimiter,
                implode(', ', $header)
            ));
            throw new InvalidArgumentException('TM_REPOSITORY_CSV_COLUMNS_REQUIRED');
        }
        $missingColumns = [];
        foreach (['locale', 'source_text'] as $required) {
            if (!isset($indexes[$required])) {
                $missingColumns[] = $required;
            }
        }
        if ($missingColumns !== []) {
            fclose($handle);
            error_log(sprintf(
                '[TM repository CSV] Missing required columns in %s: %s. Delimiter: %s. Read columns: %s',
                $path,
                implode(', ', $missingColumns),
                $delimiter,
                implode(', ', $header)
            ));
            throw new InvalidArgumentException('TM_REPOSITORY_CSV_COLUMNS_REQUIRED');
        }
        $rows = [];
        while (($row = fgetcsv($handle, 1000, $delimiter)) !== false) {
            $locale = trim((string)($row[$indexes['locale']] ?? ''));
            $source = (string)($row[$indexes['source_text']] ?? '');
            $translated = $targetIndex !== null ? (string)($row[$targetIndex] ?? '') : '';
            if ($locale !== '' && $source !== '' && $translated !== '') {
                $rows[] = ['locale' => $locale, 'source_text' => $source, 'translated_text' => $translated];
            }
        }
        fclose($handle);
        if ($rows === []) {
            error_log(sprintf(
                '[TM repository CSV] No valid data rows in %s. Delimiter: %s. Read columns: %s',
                $path,
                $delimiter,
                implode(', ', $header)
            ));
            throw new InvalidArgumentException('TM_REPOSITORY_CSV_EMPTY');
        }
        return $rows;
    }

    private function safeUploadFilename(string $originalName): string
    {
        $base = pathinfo($originalName, PATHINFO_FILENAME);
        $base = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $base) ?? 'tm-upload';
        $base = trim($base, '-_') ?: 'tm-upload';
        return substr($base, 0, 90) . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.csv';
    }
}
