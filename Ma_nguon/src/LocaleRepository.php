<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/**
 * Repository để quản lý locales
 */
class LocaleRepository
{
    private \PgSql\Connection $connection;
    
    public function __construct(\PgSql\Connection $connection)
    {
        $this->connection = $connection;
    }
    
    /**
     * Lấy tất cả locales trong hệ thống
     */
    public function getAll(): array
    {
        try {
            $result = queryParams($this->connection, 'SELECT code, name FROM locales ORDER BY code', []);
        } catch (RuntimeException $exception) {
            error_log('LOCALE_LIST_QUERY_FAILED: ' . $exception->getMessage());
            return [];
        }

        $locales = [];
        while ($row = pg_fetch_assoc($result)) {
            if (isset($row['code'], $row['name'])) {
                $locales[] = $row;
            }
        }
        return $locales;
    }
    
    /**
     * Lấy các locales đã kích hoạt cho một dự án
     */
    public function getActiveForProject(int $projectId, ?int $versionId = null): array
    {
        if ($projectId < 1) {
            return [];
        }

        try {
            $result = queryParams(
                $this->connection,
                'SELECT pl.locale_code, l.name
                 FROM project_locales AS pl
                 INNER JOIN locales AS l ON l.code = pl.locale_code
                 WHERE pl.project_id = $1
                   AND (
                       pl.version_id IS NULL
                       OR ($2::INT IS NOT NULL AND pl.version_id = $2::INT)
                   )
                 GROUP BY pl.locale_code, l.name
                 ORDER BY pl.locale_code',
                [$projectId, $versionId]
            );
        } catch (RuntimeException $exception) {
            error_log(sprintf(
                'LOCALE_PROJECT_QUERY_FAILED project_id=%d: %s',
                $projectId,
                $exception->getMessage()
            ));
            return [];
        }

        $locales = [];
        while ($row = pg_fetch_assoc($result)) {
            if (isset($row['locale_code'], $row['name'])) {
                $locales[] = $row;
            }
        }
        return $locales;
    }
    
    /**
     * Thêm locale cho dự án (nếu chưa có)
     */
    public function addLocaleForProject(int $projectId, string $localeCode, ?int $versionId = null): bool
    {
        $localeCode = strtolower(trim($localeCode));
        if ($versionId !== null) {
            $result = queryParams(
                $this->connection,
                'INSERT INTO project_locales (project_id, version_id, locale_code)
                 VALUES ($1, $2, $3)
                 ON CONFLICT DO NOTHING
                 RETURNING id',
                [$projectId, $versionId, $localeCode]
            );
        } else {
            $result = queryParams(
                $this->connection,
                'INSERT INTO project_locales (project_id, locale_code)
                 VALUES ($1, $2)
                 ON CONFLICT DO NOTHING
                 RETURNING id',
                [$projectId, $localeCode]
            );
        }
        return pg_num_rows($result) > 0;
    }
    
    /**
     * Xóa locale khỏi dự án
     */
    public function removeLocaleForProject(int $projectId, string $localeCode, ?int $versionId = null): bool
    {
        $localeCode = strtolower(trim($localeCode));
        $result = queryParams(
            $this->connection,
            'DELETE FROM project_locales
             WHERE project_id = $1 AND locale_code = $2
               AND version_id IS NOT DISTINCT FROM $3::INT
             RETURNING id',
            [$projectId, $localeCode, $versionId]
        );
        return pg_num_rows($result) > 0;
    }
}