<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ProjectAccess.php';
require_once __DIR__ . '/AuditLogger.php';
require_once __DIR__ . '/MyMemoryTranslationService.php';
require_once __DIR__ . '/TranslationProviderService.php';
require_once __DIR__ . '/VariableValidator.php';

final class DashboardApiHttpException extends RuntimeException
{
    public function __construct(public readonly int $statusCode, string $message)
    {
        parent::__construct($message);
    }
}

final class DashboardApiService
{
    private int $userId;
    private bool $isAdmin;

    public function __construct(
        private readonly PgSql\Connection $connection,
        private readonly array $user
    ) {
        $this->userId = (int)$user['id'];
        $this->isAdmin = ProjectAccess::isSystemAdmin($user);
    }

    public function workspace(string $csrfToken): array
    {
        $users = $this->users();
        $projects = $this->projects();
        $projectIds = array_map(static fn(array $project): int => (int)$project['id'], $projects);

        return [
            'csrfToken' => $csrfToken,
            'currentUserId' => (string)$this->userId,
            'canManageUsers' => $this->isAdmin,
            'users' => $users,
            'projects' => $projects,
            'translations' => $this->translations($projectIds),
            'logs' => $this->logs($projectIds),
            'locales' => $this->locales(),
        ];
    }

    public function supportRequests(): array
    {
        $this->requireAdmin();
        $result = queryParams(
            $this->connection,
            'SELECT t.id, t.title, t.message, t.status, t.image_path, t.created_at,
                    u.username AS sender_name, u.email AS sender_email
             FROM support_tickets t
             JOIN users u ON u.id = t.user_id
             ORDER BY t.created_at DESC, t.id DESC',
            []
        );

        $requests = [];
        while ($row = pg_fetch_assoc($result)) {
            $requests[] = [
                'id' => (string)$row['id'],
                'title' => (string)$row['title'],
                'message' => (string)$row['message'],
                'senderName' => (string)$row['sender_name'],
                'senderEmail' => (string)$row['sender_email'],
                'createdAt' => (string)$row['created_at'],
                'imagePath' => $row['image_path'] !== null ? (string)$row['image_path'] : null,
                'status' => (string)$row['status'],
            ];
        }
        return $requests;
    }

    public function mutate(array $payload): array
    {
        return match ($payload['action'] ?? '') {
            'create_project' => $this->createProject($payload),
            'update_project' => $this->updateProject($payload),
            'delete_project' => $this->deleteProject($payload),
            'create_user' => $this->createUser($payload),
            'update_user' => $this->updateUser($payload),
            'delete_user' => $this->deleteUser($payload),
            'save_translation' => $this->saveTranslation($payload),
            'review_translation' => $this->reviewTranslation($payload),
            'suggest_translation' => $this->suggestTranslation($payload),
            'update_support_request_status' => $this->updateSupportRequestStatus($payload),
            default => throw new DashboardApiHttpException(400, 'Unknown dashboard action.'),
        };
    }

    private function users(): array
    {
        $result = queryParams(
            $this->connection,
            "SELECT u.id, u.username, u.email, u.role, u.is_active, u.created_at,
                    (
                        SELECT COUNT(DISTINCT p.id)
                        FROM projects p
                        LEFT JOIN project_members pm ON pm.project_id = p.id
                        WHERE p.created_by = u.id OR pm.user_id = u.id
                    )::int AS project_count
             FROM users u
             WHERE $1::boolean
                OR u.id = $2
                OR EXISTS (
                    SELECT 1
                    FROM project_members member
                    JOIN projects p ON p.id = member.project_id
                    WHERE member.user_id = u.id
                      AND (
                          p.created_by = $2
                          OR EXISTS (
                              SELECT 1 FROM project_members visible
                              WHERE visible.project_id = p.id AND visible.user_id = $2
                          )
                      )
                )
             ORDER BY u.username, u.id",
            [$this->isAdmin ? 'true' : 'false', $this->userId]
        );

        $colors = ['violet', 'blue', 'amber', 'emerald', 'rose', 'cyan'];
        $users = [];
        while ($row = pg_fetch_assoc($result)) {
            $username = (string)$row['username'];
            $users[] = [
                'id' => (string)$row['id'],
                'name' => $username,
                'email' => (string)$row['email'],
                'accountRole' => (string)$row['role'],
                'active' => $row['is_active'] === 't',
                'avatar' => strtoupper(substr($username, 0, 2)),
                'color' => $colors[((int)$row['id']) % count($colors)],
                'projects' => (int)$row['project_count'],
                'joinedAt' => substr((string)$row['created_at'], 0, 10),
            ];
        }
        return $users;
    }

