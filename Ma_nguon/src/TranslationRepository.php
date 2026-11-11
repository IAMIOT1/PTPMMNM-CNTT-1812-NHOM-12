<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/JsonParser.php';

final class TranslationRepository {
    public function __construct(private PgSql\Connection $connection) {}

    /**
     * Replace the complete source payload for a version without carrying over old translations.
     */
    public function importSourceVersion(
        int $projectId,
        string $versionNumber,
        array $nestedJson,
        ?string $sourceUrl = null,
        ?string $sourceHtml = null
    ): int {
        if ($sourceUrl !== null && (
            strlen($sourceUrl) > 2048 ||
            filter_var($sourceUrl, FILTER_VALIDATE_URL) === false
        )) {
            throw new InvalidArgumentException('SOURCE_URL_INVALID');
        }
        if ($sourceHtml !== null && strlen($sourceHtml) > 2097152) {
            throw new InvalidArgumentException('SOURCE_HTML_TOO_LARGE');
        }

        $nestedTransaction = pg_transaction_status($this->connection) !== PGSQL_TRANSACTION_IDLE;
        $transactionCommand = $nestedTransaction ? 'SAVEPOINT tms_source_import' : 'BEGIN';
        if (pg_query($this->connection, $transactionCommand) === false) {
            throw new RuntimeException('SOURCE_IMPORT_TRANSACTION_FAILED');
        }

        try {
            queryParams(
                $this->connection,
                'SELECT pg_advisory_xact_lock(hashtext($1), hashtext($2))',
                [(string)$projectId, $versionNumber]
            );

            $resVersion = queryParams(
                $this->connection,
                'SELECT id, frozen_at FROM source_versions
                 WHERE project_id = $1 AND version_number = $2
                 ORDER BY id DESC LIMIT 1',
                [$projectId, $versionNumber]
            );
            $versionRow = pg_fetch_assoc($resVersion);
            if ($versionRow) {
                $versionId = (int)$versionRow['id'];
                if ($versionRow['frozen_at'] !== null) {
                    throw new RuntimeException('SOURCE_VERSION_FROZEN');
                }
            } else {
                $resVersion = queryParams(
                    $this->connection,
                    'INSERT INTO source_versions (project_id, version_number)
                     VALUES ($1, $2) RETURNING id',
                    [$projectId, $versionNumber]
                );
                $versionId = (int)pg_fetch_result($resVersion, 0, 'id');
            }

            queryParams(
                $this->connection,
                'UPDATE source_versions SET source_url = $1, source_html = $2 WHERE id = $3',
                [$sourceUrl, $sourceHtml, $versionId]
            );

            $flatData = JsonParser::flatten($nestedJson);
            $baseline = [];
            if ($versionRow) {
                $baselineRes = queryParams(
                    $this->connection,
                    'SELECT key_path, source_text FROM strings WHERE source_version_id = $1',
                    [$versionId]
                );
                while ($row = pg_fetch_assoc($baselineRes)) {
                    $baseline[$row['key_path']] = $row['source_text'];
                }
            }
            $changes = [];
            foreach ($flatData as $keyPath => $sourceText) {
                if (!array_key_exists($keyPath, $baseline)) {
                    $changes[$keyPath] = ['added', null, $sourceText];
                } elseif ($baseline[$keyPath] !== $sourceText) {
                    $changes[$keyPath] = ['modified', $baseline[$keyPath], $sourceText];
                }
            }
            foreach ($baseline as $keyPath => $sourceText) {
                if (!array_key_exists($keyPath, $flatData)) {
                    $changes[$keyPath] = ['deleted', $sourceText, null];
                }
            }

            queryParams(
                $this->connection,
                'DELETE FROM translations
                 WHERE string_id IN (
                     SELECT id FROM strings WHERE source_version_id = $1
                 )',
                [$versionId]
            );
            queryParams(
                $this->connection,
                'DELETE FROM strings WHERE source_version_id = $1',
                [$versionId]
            );

            foreach ($flatData as $keyPath => $sourceText) {
                queryParams(
                    $this->connection,
                    'INSERT INTO strings (project_id, source_version_id, key_path, source_text)
                     VALUES ($1, $2, $3, $4)
                     RETURNING id',
                    [$projectId, $versionId, $keyPath, $sourceText]
                );
            }

            queryParams(
                $this->connection,
                'DELETE FROM source_version_changes WHERE source_version_id = $1',
                [$versionId]
            );
            foreach ($changes as $keyPath => [$changeType, $oldText, $newText]) {
                queryParams(
                    $this->connection,
                    'INSERT INTO source_version_changes
                        (source_version_id, key_path, change_type, old_text, new_text)
                     VALUES ($1, $2, $3, $4, $5)',
                    [$versionId, $keyPath, $changeType, $oldText, $newText]
                );
            }

            $commitCommand = $nestedTransaction
                ? 'RELEASE SAVEPOINT tms_source_import'
                : 'COMMIT';
            if (pg_query($this->connection, $commitCommand) === false) {
                throw new RuntimeException('SOURCE_IMPORT_COMMIT_FAILED');
            }
            return $versionId;
        } catch (Throwable $e) {
            if ($nestedTransaction) {
                pg_query($this->connection, 'ROLLBACK TO SAVEPOINT tms_source_import');
                pg_query($this->connection, 'RELEASE SAVEPOINT tms_source_import');
            } else {
                pg_query($this->connection, 'ROLLBACK');
            }
            throw $e;
        }
    }

    public function getVersionChanges(int $sourceVersionId): array {
        $result = queryParams(
            $this->connection,
            'SELECT key_path, change_type, old_text, new_text
             FROM source_version_changes
             WHERE source_version_id = $1
             ORDER BY CASE change_type WHEN \'added\' THEN 1 WHEN \'modified\' THEN 2 ELSE 3 END, key_path',
            [$sourceVersionId]
        );
        $changes = [];
        while ($row = pg_fetch_assoc($result)) {
            $changes[] = $row;
        }
        return $changes;
    }
}
