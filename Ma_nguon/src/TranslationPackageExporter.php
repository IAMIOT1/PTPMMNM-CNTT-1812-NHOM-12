<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/JsonParser.php';

final class TranslationPackageExporter
{
    public function __construct(private PgSql\Connection $connection)
    {
    }

    public function export(int $versionId, string $locale): string
    {
        $result = queryParams(
            $this->connection,
            "SELECT s.key_path, COALESCE(NULLIF(t.translated_text, ''), s.source_text) AS text
             FROM strings s
             LEFT JOIN translations t ON t.string_id = s.id AND t.locale = $2
             WHERE s.source_version_id = $1
             ORDER BY s.key_path",
            [$versionId, $locale]
        );
        $flat = [];
        while ($row = pg_fetch_assoc($result)) {
            $flat[$row['key_path']] = $row['text'];
        }
        return json_encode(
            JsonParser::unflatten($flat),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        );
    }
}
