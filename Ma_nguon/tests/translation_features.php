<?php
declare(strict_types=1);

require __DIR__ . '/helper.php';
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/NotificationManager.php';
require __DIR__ . '/../src/TranslationPackageExporter.php';

try {
    $connection = db();
} catch (Throwable $e) {
    fwrite(STDERR, "NOT_RUN: PostgreSQL unavailable or unconfigured\n");
    exit(2);
}

$transactionOpen = false;
try {
    if (pg_query($connection, 'BEGIN') === false) {
        throw new RuntimeException('Could not start test transaction');
    }
    $transactionOpen = true;

    $userRes = queryParams(
        $connection,
        'INSERT INTO users (username, email, password_hash)
         VALUES ($1, $2, $3) RETURNING id',
        [
            'features_' . bin2hex(random_bytes(5)),
            'features_' . bin2hex(random_bytes(5)) . '@example.test',
            password_hash('feature-test-password', PASSWORD_BCRYPT)
        ]
    );
    $userId = (int)pg_fetch_result($userRes, 0, 'id');
    $projectRes = queryParams(
        $connection,
        'INSERT INTO projects (name, created_by) VALUES ($1, $2) RETURNING id',
        ['Translation feature test', $userId]
    );
    $projectId = (int)pg_fetch_result($projectRes, 0, 'id');
    $versionRes = queryParams(
        $connection,
        'INSERT INTO source_versions (project_id, version_number) VALUES ($1, $2) RETURNING id',
        [$projectId, 'features-test']
    );
    $versionId = (int)pg_fetch_result($versionRes, 0, 'id');
    $stringRes = queryParams(
        $connection,
        'INSERT INTO strings (project_id, source_version_id, key_path, source_text)
         VALUES ($1, $2, $3, $4) RETURNING id',
        [$projectId, $versionId, 'menu.play', 'Play']
    );
    $stringId = (int)pg_fetch_result($stringRes, 0, 'id');

    queryParams($connection, "SELECT set_config('tms.user_id', $1, TRUE)", [(string)$userId]);
    $translationRes = queryParams(
        $connection,
        "INSERT INTO translations (string_id, locale, translated_text, user_id)
         VALUES ($1, 'en', 'Start', $2) RETURNING id",
        [$stringId, $userId]
    );
    $translationId = (int)pg_fetch_result($translationRes, 0, 'id');
    queryParams(
        $connection,
        "UPDATE translations SET translated_text = 'Begin' WHERE id = $1",
        [$translationId]
    );
    $historyRes = queryParams(
        $connection,
        'SELECT old_text, new_text, changed_by_user_id
         FROM translation_history WHERE translation_id = $1 ORDER BY id',
        [$translationId]
    );
    $history = pg_fetch_all($historyRes);
    same(count($history), 2, 'text-insert-and-update-record-history');
    same($history[0]['old_text'], null, 'initial-history-has-null-old-text');
    same($history[0]['new_text'], 'Start', 'initial-history-records-new-text');
    same($history[1]['old_text'], 'Start', 'update-history-records-old-text');
    same($history[1]['new_text'], 'Begin', 'update-history-records-new-text');
    same((int)$history[1]['changed_by_user_id'], $userId, 'history-records-actor');

    $package = (new TranslationPackageExporter($connection))->export($versionId, 'en');
    same(json_decode($package, true), ['menu' => ['play' => 'Begin']], 'translation-export-preserves-nested-json');

    NotificationManager::create($connection, $userId, 'Test notification');
    $manager = new NotificationManager($connection);
    same($manager->unreadCount($userId), 1, 'notification-unread-count');
    $recent = $manager->recentForUser($userId);
    same($recent[0]['message'], 'Test notification', 'notification-recent-list');
    queryParams($connection, 'UPDATE notifications SET is_read = TRUE WHERE user_id = $1', [$userId]);
    same($manager->unreadCount($userId), 0, 'notification-marked-read');
} catch (Throwable $e) {
    $failed++;
    echo 'FAIL translation features: ' . $e->getMessage() . ' (' . pg_last_error($connection) . ")\n";
} finally {
    if ($transactionOpen) {
        pg_query($connection, 'ROLLBACK');
    }
}

finish();