    private function projects(): array
    {
        $result = queryParams(
            $this->connection,
            'SELECT p.id, p.name, p.description, p.created_by, p.created_at
             FROM projects p
             WHERE $1::boolean
                OR p.created_by = $2
                OR EXISTS (
                    SELECT 1 FROM project_members pm
                    WHERE pm.project_id = p.id AND pm.user_id = $2
                )
             ORDER BY p.created_at DESC, p.id DESC',
            [$this->isAdmin ? 'true' : 'false', $this->userId]
        );

        $projects = [];
        $accents = ['violet', 'blue', 'emerald', 'amber', 'rose', 'cyan'];
        while ($row = pg_fetch_assoc($result)) {
            $projectId = (int)$row['id'];
            $versionResult = queryParams(
                $this->connection,
                'SELECT id FROM source_versions WHERE project_id = $1 ORDER BY id DESC LIMIT 1',
                [$projectId]
            );
            $versionId = pg_fetch_result($versionResult, 0, 'id');
            $versionId = $versionId === false ? null : (int)$versionId;

            $localeResult = queryParams(
                $this->connection,
                'SELECT DISTINCT locale_code
                 FROM project_locales
                 WHERE project_id = $1
                   AND locale_code <> \'en\'
                   AND (version_id IS NULL OR version_id = $2::integer)
                 ORDER BY locale_code',
                [$projectId, $versionId]
            );
            $targetLocales = [];
            while ($locale = pg_fetch_assoc($localeResult)) {
                $targetLocales[] = (string)$locale['locale_code'];
            }

            $stringCount = 0;
            $wordCount = 0;
            $translatedCount = 0;
            $approvedCount = 0;
            $reviewCount = 0;
            $totalCount = 0;
            if ($versionId !== null) {
                $stringResult = queryParams(
                    $this->connection,
                    "SELECT COUNT(*)::int AS string_count,
                            COALESCE(SUM(
                                CASE WHEN BTRIM(source_text) = '' THEN 0
                                     ELSE CARDINALITY(regexp_split_to_array(BTRIM(source_text), '[[:space:]]+'))
                                END
                            ), 0)::int AS word_count
                     FROM strings
                     WHERE project_id = $1 AND source_version_id = $2",
                    [$projectId, $versionId]
                );
                $stringStats = pg_fetch_assoc($stringResult) ?: [];
                $stringCount = (int)($stringStats['string_count'] ?? 0);
                $wordCount = (int)($stringStats['word_count'] ?? 0);

                if ($targetLocales !== []) {
                    $localeArray = '{' . implode(',', $targetLocales) . '}';
                    $progressResult = queryParams(
                        $this->connection,
                        "SELECT COUNT(*)::int AS total_count,
                                COUNT(*) FILTER (
                                    WHERE NULLIF(BTRIM(t.translated_text), '') IS NOT NULL
                                )::int AS translated_count,
                                COUNT(*) FILTER (
                                    WHERE t.status = 'approved'
                                      AND NULLIF(BTRIM(t.translated_text), '') IS NOT NULL
                                )::int AS approved_count,
                                COUNT(*) FILTER (
                                    WHERE t.status IN ('needs_review', 'rejected')
                                )::int AS review_count
                         FROM strings s
                         CROSS JOIN unnest($3::text[]) AS target(locale_code)
                         LEFT JOIN translations t
                           ON t.string_id = s.id AND t.locale = target.locale_code
                         WHERE s.project_id = $1 AND s.source_version_id = $2",
                        [$projectId, $versionId, $localeArray]
                    );
                    $progress = pg_fetch_assoc($progressResult) ?: [];
                    $totalCount = (int)($progress['total_count'] ?? 0);
                    $translatedCount = (int)($progress['translated_count'] ?? 0);
                    $approvedCount = (int)($progress['approved_count'] ?? 0);
                    $reviewCount = (int)($progress['review_count'] ?? 0);
                }
            }

            $memberResult = queryParams(
                $this->connection,
                "SELECT pm.user_id, pm.role_in_project, u.role AS account_role
                 FROM project_members pm
                 JOIN users u ON u.id = pm.user_id
                 WHERE pm.project_id = $1
                 ORDER BY pm.user_id",
                [$projectId]
            );
            $teamLeadIds = [];
            $translatorIds = [];
            $reviewerIds = [];
            while ($member = pg_fetch_assoc($memberResult)) {
                $memberId = (string)$member['user_id'];
                if ($member['role_in_project'] === 'Team Lead') {
                    $teamLeadIds[] = $memberId;
                } elseif ($member['account_role'] === 'admin') {
                    continue;
                } elseif ($member['role_in_project'] === 'Reviewer') {
                    $reviewerIds[] = $memberId;
                } else {
                    $translatorIds[] = $memberId;
                }
            }
            $teamLeadId = $teamLeadIds[0] ?? (string)($row['created_by'] ?? $this->userId);
            $status = $totalCount > 0 && $approvedCount === $totalCount
                ? 'Completed'
                : ($reviewCount > 0 ? 'Review' : 'Active');

            $projects[] = [
                'id' => (string)$projectId,
                'name' => (string)$row['name'],
                'description' => (string)($row['description'] ?? ''),
                'sourceLocale' => 'en',
                'targetLocales' => $targetLocales,
                'status' => $status,
                'progress' => $totalCount > 0 ? (int)round($translatedCount / $totalCount * 100) : 0,
                'words' => $wordCount,
                'strings' => $stringCount,
                'updatedAt' => (string)$row['created_at'],
                'teamLeadId' => $teamLeadId,
                'translatorIds' => $translatorIds,
                'reviewerIds' => $reviewerIds,
                'accent' => $accents[$projectId % count($accents)],
            ];
        }
        return $projects;
    }

