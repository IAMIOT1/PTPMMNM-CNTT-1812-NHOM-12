<?php
declare(strict_types=1);

class ReleaseExporter {
    public function __construct(private PgSql\Connection $conn) {
    }

    public function generateRelease(int $projectId, string $locale, string $versionNumber, string $outputPath): string {
        $versionRes = queryParams(
            $this->conn,
            'SELECT id FROM source_versions
             WHERE project_id = $1 AND version_number = $2
             ORDER BY id DESC LIMIT 1',
            [$projectId, $versionNumber]
        );
        $versionRow = pg_fetch_assoc($versionRes);
        if (!$versionRow) {
            throw new RuntimeException('SOURCE_VERSION_NOT_FOUND');
        }
        $versionId = (int)$versionRow['id'];

        $snapshotRes = queryParams(
            $this->conn,
            'SELECT json_content FROM release_snapshots
             WHERE source_version_id = $1 AND locale = $2',
            [$versionId, $locale]
        );
        $snapshot = pg_fetch_assoc($snapshotRes);

        if ($snapshot) {
            $jsonContent = $snapshot['json_content'];
        } elseif (is_file($outputPath)) {
            $jsonContent = file_get_contents($outputPath);
            if ($jsonContent === false || json_decode($jsonContent, true) === null) {
                throw new RuntimeException('EXISTING_RELEASE_INVALID');
            }
        } else {
            $sql = "SELECT s.key_path, COALESCE(t.translated_text, s.source_text) AS text
                    FROM strings s
                    LEFT JOIN translations t ON t.string_id = s.id AND t.locale = $2
                    WHERE s.source_version_id = $1
                    ORDER BY s.key_path";
            $res = queryParams($this->conn, $sql, [$versionId, $locale]);

            $flatData = [];
            while ($row = pg_fetch_assoc($res)) {
                $flatData[$row['key_path']] = $row['text'];
            }

            $jsonContent = json_encode(
                JsonParser::unflatten($flatData),
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            );
        }

        queryParams(
            $this->conn,
            'INSERT INTO release_snapshots
                (project_id, source_version_id, locale, version_number, json_content)
             VALUES ($1, $2, $3, $4, $5)
             ON CONFLICT (source_version_id, locale) DO NOTHING',
            [$projectId, $versionId, $locale, $versionNumber, $jsonContent]
        );

        $snapshotRes = queryParams(
            $this->conn,
            'SELECT json_content FROM release_snapshots
             WHERE source_version_id = $1 AND locale = $2',
            [$versionId, $locale]
        );
        $snapshot = pg_fetch_assoc($snapshotRes);
        if (!$snapshot) {
            throw new RuntimeException('RELEASE_SNAPSHOT_NOT_FOUND');
        }
        $jsonContent = $snapshot['json_content'];

        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('RELEASE_DIRECTORY_CREATE_FAILED');
            }
        }

        $existingContent = is_file($outputPath) ? file_get_contents($outputPath) : false;
        if ($existingContent !== false && $existingContent !== $jsonContent) {
            throw new RuntimeException('RELEASE_FILE_DIFFERS_FROM_IMMUTABLE_SNAPSHOT');
        }

        if ($existingContent === false && file_put_contents($outputPath, $jsonContent, LOCK_EX) === false) {
            throw new RuntimeException('RELEASE_FILE_WRITE_FAILED');
        }

        return $jsonContent;
    }
}