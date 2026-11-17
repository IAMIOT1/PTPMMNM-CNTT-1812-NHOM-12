<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

final class ProjectSyncState
{
    public static function fingerprint(PgSql\Connection $connection, int $userId): string
    {
        $result = queryParams(
            $connection,
            "SELECT md5(COALESCE(string_agg(
                concat_ws(':', p.id::TEXT, p.name, COALESCE(p.description, ''), COALESCE(members.member_state, '')),
                '|' ORDER BY p.id
            ), '')) AS revision
            FROM projects p
            LEFT JOIN LATERAL (
                SELECT string_agg(
                    concat_ws(':', pm.user_id::TEXT, pm.role_in_project),
                    ',' ORDER BY pm.user_id
                ) AS member_state
                FROM project_members pm
                WHERE pm.project_id = p.id
            ) members ON TRUE
            WHERE p.created_by = $1 OR EXISTS (
                SELECT 1 FROM project_members visible
                WHERE visible.project_id = p.id AND visible.user_id = $1
            )",
            [$userId]
        );

        return (string)pg_fetch_result($result, 0, 'revision');
    }
}