    private function translations(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        $result = queryParams(
            $this->connection,
            "WITH latest_versions AS (
                SELECT DISTINCT ON (project_id) project_id, id, created_at
                FROM source_versions
                ORDER BY project_id, id DESC
             ),
             active_locales AS (
                SELECT DISTINCT pl.project_id, pl.locale_code
                FROM project_locales pl
                JOIN latest_versions v ON v.project_id = pl.project_id
                WHERE pl.version_id IS NULL OR pl.version_id = v.id
             )
             SELECT s.id AS string_id, s.project_id, s.key_path, s.source_text,
                    al.locale_code, t.translated_text, t.status,
                    t.user_id AS assignee_id,
                    to_char(t.updated_at AT TIME ZONE 'UTC',
                            'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS translation_updated_at,
                    to_char(COALESCE(t.updated_at, v.created_at) AT TIME ZONE 'UTC',
                            'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS updated_at,
                    latest_comment.comment
             FROM strings s
             JOIN latest_versions v ON v.project_id = s.project_id AND v.id = s.source_version_id
             JOIN active_locales al ON al.project_id = s.project_id AND al.locale_code <> 'en'
             LEFT JOIN translations t ON t.string_id = s.id AND t.locale = al.locale_code
             LEFT JOIN LATERAL (
                SELECT tc.comment
                FROM translation_comments tc
                WHERE tc.string_id = s.id AND tc.locale = al.locale_code
                ORDER BY tc.created_at DESC, tc.id DESC
                LIMIT 1
             ) latest_comment ON TRUE
             WHERE s.project_id = ANY($1::int[])
             ORDER BY s.project_id, s.key_path, al.locale_code",
            ['{' . implode(',', $projectIds) . '}']
        );

        $translations = [];
        while ($row = pg_fetch_assoc($result)) {
            $status = match ((string)($row['status'] ?? 'draft')) {
                'approved' => 'Approved',
                'needs_review' => 'In review',
                'rejected' => 'Needs revision',
                default => 'Draft',
            };
            $translations[] = [
                'id' => $row['string_id'] . ':' . $row['locale_code'],
                'stringId' => (string)$row['string_id'],
                'projectId' => (string)$row['project_id'],
                'key' => (string)$row['key_path'],
                'source' => (string)$row['source_text'],
                'translated' => (string)($row['translated_text'] ?? ''),
                'sourceLocale' => 'en',
                'targetLocale' => (string)$row['locale_code'],
                'status' => $status,
                'assigneeId' => (string)($row['assignee_id'] ?? ''),
                'updatedAt' => (string)$row['updated_at'],
                'translationUpdatedAt' => $row['translation_updated_at'] !== null
                    ? (string)$row['translation_updated_at']
                    : null,
                'comment' => $row['comment'] !== null ? (string)$row['comment'] : null,
                'fallback' => false,
            ];
        }
        return $translations;
    }

    private function logs(array $projectIds): array
    {
        if (!$this->isAdmin && $projectIds === []) {
            return [];
        }
        $visibleProjects = '{' . implode(',', $projectIds) . '}';
        $result = queryParams(
            $this->connection,
            "SELECT a.id, a.actor_user_id, a.project_id, a.action_type, a.details,
                    a.created_at, COALESCE(s.key_path, p.name, u_target.username, a.action_type) AS target,
                    COALESCE(u.username, 'System') AS actor_name, p.name AS project_name
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.actor_user_id
             LEFT JOIN users u_target ON u_target.id = a.target_user_id
             LEFT JOIN projects p ON p.id = a.project_id
             LEFT JOIN strings s ON s.id = a.string_id
             WHERE $1::boolean
                OR a.project_id = ANY($2::int[])
                OR (a.project_id IS NULL AND a.actor_user_id = $3)
             ORDER BY a.created_at DESC, a.id DESC
             LIMIT 1000",
            [$this->isAdmin ? 'true' : 'false', $visibleProjects, $this->userId]
        );
        $actionMap = [
            'CREATE_PROJECT' => 'Project created',
            'UPDATE_PROJECT' => 'Project updated',
            'DELETE_PROJECT' => 'Project deleted',
            'SET_PROJECT_MEMBER_ROLE' => 'Assignment changed',
            'UPDATE_PROJECT_MEMBER_ROLE' => 'Assignment changed',
            'REMOVE_PROJECT_MEMBER' => 'Assignment changed',
            'ADMIN_USER_CREATED' => 'User created',
            'ADMIN_USER_UPDATED' => 'User updated',
            'ADMIN_USER_STATUS_CHANGED' => 'User updated',
            'ADMIN_USER_DELETED' => 'User deleted',
            'SUPPORT_TICKET_STATUS_UPDATED' => 'Support request status updated',
            'UPDATE_TRANSLATION' => 'Translation saved',
            'BULK_UPDATE_TRANSLATIONS' => 'Translation saved',
            'APPROVE_TRANSLATION' => 'Translation approved',
            'REQUEST_TRANSLATION_REVISION' => 'Revision requested',
        ];
        $logs = [];
        while ($row = pg_fetch_assoc($result)) {
            $actionType = (string)$row['action_type'];
            $logs[] = [
                'id' => (string)$row['id'],
                'actorId' => (string)($row['actor_user_id'] ?? '0'),
                'action' => $actionMap[$actionType] ?? 'Assignment changed',
                'target' => (string)$row['target'],
                'projectId' => $row['project_id'] !== null ? (string)$row['project_id'] : null,
                'details' => (string)($row['details'] ?? ''),
                'createdAt' => (string)$row['created_at'],
            ];
        }
        return $logs;
    }

    private function locales(): array
    {
        $result = queryParams(
            $this->connection,
            'SELECT code, name FROM locales WHERE code <> \'en\' ORDER BY name, code',
            []
        );
        $locales = [];
        while ($row = pg_fetch_assoc($result)) {
            $locales[] = ['code' => (string)$row['code'], 'name' => (string)$row['name']];
        }
        return $locales;
    }

    private function createProject(array $payload): array
    {
        $this->assertCanCreateProject();
        $name = $this->requiredString($payload, 'name', 1, 150);
        $description = $this->optionalString($payload, 'description', 5000);
        $targetLocales = $this->validateLocales($payload['targetLocales'] ?? null);
        $translatorIds = $this->validateIds($payload['translatorIds'] ?? []);
        $reviewerIds = $this->validateIds($payload['reviewerIds'] ?? []);
        $teamLeadId = $this->positiveId($payload['teamLeadId'] ?? $this->userId, 'teamLeadId');
        $this->validateAssignments($teamLeadId, $translatorIds, $reviewerIds);

        $projectId = $this->transaction(function () use (
            $name,
            $description,
            $targetLocales,
            $translatorIds,
            $reviewerIds,
            $teamLeadId
        ): int {
            $created = queryParams(
                $this->connection,
                'INSERT INTO projects (name, description, created_by)
                 VALUES ($1, $2, $3) RETURNING id',
                [$name, $description !== '' ? $description : null, $this->userId]
            );
            $projectId = (int)pg_fetch_result($created, 0, 'id');
            $members = [$teamLeadId => 'Team Lead'];
            foreach ($translatorIds as $id) {
                $members[$id] = 'Translator';
            }
            foreach ($reviewerIds as $id) {
                $members[$id] = 'Reviewer';
            }
            $this->saveMembers($projectId, $members);
            $this->saveLocales($projectId, $targetLocales);
            (new AuditLogger($this->connection))->log(
                $projectId,
                null,
                'CREATE_PROJECT',
                "Created project [$name].",
                $this->userId
            );
            return $projectId;
        });
        return ['projectId' => (string)$projectId];
    }

    private function updateProject(array $payload): array
    {
        $projectId = $this->positiveId($payload['id'] ?? null, 'id');
        $this->projectForUpdate($projectId);
        $this->assertCanManageProject($projectId);
        $name = $this->requiredString($payload, 'name', 1, 150);
        $description = $this->optionalString($payload, 'description', 5000);
        $targetLocales = $this->validateLocales($payload['targetLocales'] ?? null);
        $translatorIds = $this->validateIds($payload['translatorIds'] ?? []);
        $reviewerIds = $this->validateIds($payload['reviewerIds'] ?? []);
        $teamLeadId = $this->positiveId($payload['teamLeadId'] ?? $this->userId, 'teamLeadId');
        $this->validateAssignments($teamLeadId, $translatorIds, $reviewerIds);

        $this->transaction(function () use (
            $projectId,
            $name,
            $description,
            $targetLocales,
            $translatorIds,
            $reviewerIds,
            $teamLeadId
        ): void {
            queryParams(
                $this->connection,
                'UPDATE projects SET name = $1, description = $2 WHERE id = $3',
                [$name, $description !== '' ? $description : null, $projectId]
            );
            $members = [$teamLeadId => 'Team Lead'];
            foreach ($translatorIds as $id) {
                $members[$id] = 'Translator';
            }
            foreach ($reviewerIds as $id) {
                $members[$id] = 'Reviewer';
            }
            queryParams($this->connection, 'DELETE FROM project_members WHERE project_id = $1', [$projectId]);
            $this->saveMembers($projectId, $members);
            $this->saveLocales($projectId, $targetLocales);
            (new AuditLogger($this->connection))->log(
                $projectId,
                null,
                'UPDATE_PROJECT',
                "Updated project [$name] and its assignments.",
                $this->userId
            );
        });
        return ['projectId' => (string)$projectId];
    }

    private function deleteProject(array $payload): array
    {
        $projectId = $this->positiveId($payload['id'] ?? null, 'id');
        $project = $this->projectForUpdate($projectId);
        $this->assertCanManageProject($projectId);
        $name = (string)$project['name'];
        $this->transaction(function () use ($projectId, $name): void {
            (new AuditLogger($this->connection))->log(
                $projectId,
                null,
                'DELETE_PROJECT',
                "Deleted project [$name].",
                $this->userId
            );
            queryParams($this->connection, 'DELETE FROM projects WHERE id = $1', [$projectId]);
        });
        return ['projectId' => (string)$projectId];
    }

    private function createUser(array $payload): array
    {
        $this->requireAdmin();
        $username = $this->username($payload['name'] ?? null);
        $email = $this->email($payload['email'] ?? null);
        $password = $this->requiredString($payload, 'password', 8, 200);
        $role = $this->accountRole($payload['accountRole'] ?? 'user');
        $active = ($payload['active'] ?? true) === true;
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        if ($passwordHash === false) {
            throw new RuntimeException('PASSWORD_HASH_FAILED');
        }

        $userId = $this->transaction(function () use ($username, $email, $passwordHash, $role, $active): int {
            $duplicate = queryParams(
                $this->connection,
                'SELECT 1 FROM users
                 WHERE lower(username) = lower($1) OR lower(email) = lower($2)
                 LIMIT 1',
                [$username, $email]
            );
            if (pg_num_rows($duplicate) > 0) {
                throw new DashboardApiHttpException(409, 'Username or email is already in use.');
            }
            $created = queryParams(
                $this->connection,
                'INSERT INTO users (username, email, password_hash, role, is_active, is_verified)
                 VALUES ($1, $2, $3, $4, $5, TRUE)
                 RETURNING id',
                [$username, $email, $passwordHash, $role, $active ? 'true' : 'false']
            );
            $userId = (int)pg_fetch_result($created, 0, 'id');
            $this->writeUserAudit($userId, 'ADMIN_USER_CREATED', "Created user [$username] (ID $userId).");
            return $userId;
        });
        return ['userId' => (string)$userId];
    }

    private function updateUser(array $payload): array
    {
        $this->requireAdmin();
        $userId = $this->positiveId($payload['id'] ?? null, 'id');
        $username = $this->username($payload['name'] ?? null);
        $email = $this->email($payload['email'] ?? null);
        $role = $this->accountRole($payload['accountRole'] ?? null);
        $active = ($payload['active'] ?? null) === true;
        $this->transaction(function () use ($userId, $username, $email, $role, $active): void {
            $targetResult = queryParams(
                $this->connection,
                'SELECT id, username, role, is_active FROM users WHERE id = $1 FOR UPDATE',
                [$userId]
            );
            $target = pg_fetch_assoc($targetResult);
            if (!$target) {
                throw new DashboardApiHttpException(404, 'User not found.');
            }
            if ($userId === $this->userId && ($role !== 'admin' || !$active)) {
                throw new DashboardApiHttpException(422, 'You cannot disable or demote your own account.');
            }
            if ($target['role'] === 'admin' && $target['is_active'] === 't' && ($role !== 'admin' || !$active)) {
                $admins = queryParams(
                    $this->connection,
                    "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = TRUE",
                    []
                );
                if ((int)pg_fetch_result($admins, 0, 0) <= 1) {
                    throw new DashboardApiHttpException(422, 'The last active administrator cannot be disabled or demoted.');
                }
            }
            $duplicate = queryParams(
                $this->connection,
                'SELECT 1 FROM users
                 WHERE id <> $1 AND (lower(username) = lower($2) OR lower(email) = lower($3))
                 LIMIT 1',
                [$userId, $username, $email]
            );
            if (pg_num_rows($duplicate) > 0) {
                throw new DashboardApiHttpException(409, 'Username or email is already in use.');
            }
            queryParams(
                $this->connection,
                'UPDATE users SET username = $1, email = $2, role = $3, is_active = $4 WHERE id = $5',
                [$username, $email, $role, $active ? 'true' : 'false', $userId]
            );
            $this->writeUserAudit(
                $userId,
                'ADMIN_USER_UPDATED',
                "Updated user [$username] (ID $userId), account role [$role], active [" . ($active ? 'true' : 'false') . '].'
            );
        });
        return ['userId' => (string)$userId];
    }

    private function deleteUser(array $payload): array
    {
        $this->requireAdmin();
        $userId = $this->positiveId($payload['id'] ?? null, 'id');
        if ($userId === $this->userId) {
            throw new DashboardApiHttpException(422, 'You cannot delete the account currently in use.');
        }
        $this->transaction(function () use ($userId): void {
            $targetResult = queryParams(
                $this->connection,
                'SELECT id, username, role, is_active FROM users WHERE id = $1 FOR UPDATE',
                [$userId]
            );
            $target = pg_fetch_assoc($targetResult);
            if (!$target) {
                throw new DashboardApiHttpException(404, 'User not found.');
            }
            if ($target['role'] === 'admin' && $target['is_active'] === 't') {
                $admins = queryParams(
                    $this->connection,
                    "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = TRUE",
                    []
                );
                if ((int)pg_fetch_result($admins, 0, 0) <= 1) {
                    throw new DashboardApiHttpException(422, 'The last active administrator cannot be deleted.');
                }
            }
            $this->writeUserAudit(
                $userId,
                'ADMIN_USER_DELETED',
                "Deleted user [" . (string)$target['username'] . "] (ID $userId)."
            );
            queryParams($this->connection, 'DELETE FROM users WHERE id = $1', [$userId]);
        });
        return ['userId' => (string)$userId];
    }

    private function updateSupportRequestStatus(array $payload): array
    {
        $this->requireAdmin();
        $ticketId = $this->positiveId($payload['id'] ?? null, 'id');
        $status = $payload['status'] ?? null;
        if (!is_string($status) || !in_array($status, ['pending', 'in_progress', 'resolved'], true)) {
            throw new DashboardApiHttpException(422, 'Invalid support request status.');
        }

        $this->transaction(function () use ($ticketId, $status): void {
            $ticketResult = queryParams(
                $this->connection,
                'SELECT id, user_id, title, status FROM support_tickets WHERE id = $1 FOR UPDATE',
                [$ticketId]
            );
            $ticket = pg_fetch_assoc($ticketResult);
            if (!$ticket) {
                throw new DashboardApiHttpException(404, 'Support request not found.');
            }
            if ($ticket['status'] === $status) {
                return;
            }

            queryParams(
                $this->connection,
                'UPDATE support_tickets SET status = $1 WHERE id = $2',
                [$status, $ticketId]
            );
            $this->writeUserAudit(
                (int)$ticket['user_id'],
                'SUPPORT_TICKET_STATUS_UPDATED',
                sprintf(
                    'Updated support request #%d (%s) from [%s] to [%s].',
                    $ticketId,
                    $ticket['title'],
                    $ticket['status'],
                    $status
                )
            );
        });

        return ['id' => (string)$ticketId, 'status' => $status];
    }

    private function saveTranslation(array $payload): array
    {
        $stringId = $this->positiveId($payload['stringId'] ?? null, 'stringId');
        $locale = $this->requiredString($payload, 'targetLocale', 2, 10);
        $translatedText = $this->requiredString($payload, 'translated', 1, 50000);
        $baseVersion = $payload['translationUpdatedAt'] ?? null;
        if ($baseVersion !== null && !is_string($baseVersion)) {
            throw new DashboardApiHttpException(422, 'Invalid translation version.');
        }
        $sourceResult = queryParams(
            $this->connection,
            'SELECT s.project_id, s.key_path, s.source_text, s.source_version_id, v.frozen_at
             FROM strings s
             JOIN source_versions v ON v.id = s.source_version_id
             WHERE s.id = $1',
            [$stringId]
        );
        $source = pg_fetch_assoc($sourceResult);
        if (!$source) {
            throw new DashboardApiHttpException(404, 'Source string not found.');
        }
        $projectId = (int)$source['project_id'];
        if (!$this->isAdmin && !ProjectAccess::canEditTranslation($this->connection, $projectId, $this->userId)) {
            throw new DashboardApiHttpException(403, 'You cannot edit translations in this project.');
        }
        if ($source['frozen_at'] !== null) {
            throw new DashboardApiHttpException(409, 'This source version is frozen.');
        }
        if (!VariableValidator::validate((string)$source['source_text'], $translatedText)) {
            throw new DashboardApiHttpException(422, 'The translation must preserve all variables from the source.');
        }
        $localeResult = queryParams(
            $this->connection,
            'SELECT 1 FROM project_locales
             WHERE project_id = $1 AND locale_code = $2
               AND (version_id IS NULL OR version_id = $3)',
            [$projectId, $locale, (int)$source['source_version_id']]
        );
        if (pg_num_rows($localeResult) === 0) {
            throw new DashboardApiHttpException(422, 'The target language is not enabled for this project.');
        }

        $this->transaction(function () use ($stringId, $locale, $translatedText, $baseVersion, $source, $projectId): void {
            queryParams($this->connection, 'SELECT pg_advisory_xact_lock($1, hashtext($2))', [$stringId, $locale]);
            $currentResult = queryParams(
                $this->connection,
                "SELECT id, translated_text, status,
                        to_char(updated_at AT TIME ZONE 'UTC',
                                'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS updated_at
                 FROM translations WHERE string_id = $1 AND locale = $2 FOR UPDATE",
                [$stringId, $locale]
            );
            $current = pg_fetch_assoc($currentResult);
            if (($current['updated_at'] ?? null) !== $baseVersion) {
                throw new DashboardApiHttpException(409, 'This translation changed. Reload the latest version before saving.');
            }
            queryParams($this->connection, "SELECT set_config('tms.user_id', $1, TRUE)", [(string)$this->userId]);
            if ($current) {
                queryParams(
                    $this->connection,
                    "UPDATE translations
                     SET translated_text = $1, status = 'needs_review',
                         updated_at = clock_timestamp(), user_id = $2
                     WHERE id = $3",
                    [$translatedText, $this->userId, (int)$current['id']]
                );
            } else {
                queryParams(
                    $this->connection,
                    "INSERT INTO translations (string_id, locale, translated_text, status, user_id)
                     VALUES ($1, $2, $3, 'needs_review', $4)",
                    [$stringId, $locale, $translatedText, $this->userId]
                );
            }
            (new AuditLogger($this->connection))->log(
                $projectId,
                $stringId,
                'UPDATE_TRANSLATION',
                "Updated translation [$locale] for key [" . (string)$source['key_path'] . '].',
                $this->userId
            );
        });
        return ['stringId' => (string)$stringId, 'targetLocale' => $locale];
    }

    private function reviewTranslation(array $payload): array
    {
        $stringId = $this->positiveId($payload['stringId'] ?? null, 'stringId');
        $locale = $this->requiredString($payload, 'targetLocale', 2, 10);
        $decision = $payload['decision'] ?? '';
        $comment = $this->optionalString($payload, 'comment', 5000);
        if (!in_array($decision, ['approve', 'request_revision'], true)) {
            throw new DashboardApiHttpException(422, 'Invalid review decision.');
        }
        if ($decision === 'request_revision' && mb_strlen(trim($comment), 'UTF-8') < 8) {
            throw new DashboardApiHttpException(422, 'Revision feedback must contain at least 8 characters.');
        }
        $sourceResult = queryParams(
            $this->connection,
            'SELECT s.project_id, s.key_path
             FROM strings s WHERE s.id = $1',
            [$stringId]
        );
        $source = pg_fetch_assoc($sourceResult);
        if (!$source) {
            throw new DashboardApiHttpException(404, 'Source string not found.');
        }
        $projectId = (int)$source['project_id'];
        if (!$this->isAdmin && !ProjectAccess::canReview($this->connection, $projectId, $this->userId)) {
            throw new DashboardApiHttpException(403, 'You cannot review translations in this project.');
        }
        $status = $decision === 'approve' ? 'approved' : 'rejected';
        $this->transaction(function () use ($stringId, $locale, $status, $decision, $comment, $source, $projectId): void {
            $translationResult = queryParams(
                $this->connection,
                'UPDATE translations
                 SET status = $1, updated_at = clock_timestamp()
                 WHERE string_id = $2 AND locale = $3
                   AND NULLIF(BTRIM(translated_text), \'\') IS NOT NULL
                 RETURNING id',
                [$status, $stringId, $locale]
            );
            $translationId = pg_fetch_result($translationResult, 0, 'id');
            if ($translationId === false) {
                throw new DashboardApiHttpException(404, 'Submitted translation not found.');
            }
            if ($decision === 'request_revision') {
                queryParams(
                    $this->connection,
                    'INSERT INTO translation_comments (string_id, locale, user_id, comment)
                     VALUES ($1, $2, $3, $4)',
                    [$stringId, $locale, $this->userId, trim($comment)]
                );
            }
            (new AuditLogger($this->connection))->log(
                $projectId,
                $stringId,
                $decision === 'approve' ? 'APPROVE_TRANSLATION' : 'REQUEST_TRANSLATION_REVISION',
                $decision === 'approve'
                    ? 'Approved translation [' . $locale . '] for key [' . (string)$source['key_path'] . '].'
                    : 'Requested revision for translation [' . $locale . '] for key [' . (string)$source['key_path'] . '].',
                $this->userId
            );
        });
        return ['stringId' => (string)$stringId, 'targetLocale' => $locale];
    }

    private function suggestTranslation(array $payload): array
    {
        $stringId = $this->positiveId($payload['stringId'] ?? null, 'stringId');
        $locale = $this->requiredString($payload, 'targetLocale', 2, 10);
        $sourceResult = queryParams(
            $this->connection,
            'SELECT s.project_id, s.source_text
             FROM strings s
             JOIN source_versions v ON v.id = s.source_version_id
             WHERE s.id = $1',
            [$stringId]
        );
        $source = pg_fetch_assoc($sourceResult);
        if (!$source) {
            throw new DashboardApiHttpException(404, 'Source string not found.');
        }
        $projectId = (int)$source['project_id'];
        if (!$this->isAdmin && !ProjectAccess::canEditTranslation($this->connection, $projectId, $this->userId)) {
            throw new DashboardApiHttpException(403, 'You cannot translate strings in this project.');
        }
        $targetResult = queryParams(
            $this->connection,
            'SELECT 1 FROM project_locales
             WHERE project_id = $1 AND locale_code = $2
               AND (version_id IS NULL OR version_id = (
                   SELECT id FROM source_versions WHERE project_id = $1 ORDER BY id DESC LIMIT 1
               ))',
            [$projectId, $locale]
        );
        if (pg_num_rows($targetResult) === 0) {
            throw new DashboardApiHttpException(422, 'The target language is not enabled for this project.');
        }
        $translator = new TranslationProviderService(new MyMemoryTranslationService());
        $translation = $translator->translateWithMetadata(
            TranslationProviderService::MYMEMORY,
            (string)$source['source_text'],
            'en',
            $locale
        );
        return [
            'translated' => $translation['translated_text'],
            'fallback' => $translation['fallback'],
            'targetLocale' => $translation['target_locale'],
            'requestedTargetLocale' => $translation['requested_target_locale'],
        ];
    }

    private function projectForUpdate(int $projectId): array
    {
        $result = queryParams(
            $this->connection,
            'SELECT id, name, created_by FROM projects WHERE id = $1',
            [$projectId]
        );
        $project = pg_fetch_assoc($result);
        if (!$project) {
            throw new DashboardApiHttpException(404, 'Project not found.');
        }
        return $project;
    }

    private function assertCanManageProject(int $projectId): void
    {
        if (!$this->isAdmin && !ProjectAccess::canManageMembers($this->connection, $projectId, $this->user)) {
            throw new DashboardApiHttpException(403, 'You cannot manage this project.');
        }
    }

    private function assertCanCreateProject(): void
    {
        if ($this->isAdmin) {
            return;
        }
        $result = queryParams(
            $this->connection,
            "SELECT EXISTS (
                SELECT 1 FROM project_members
                WHERE user_id = $1 AND role_in_project = 'Team Lead'
            )",
            [$this->userId]
        );
        if (pg_fetch_result($result, 0, 0) !== 't') {
            throw new DashboardApiHttpException(403, 'Only project managers can create projects.');
        }
    }

    private function validateAssignments(int $teamLeadId, array $translatorIds, array $reviewerIds): void
    {
        if (array_intersect($translatorIds, $reviewerIds) !== []) {
            throw new DashboardApiHttpException(422, 'A member cannot be assigned as both translator and reviewer.');
        }
        if (in_array($teamLeadId, $translatorIds, true) || in_array($teamLeadId, $reviewerIds, true)) {
            throw new DashboardApiHttpException(422, 'The team lead cannot also be assigned another project role.');
        }
        foreach (array_unique(array_merge([$teamLeadId], $translatorIds, $reviewerIds)) as $memberId) {
            $result = queryParams(
                $this->connection,
                'SELECT role, is_active FROM users WHERE id = $1',
                [$memberId]
            );
            $member = pg_fetch_assoc($result);
            if (!$member || $member['is_active'] !== 't') {
                throw new DashboardApiHttpException(422, 'Every project member must be an active account.');
            }
            if ($member['role'] === 'admin') {
                throw new DashboardApiHttpException(422, 'System administrators cannot be assigned project roles.');
            }
        }
    }

    private function saveMembers(int $projectId, array $members): void
    {
        foreach ($members as $memberId => $role) {
            queryParams(
                $this->connection,
                'INSERT INTO project_members (project_id, user_id, role_in_project)
                 VALUES ($1, $2, $3)',
                [$projectId, (int)$memberId, $role]
            );
        }
    }

    private function saveLocales(int $projectId, array $locales): void
    {
        queryParams(
            $this->connection,
            "DELETE FROM project_locales
             WHERE project_id = $1 AND version_id IS NULL AND locale_code <> 'en'",
            [$projectId]
        );
        foreach ($locales as $locale) {
            queryParams(
                $this->connection,
                'INSERT INTO project_locales (project_id, locale_code)
                 VALUES ($1, $2) ON CONFLICT DO NOTHING',
                [$projectId, $locale]
            );
        }
    }

    private function validateLocales(mixed $value): array
    {
        if (!is_array($value) || count($value) < 1 || count($value) > 30) {
            throw new DashboardApiHttpException(422, 'Select between 1 and 30 target languages.');
        }
        $locales = [];
        foreach ($value as $locale) {
            if (!is_string($locale) || preg_match('/^[a-z]{2,3}$/D', $locale) !== 1) {
                throw new DashboardApiHttpException(422, 'Invalid target language.');
            }
            $locales[$locale] = true;
        }
        $locales = array_keys($locales);
        $available = [];
        $result = queryParams(
            $this->connection,
            'SELECT code FROM locales WHERE code = ANY($1::text[]) AND code <> \'en\'',
            ['{' . implode(',', $locales) . '}']
        );
        while ($row = pg_fetch_assoc($result)) {
            $available[] = (string)$row['code'];
        }
        sort($available);
        sort($locales);
        if ($available !== $locales) {
            throw new DashboardApiHttpException(422, 'One or more selected languages are not supported.');
        }
        return $locales;
    }

    private function validateIds(mixed $value): array
    {
        if (!is_array($value) || count($value) > 100) {
            throw new DashboardApiHttpException(422, 'Invalid project assignment list.');
        }
        $ids = [];
        foreach ($value as $id) {
            $ids[] = $this->positiveId($id, 'assignment');
        }
        if (count(array_unique($ids)) !== count($ids)) {
            throw new DashboardApiHttpException(422, 'Duplicate project assignment.');
        }
        return $ids;
    }

    private function writeUserAudit(int $targetUserId, string $action, string $details): void
    {
        $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $ipAddress = is_string($remoteAddress) && filter_var($remoteAddress, FILTER_VALIDATE_IP)
            ? $remoteAddress
            : null;
        queryParams(
            $this->connection,
            'INSERT INTO audit_logs (actor_user_id, target_user_id, action_type, details, ip_address)
             VALUES ($1, $2, $3, $4, $5)',
            [$this->userId, $targetUserId, $action, $details, $ipAddress]
        );
    }

    private function transaction(callable $operation): mixed
    {
        if (pg_query($this->connection, 'BEGIN') === false) {
            throw new RuntimeException('DASHBOARD_TRANSACTION_FAILED');
        }
        try {
            $result = $operation();
            if (pg_query($this->connection, 'COMMIT') === false) {
                throw new RuntimeException('DASHBOARD_COMMIT_FAILED');
            }
            return $result;
        } catch (Throwable $exception) {
            if (pg_transaction_status($this->connection) !== PGSQL_TRANSACTION_IDLE) {
                pg_query($this->connection, 'ROLLBACK');
            }
            throw $exception;
        }
    }

    private function requireAdmin(): void
    {
        if (!$this->isAdmin) {
            throw new DashboardApiHttpException(403, 'System administrator access is required.');
        }
    }

    private function positiveId(mixed $value, string $field): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id === null || $id < 1) {
            throw new DashboardApiHttpException(422, 'Invalid ' . $field . '.');
        }
        return $id;
    }

