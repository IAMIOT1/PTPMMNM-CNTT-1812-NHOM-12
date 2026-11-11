<?php
declare(strict_types=1);

require __DIR__ . '/helper.php';
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/TranslationRepository.php';
require __DIR__ . '/../src/ReleaseExporter.php';
require __DIR__ . '/../src/VariableValidator.php';
require __DIR__ . '/../src/RemoteContentService.php';
require __DIR__ . '/../src/GlossaryManager.php';
require __DIR__ . '/../src/LocaleRepository.php';

try {
    $connection = db();
} catch (Throwable $e) {
    fwrite(STDERR, "NOT_RUN: PostgreSQL unavailable or unconfigured\n");
    exit(2);
}

$outputPath = sys_get_temp_dir() . '/tms-release-' . bin2hex(random_bytes(8)) . '.json';
$transactionOpen = false;
try {
    if (pg_query($connection, 'BEGIN') === false) {
        throw new RuntimeException('Could not start test transaction');
    }
    $transactionOpen = true;

    $projectRes = queryParams(
        $connection,
        "INSERT INTO projects (name) VALUES ($1) RETURNING id",
        ['TMS workflow test']
    );
    $projectId = (int)pg_fetch_result($projectRes, 0, 'id');
    $localeRepository = new LocaleRepository($connection);
    $localeRepository->addLocaleForProject($projectId, 'vi');
    $addedLocale = $localeRepository->addLocaleForProject($projectId, 'en');
    same($addedLocale, true, 'project-locale-deployment-is-persisted');
    same($localeRepository->addLocaleForProject($projectId, 'en'), false, 'project-locale-deployment-is-idempotent');
    $activeLocaleCodes = array_column($localeRepository->getActiveForProject($projectId), 'locale_code');
    same(in_array('en', $activeLocaleCodes, true), true, 'deployed-project-locale-is-returned-for-rendering');
    $repo = new TranslationRepository($connection);
    $glossary = new GlossaryManager($connection);
    $glossaryTermId = $glossary->saveProjectTerm($projectId, 'vi', 'Dashboard', 'Bảng điều khiển', 'UI label');
    same($glossary->getTerms($projectId, 'vi')[0]['term'], 'Dashboard', 'glossary-term-is-listed');
    same(
        $glossary->saveProjectTerm($projectId, 'vi', 'Dashboard', 'Trang tổng quan', 'UI label', $glossaryTermId),
        $glossaryTermId,
        'glossary-term-is-updated'
    );
    $duplicateGlossaryRejected = false;
    try {
        $glossary->saveProjectTerm($projectId, 'vi', 'Dashboard', 'Duplicate', '');
    } catch (RuntimeException $exception) {
        $duplicateGlossaryRejected = $exception->getMessage() === 'GLOSSARY_TERM_EXISTS';
    }
    same($duplicateGlossaryRejected, true, 'glossary-duplicate-term-is-rejected');
    same($glossary->deleteProjectTerm($projectId + 1, 'vi', $glossaryTermId), false, 'glossary-delete-is-project-scoped');
    same($glossary->deleteProjectTerm($projectId, 'vi', $glossaryTermId), true, 'glossary-term-is-deleted');
    same($glossary->deleteProjectTerm($projectId, 'vi', $glossaryTermId), false, 'glossary-delete-missing-term-is-safe');
    $source = ['screen' => ['welcome' => 'Hello {name}', 'save' => 'Save']];
    $versionId = $repo->importSourceVersion($projectId, 'workflow-test', $source);
    $sameVersionId = $repo->importSourceVersion($projectId, 'workflow-test', $source);
    same($sameVersionId, $versionId, 'same-version-reused');
    $otherVersionId = $repo->importSourceVersion(
        $projectId,
        'workflow-test-other',
        ['screen' => ['welcome' => 'Welcome']]
    );
    same(
        $localeRepository->addLocaleForProject($projectId, 'JA', $versionId),
        true,
        'version-locale-input-is-normalized-and-persisted'
    );
    same(
        $localeRepository->addLocaleForProject($projectId, 'ja', $versionId),
        false,
        'version-locale-deployment-is-idempotent-after-normalization'
    );
    $versionLocaleCodes = array_column(
        $localeRepository->getActiveForProject($projectId, $versionId),
        'locale_code'
    );
    $otherVersionLocaleCodes = array_column(
        $localeRepository->getActiveForProject($projectId, $otherVersionId),
        'locale_code'
    );
    same(in_array('ja', $versionLocaleCodes, true), true, 'version-locale-is-returned-for-own-version');
    same(in_array('ja', $otherVersionLocaleCodes, true), false, 'version-locale-is-not-leaked-to-other-version');

    $urlProjectRes = queryParams(
        $connection,
        'INSERT INTO projects (name) VALUES ($1) RETURNING id',
        ['Source URL persistence test']
    );
    $urlProjectId = (int)pg_fetch_result($urlProjectRes, 0, 'id');
    $urlVersionId = $repo->importSourceVersion(
        $urlProjectId,
        'url-workflow-test',
        $source,
        'https://store.example.test/catalog.json'
    );
    $urlRes = queryParams(
        $connection,
        'SELECT source_url FROM source_versions WHERE id = $1',
        [$urlVersionId]
    );
    same(
        pg_fetch_result($urlRes, 0, 'source_url'),
        'https://store.example.test/catalog.json',
        'source-url-is-persisted-on-version'
    );

    $countRes = queryParams(
        $connection,
        'SELECT COUNT(*) FROM strings WHERE source_version_id = $1',
        [$versionId]
    );
    same((int)pg_fetch_result($countRes, 0, 0), 2, 'import-does-not-duplicate-keys');

    queryParams(
        $connection,
        "INSERT INTO release_sync_history
            (project_id, source_version_id, type, version_number, record_count)
         VALUES ($1, $2, 'version_lock', $3, 2)
         ON CONFLICT DO NOTHING",
        [$projectId, $versionId, 'workflow-test']
    );
    queryParams(
        $connection,
        "INSERT INTO release_sync_history
            (project_id, source_version_id, type, version_number, record_count)
         VALUES ($1, $2, 'version_lock', $3, 2)
         ON CONFLICT DO NOTHING",
        [$projectId, $versionId, 'workflow-test']
    );
    $lockHistoryCount = queryParams(
        $connection,
        "SELECT COUNT(*) FROM release_sync_history
         WHERE source_version_id = $1 AND type = 'version_lock'",
        [$versionId]
    );
    same((int)pg_fetch_result($lockHistoryCount, 0, 0), 1, 'version-lock-history-is-idempotent');

    $syncHistoryInsert = queryParams(
        $connection,
        "INSERT INTO release_sync_history
            (project_id, source_version_id, type, version_number, locale, endpoint_url,
             record_count, new_data, target_rollback_data)
         VALUES ($1, $2, 'api_sync', $3, 'en', $4, 2, $5::JSONB, $6::JSONB)
         RETURNING id",
        [
            $projectId,
            $versionId,
            'workflow-test',
            'https://example.test/api/translations',
            '{"menu":"new"}',
            '{"menu":"original"}',
        ]
    );
    $syncHistoryId = (int)pg_fetch_result($syncHistoryInsert, 0, 'id');
    same(
        RemoteContentService::currentSyncData($connection, $projectId, 'https://example.test/api/translations'),
        '{"menu": "new"}',
        'api-sync-chain-uses-latest-active-data'
    );
    $secondSyncHistoryInsert = queryParams(
        $connection,
        "INSERT INTO release_sync_history
            (project_id, source_version_id, type, version_number, locale, endpoint_url,
             record_count, new_data, target_rollback_data)
         VALUES ($1, $2, 'api_sync', $3, 'en', $4, 2, $5::JSONB, $6::JSONB)
         RETURNING id",
        [
            $projectId,
            $versionId,
            'workflow-test-2',
            'https://example.test/api/translations',
            '{"menu":"newer"}',
            '{"menu":"new"}',
        ]
    );
    $secondSyncHistoryId = (int)pg_fetch_result($secondSyncHistoryInsert, 0, 'id');
    $secondTarget = queryParams(
        $connection,
        'SELECT target_rollback_data::TEXT FROM release_sync_history WHERE id = $1',
        [$secondSyncHistoryId]
    );
    same(
        pg_fetch_result($secondTarget, 0, 0),
        '{"menu": "new"}',
        'second-api-sync-targets-previous-successful-payload'
    );
    $syncHistoryRevoke = queryParams(
        $connection,
        "UPDATE release_sync_history
         SET status = 'revoked', revoked_at = clock_timestamp()
         WHERE id = $1 AND type = 'api_sync'
         RETURNING status, record_count, endpoint_url",
        [$secondSyncHistoryId]
    );
    $syncHistoryRow = pg_fetch_assoc($syncHistoryRevoke);
    same($syncHistoryRow['status'], 'revoked', 'api-sync-history-can-be-revoked');
    same((int)$syncHistoryRow['record_count'], 2, 'api-sync-history-retains-record-count');
    same($syncHistoryRow['endpoint_url'], 'https://example.test/api/translations', 'api-sync-history-retains-endpoint');
    same(
        RemoteContentService::currentSyncData($connection, $projectId, 'https://example.test/api/translations'),
        '{"menu": "new"}',
        'api-sync-chain-falls-back-to-previous-active-payload'
    );
    queryParams(
        $connection,
        "UPDATE release_sync_history SET status = 'revoked', revoked_at = clock_timestamp()
         WHERE id = $1",
        [$syncHistoryId]
    );
    same(
        RemoteContentService::currentSyncData($connection, $projectId, 'https://example.test/api/translations'),
        '{"menu": "original"}',
        'api-sync-chain-recovers-original-baseline-after-all-syncs-revoked'
    );

    $htmlProjectRes = queryParams(
        $connection,
        'INSERT INTO projects (name) VALUES ($1) RETURNING id',
        ['HTML ingestion workflow test']
    );
    $htmlProjectId = (int)pg_fetch_result($htmlProjectRes, 0, 'id');
    $htmlSnapshot = '<!doctype html><html><head><style>.hero{color:purple}</style></head><body><main class="hero"><h1>Welcome</h1><p>Read more</p></main></body></html>';
    $htmlVersionId = $repo->importSourceVersion(
        $htmlProjectId,
        'html-workflow-test',
        RemoteContentService::parseHtml($htmlSnapshot),
        'https://store.example.test/catalog',
        $htmlSnapshot
    );
    $snapshotRes = queryParams(
        $connection,
        'SELECT source_html FROM source_versions WHERE id = $1',
        [$htmlVersionId]
    );
    same(pg_fetch_result($snapshotRes, 0, 'source_html'), $htmlSnapshot, 'html-source-snapshot-is-persisted');

    $repo->importSourceVersion(
        $htmlProjectId,
        'html-workflow-test',
        RemoteContentService::parseHtml($htmlSnapshot)
    );
    $clearedSnapshotRes = queryParams(
        $connection,
        'SELECT source_html FROM source_versions WHERE id = $1',
        [$htmlVersionId]
    );
    same(pg_fetch_result($clearedSnapshotRes, 0, 'source_html'), null, 'non-html-reimport-clears-old-snapshot');

    $htmlStringRes = queryParams(
        $connection,
        'SELECT key_path, source_text FROM strings
         WHERE source_version_id = $1 ORDER BY key_path',
        [$htmlVersionId]
    );
    $htmlStrings = pg_fetch_all($htmlStringRes);
    same(count($htmlStrings), 2, 'html-source-import-creates-string-rows');
    same($htmlStrings[0]['key_path'], 'page.text_0001', 'html-source-import-creates-stable-key-paths');
    same($htmlStrings[0]['source_text'], 'Welcome', 'html-source-import-persists-extracted-text');

    $welcomeRes = queryParams(
        $connection,
        "SELECT id FROM strings WHERE source_version_id = $1 AND key_path = 'screen.welcome'",
        [$versionId]
    );
    $welcomeId = (int)pg_fetch_result($welcomeRes, 0, 'id');
    $saveRes = queryParams(
        $connection,
        "SELECT id FROM strings WHERE source_version_id = $1 AND key_path = 'screen.save'",
        [$versionId]
    );
    $saveId = (int)pg_fetch_result($saveRes, 0, 'id');
    $commentUserRes = queryParams(
        $connection,
        'INSERT INTO users (username, email, password_hash)
         VALUES ($1, $2, $3) RETURNING id',
        [
            'workflow_' . bin2hex(random_bytes(5)),
            'workflow_' . bin2hex(random_bytes(5)) . '@example.test',
            password_hash('workflow-test-password', PASSWORD_BCRYPT)
        ]
    );
    $commentUserId = (int)pg_fetch_result($commentUserRes, 0, 'id');
    queryParams(
        $connection,
        "INSERT INTO project_members (project_id, user_id, role_in_project)
         VALUES ($1, $2, 'Translator')",
        [$projectId, $commentUserId]
    );
    queryParams(
        $connection,
        "INSERT INTO translations (string_id, locale, translated_text, status)
         VALUES ($1, 'en', 'Hi {name}', 'approved'), ($2, 'en', 'Save', 'rejected')",
        [$welcomeId, $saveId]
    );
    queryParams(
        $connection,
        'INSERT INTO translation_comments (string_id, locale, user_id, comment)
         VALUES ($1, $2, $3, $4)',
        [$welcomeId, 'en', $commentUserId, 'Keep the variable in the greeting']
    );
    $commentRes = queryParams(
        $connection,
        'SELECT comment, user_id FROM translation_comments WHERE string_id = $1 AND locale = $2',
        [$welcomeId, 'en']
    );
    $comment = pg_fetch_assoc($commentRes);
    same($comment['comment'], 'Keep the variable in the greeting', 'translation-comment-is-stored');
    same((int)$comment['user_id'], $commentUserId, 'translation-comment-keeps-author');

    $repo->importSourceVersion(
        $projectId,
        'workflow-test',
        ['screen' => ['welcome' => 'Welcome {name}', 'save' => 'Save']]
    );
    $statusesRes = queryParams(
        $connection,
        'SELECT s.key_path, t.status
         FROM strings s JOIN translations t ON t.string_id = s.id
         WHERE s.source_version_id = $1 AND t.locale = $2
         ORDER BY s.key_path',
        [$versionId, 'en']
    );
    $statuses = [];
    while ($row = pg_fetch_assoc($statusesRes)) {
        $statuses[$row['key_path']] = $row['status'];
    }
    same(count($statuses), 0, 'same-version-import-clears-translations');
    $newWelcomeRes = queryParams(
        $connection,
        "SELECT id FROM strings WHERE source_version_id = $1 AND key_path = 'screen.welcome'",
        [$versionId]
    );
    $newWelcomeId = (int)pg_fetch_result($newWelcomeRes, 0, 'id');
    $newSaveRes = queryParams(
        $connection,
        "SELECT id FROM strings WHERE source_version_id = $1 AND key_path = 'screen.save'",
        [$versionId]
    );
    $newSaveId = (int)pg_fetch_result($newSaveRes, 0, 'id');
    same($newWelcomeId !== $welcomeId, true, 'same-version-import-replaces-string-rows');
    $commentCountRes = queryParams(
        $connection,
        'SELECT COUNT(*) FROM translation_comments WHERE string_id = $1',
        [$welcomeId]
    );
    same((int)pg_fetch_result($commentCountRes, 0, 0), 0, 'same-version-import-clears-comments');
    same(VariableValidator::validate('Hello {name}', 'Bonjour {name}'), true, 'placeholder-matches');
    same(VariableValidator::validate('Hello {name}', 'Bonjour'), false, 'placeholder-missing-rejected');
    same(VariableValidator::validate('Hello {name}', 'Bonjour {other}'), false, 'placeholder-extra-rejected');
    same(VariableValidator::validate('Hi %s, {name}', 'Salut %s, {name}'), true, 'printf-and-brace-placeholders-match');
    same(VariableValidator::validate('Hi %s, {name}', 'Salut {name}'), false, 'printf-placeholder-missing-rejected');
    same(VariableValidator::validate('%s then {name}', '{name} then %s'), true, 'placeholder-order-change-allowed');
    same(VariableValidator::validate('Index {0}', 'Indice { 0 }'), true, 'numbered-placeholder-whitespace-normalized');
    same(VariableValidator::validate('{{ variable }}', '{{variable}}'), true, 'double-brace-whitespace-normalized');
    same(VariableValidator::validate('<b>{name}</b>', '</B>Bonjour <B>{name}</B>'), false, 'html-tag-duplicate-rejected');
    same(VariableValidator::validate('<br>Hi</br>', '<br/>Salut</br>'), true, 'html-tag-case-and-self-closing-normalized');
    same(VariableValidator::validate('<b>Hi</b>', '<i>Salut</i>'), false, 'html-tag-change-rejected');
    same(VariableValidator::validate('{name} {name}', '{name}'), false, 'duplicate-placeholder-count-checked');
    $nestedWithDottedKey = ['auth' => ['login.page' => 'Sign in']];
    same(
        JsonParser::unflatten(JsonParser::flatten($nestedWithDottedKey)),
        $nestedWithDottedKey,
        'nested-json-literal-dot-key-round-trips'
    );

    $nextVersionId = $repo->importSourceVersion(
        $projectId,
        'workflow-test-2',
        ['screen' => ['welcome' => 'Welcome {name}', 'save' => 'Save']]
    );
    $nextStatusesRes = queryParams(
        $connection,
        'SELECT s.key_path, s.id AS string_id, t.status, t.translated_text
         FROM strings s JOIN translations t ON t.string_id = s.id
         WHERE s.source_version_id = $1 AND t.locale = $2
         ORDER BY s.key_path',
        [$nextVersionId, 'en']
    );
    $nextRows = [];
    while ($row = pg_fetch_assoc($nextStatusesRes)) {
        $nextRows[$row['key_path']] = $row;
    }
    same(count($nextRows), 0, 'new-version-import-starts-without-translations');

    $timestampRes = queryParams(
        $connection,
        'SELECT updated_at::text AS updated_at FROM translations WHERE string_id = $1 AND locale = $2',
        [$newWelcomeId, 'en']
    );
    $baseline = pg_num_rows($timestampRes) > 0
        ? pg_fetch_result($timestampRes, 0, 'updated_at')
        : '';
    $saveSql = "INSERT INTO translations (string_id, locale, translated_text, status, updated_at)
                VALUES ($1, $2, $3, $4, clock_timestamp())
                ON CONFLICT (string_id, locale)
                DO UPDATE SET translated_text = EXCLUDED.translated_text,
                              status = EXCLUDED.status,
                              updated_at = clock_timestamp()
                WHERE translations.updated_at = NULLIF($5, '')::timestamptz
                RETURNING id";
    $firstWrite = queryParams(
        $connection,
        $saveSql,
        [$newWelcomeId, 'en', 'Welcome {name}', 'approved', $baseline]
    );
    same(pg_num_rows($firstWrite), 1, 'first-concurrent-write-succeeds');
    $staleWrite = queryParams(
        $connection,
        $saveSql,
        [$newWelcomeId, 'en', 'Stale {name}', 'approved', $baseline]
    );
    same(pg_num_rows($staleWrite), 0, 'stale-write-is-rejected');
    $firstInsert = queryParams(
        $connection,
        $saveSql,
        [$newSaveId, 'vi', 'Lưu', 'approved', '']
    );
    same(pg_num_rows($firstInsert), 1, 'first-empty-translation-insert-succeeds');
    $staleInsert = queryParams(
        $connection,
        $saveSql,
        [$newSaveId, 'vi', 'Lưu cũ', 'approved', '']
    );
    same(pg_num_rows($staleInsert), 0, 'competing-empty-translation-insert-is-rejected');

    $exporter = new ReleaseExporter($connection);
    $release = $exporter->generateRelease($projectId, 'en', 'workflow-test-2', $outputPath);
    $decoded = json_decode($release, true, 512, JSON_THROW_ON_ERROR);
    same($decoded['screen']['welcome'], 'Welcome {name}', 'release-uses-current-source');
    pg_query($connection, 'SAVEPOINT release_immutability_test');
    $snapshotMutationRejected = false;
    try {
        queryParams(
            $connection,
            "UPDATE release_snapshots SET json_content = '{}' WHERE source_version_id = $1 AND locale = $2",
            [$nextVersionId, 'en']
        );
    } catch (RuntimeException $e) {
        $snapshotMutationRejected = true;
        pg_query($connection, 'ROLLBACK TO SAVEPOINT release_immutability_test');
    }
    pg_query($connection, 'RELEASE SAVEPOINT release_immutability_test');
    same($snapshotMutationRejected, true, 'release-snapshot-cannot-be-mutated');
    $nextWelcomeRes = queryParams(
        $connection,
        "SELECT id FROM strings WHERE source_version_id = $1 AND key_path = 'screen.welcome'",
        [$nextVersionId]
    );
    $nextWelcomeId = (int)pg_fetch_result($nextWelcomeRes, 0, 'id');
    queryParams(
        $connection,
        "INSERT INTO translations (string_id, locale, translated_text, status)
         VALUES ($1, 'en', 'Welcome after release', 'approved')",
        [$nextWelcomeId]
    );
    queryParams(
        $connection,
        "UPDATE translations SET translated_text = 'Changed after release', updated_at = clock_timestamp()
         WHERE string_id = $1 AND locale = $2",
        [$nextWelcomeId, 'en']
    );
    $secondRelease = $exporter->generateRelease($projectId, 'en', 'workflow-test-2', $outputPath);
    same($secondRelease, $release, 'release-snapshot-is-immutable');

    $diffVersionId = $repo->importSourceVersion(
        $projectId,
        'workflow-test-3',
        ['screen' => ['welcome' => 'Greetings {name}', 'added' => 'New']]
    );
    $diffs = $repo->getVersionChanges($diffVersionId);
    $diffByKey = [];
    foreach ($diffs as $diff) {
        $diffByKey[$diff['key_path']] = $diff['change_type'];
    }
    same($diffByKey['screen.welcome'], 'added', 'source-diff-records-added-welcome');
    same($diffByKey['screen.added'], 'added', 'source-diff-records-added-key');
    same(array_key_exists('screen.save', $diffByKey), false, 'source-diff-has-no-deleted-key');
    queryParams(
        $connection,
        'UPDATE source_versions SET frozen_at = clock_timestamp() WHERE id = $1',
        [$diffVersionId]
    );
    $freezeGuardRejectedWrite = false;
    pg_query($connection, 'SAVEPOINT frozen_version_test');
    try {
        queryParams(
            $connection,
            "INSERT INTO translations (string_id, locale, translated_text, status)
             SELECT id, 'ja', '変更', 'draft' FROM strings
             WHERE source_version_id = $1 LIMIT 1",
            [$diffVersionId]
        );
    } catch (RuntimeException $e) {
        $freezeGuardRejectedWrite = true;
        pg_query($connection, 'ROLLBACK TO SAVEPOINT frozen_version_test');
    }
    pg_query($connection, 'RELEASE SAVEPOINT frozen_version_test');
    same($freezeGuardRejectedWrite, true, 'frozen-version-rejects-translation-writes');
    $frozenImportRejected = false;
    try {
        $repo->importSourceVersion(
            $projectId,
            'workflow-test-3',
            ['screen' => ['welcome' => 'Different']]
        );
    } catch (RuntimeException $e) {
        $frozenImportRejected = $e->getMessage() === 'SOURCE_VERSION_FROZEN';
    }
    same($frozenImportRejected, true, 'frozen-version-rejects-reimport');
} catch (Throwable $e) {
    $failed++;
    echo 'FAIL tms workflow: ' . $e->getMessage() . ' (' . pg_last_error($connection) . ")\n";
} finally {
    if ($transactionOpen) {
        pg_query($connection, 'ROLLBACK');
    }
    if (is_file($outputPath)) {
        unlink($outputPath);
    }
}

finish();
