<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/JsonParser.php';

final class TranslationRepository {
    public function __construct(private PgSql\Connection $connection) {}

    /**
     * Import a source version idempotently, preserving translations for unchanged keys.
     */
    public function importSourceVersion(int $projectId, string $versionNumber, array $nestedJson): int {
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
                'SELECT id FROM source_versions
                 WHERE project_id = $1 AND version_number = $2
                 ORDER BY id DESC LIMIT 1',
                [$projectId, $versionNumber]
            );
            $versionRow = pg_fetch_assoc($resVersion);
            if ($versionRow) {
                $versionId = (int)$versionRow['id'];
            } else {
                $resVersion = queryParams(
                    $this->connection,
                    'INSERT INTO source_versions (project_id, version_number)
                     VALUES ($1, $2) RETURNING id',
                    [$projectId, $versionNumber]
                );
                $versionId = (int)pg_fetch_result($resVersion, 0, 'id');
            }

            $priorVersionRes = queryParams(
                $this->connection,
                'SELECT id FROM source_versions
                 WHERE project_id = $1 AND id < $2
                 ORDER BY id DESC LIMIT 1',
                [$projectId, $versionId]
            );
            $priorVersionRow = pg_fetch_assoc($priorVersionRes);
            $priorVersionId = $priorVersionRow ? (int)$priorVersionRow['id'] : null;

            $flatData = JsonParser::flatten($nestedJson);
            $existingRes = queryParams(
                $this->connection,
                'SELECT key_path FROM strings WHERE source_version_id = $1',
                [$versionId]
            );
            while ($existingRow = pg_fetch_assoc($existingRes)) {
                if (!array_key_exists($existingRow['key_path'], $flatData)) {
                    queryParams(
                        $this->connection,
                        'DELETE FROM strings WHERE source_version_id = $1 AND key_path = $2',
                        [$versionId, $existingRow['key_path']]
                    );
                }
            }

            foreach ($flatData as $keyPath => $sourceText) {
                $oldRes = queryParams(
                    $this->connection,
                    'SELECT id, source_text FROM strings
                     WHERE source_version_id = $1 AND key_path = $2',
                    [$versionId, $keyPath]
                );
                $oldRow = pg_fetch_assoc($oldRes);
                if ($oldRow && $oldRow['source_text'] !== $sourceText) {
                    queryParams(
                        $this->connection,
                        "UPDATE translations
                         SET status = 'needs_review', updated_at = clock_timestamp()
                         WHERE string_id = $1",
                        [(int)$oldRow['id']]
                    );
                }

                $priorRow = null;
                if (!$oldRow && $priorVersionId !== null) {
                    $priorStringRes = queryParams(
                        $this->connection,
                        'SELECT id, source_text FROM strings
                         WHERE source_version_id = $1 AND key_path = $2',
                        [$priorVersionId, $keyPath]
                    );
                    $priorRow = pg_fetch_assoc($priorStringRes);
                }

                $stringRes = queryParams(
                    $this->connection,
                    'INSERT INTO strings (project_id, source_version_id, key_path, source_text)
                     VALUES ($1, $2, $3, $4)
                     ON CONFLICT (source_version_id, key_path)
                     DO UPDATE SET project_id = EXCLUDED.project_id,
                                   source_text = EXCLUDED.source_text
                     WHERE strings.source_text IS DISTINCT FROM EXCLUDED.source_text
                     RETURNING id',
                    [$projectId, $versionId, $keyPath, $sourceText]
                );

                $stringRow = $oldRow ?: pg_fetch_assoc($stringRes);
                if ($priorRow && $stringRow) {
                    queryParams(
                        $this->connection,
                        "INSERT INTO translations (string_id, locale, translated_text, status, updated_at, user_id)
                         SELECT $1, locale, translated_text,
                                CASE WHEN $2 = 'true' THEN 'needs_review' ELSE status END,
                                clock_timestamp(), user_id
                         FROM translations
                         WHERE string_id = $3
                         ON CONFLICT (string_id, locale) DO NOTHING",
                        [
                            (int)$stringRow['id'],
                            $priorRow['source_text'] === $sourceText ? 'false' : 'true',
                            (int)$priorRow['id']
                        ]
                    );
                }
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
}