    private function requiredString(array $payload, string $field, int $minLength, int $maxLength): string
    {
        $value = $payload[$field] ?? null;
        if (!is_string($value)) {
            throw new DashboardApiHttpException(422, 'Invalid ' . $field . '.');
        }
        $value = trim($value);
        $length = mb_strlen($value, 'UTF-8');
        if ($length < $minLength || $length > $maxLength) {
            throw new DashboardApiHttpException(422, 'Invalid ' . $field . '.');
        }
        return $value;
    }

    private function optionalString(array $payload, string $field, int $maxLength): string
    {
        $value = $payload[$field] ?? '';
        if (!is_string($value) || mb_strlen($value, 'UTF-8') > $maxLength) {
            throw new DashboardApiHttpException(422, 'Invalid ' . $field . '.');
        }
        return trim($value);
    }

    private function username(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9_.-]{3,50}$/D', trim($value)) !== 1) {
            throw new DashboardApiHttpException(422, 'Username must be 3–50 characters using letters, numbers, dots, underscores or hyphens.');
        }
        return trim($value);
    }

    private function email(mixed $value): string
    {
        if (!is_string($value)) {
            throw new DashboardApiHttpException(422, 'Invalid email address.');
        }
        $email = strtolower(trim($value));
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new DashboardApiHttpException(422, 'Invalid email address.');
        }
        return $email;
    }

    private function accountRole(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['user', 'admin'], true)) {
            throw new DashboardApiHttpException(422, 'Invalid account role.');
        }
        return $value;
    }
}
