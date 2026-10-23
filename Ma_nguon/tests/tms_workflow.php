<?php
declare(strict_types=1);

require __DIR__ . '/helper.php';
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/TranslationRepository.php';
require __DIR__ . '/../src/ReleaseExporter.php';
require __DIR__ . '/../src/VariableValidator.php';

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
    $repo = new TranslationRepository($connection);
    $source = ['screen' => ['welcome' => 'Hello {name}', 'save' => 'Save']];
    $versionId = $repo->importSourceVersion($projectId, 'workflow-test', $source);
    $sameVersionId = $repo->importSourceVersion($projectId, 'workflow-test', $source);
    same($sameVersionId, $versionId, 'same-version-reused');

    $countRes = queryParams(
        $connection,
        'SELECT COUNT(*) FROM strings WHERE source_version_id = $1',
        [$versionId]
    );
    same((int)pg_fetch_result($countRes, 0, 0), 2, 'import-does-not-duplicate-keys');

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
    queryParams(
        $connection,
        "INSERT INTO translations (string_id, locale, translated_text, status)
         VALUES ($1, 'en', 'Hi {name}', 'approved'), ($2, 'en', 'Save', 'rejected')",
        [$welcomeId, $saveId]
    );

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
    same($statuses['screen.welcome'], 'needs_review', 'changed-source-needs-review');
    same($statuses['screen.save'], 'rejected', 'unchanged-source-keeps-status');
    same(VariableValidator::validate('Hello {name}', 'Bonjour {name}'), true, 'placeholder-matches');
    same(VariableValidator::validate('Hello {name}', 'Bonjour'), false, 'placeholder-missing-rejected');
    same(VariableValidator::validate('Hello {name}', 'Bonjour {other}'), false, 'placeholder-extra-rejected');

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
    same($nextRows['screen.welcome']['status'], 'needs_review', 'changed-source-carries-review-state');
    same($nextRows['screen.save']['status'], 'rejected', 'unchanged-source-carries-status');
    same($nextRows['screen.welcome']['translated_text'], 'Hi {name}', 'translations-copied-to-new-version');

    $timestampRes = queryParams(
        $connection,
        'SELECT updated_at::text AS updated_at FROM translations WHERE string_id = $1 AND locale = $2',
        [$welcomeId, 'en']
    );
    $baseline = pg_fetch_result($timestampRes, 0, 'updated_at');
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
        [$welcomeId, 'en', 'Welcome {name}', 'approved', $baseline]
    );
    same(pg_num_rows($firstWrite), 1, 'first-concurrent-write-succeeds');
    $staleWrite = queryParams(
        $connection,
        $saveSql,
        [$welcomeId, 'en', 'Stale {name}', 'approved', $baseline]
    );
    same(pg_num_rows($staleWrite), 0, 'stale-write-is-rejected');
    $firstInsert = queryParams(
        $connection,
        $saveSql,
        [$saveId, 'vi', 'Lưu', 'approved', '']
    );
    same(pg_num_rows($firstInsert), 1, 'first-empty-translation-insert-succeeds');
    $staleInsert = queryParams(
        $connection,
        $saveSql,
        [$saveId, 'vi', 'Lưu cũ', 'approved', '']
    );
    same(pg_num_rows($staleInsert), 0, 'competing-empty-translation-insert-is-rejected');

    $exporter = new ReleaseExporter($connection);
    $release = $exporter->generateRelease($projectId, 'en', 'workflow-test-2', $outputPath);
    $decoded = json_decode($release, true, 512, JSON_THROW_ON_ERROR);
    same($decoded['screen']['welcome'], 'Hi {name}', 'release-retains-nested-json');
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
    queryParams(
        $connection,
        "UPDATE translations SET translated_text = 'Changed after release', updated_at = clock_timestamp()
         WHERE string_id = $1 AND locale = $2",
        [(int)$nextRows['screen.welcome']['string_id'], 'en']
    );
    $secondRelease = $exporter->generateRelease($projectId, 'en', 'workflow-test-2', $outputPath);
    same($secondRelease, $release, 'release-snapshot-is-immutable');
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
