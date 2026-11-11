<?php
declare(strict_types=1);

require __DIR__ . '/helper.php';
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/AiTranslator.php';

if (!function_exists('pcntl_fork') || !function_exists('curl_init')) {
    fwrite(STDERR, "NOT_RUN: pcntl and cURL extensions are required\n");
    exit(2);
}

$server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if ($server === false) {
    fwrite(STDERR, "NOT_RUN: local mock server unavailable\n");
    exit(2);
}
$address = stream_socket_get_name($server, false);
$port = (int)substr(strrchr((string)$address, ':'), 1);
$pid = pcntl_fork();
if ($pid === -1) {
    fclose($server);
    fwrite(STDERR, "NOT_RUN: could not start local mock server\n");
    exit(2);
}

if ($pid === 0) {
    for ($requestIndex = 0; $requestIndex < 14; $requestIndex++) {
        $client = stream_socket_accept($server, 15);
        if ($client === false) {
            fclose($server);
            exit(1);
        }
        while (($line = fgets($client)) !== false && trim($line) !== '') {
        }

        if ($requestIndex >= 2 && $requestIndex <= 10) {
            $status = '503 Service Unavailable';
            $responseBody = json_encode([
                'error' => ['message' => 'The model is overloaded due to high demand.']
            ]);
        } else {
            $status = '200 OK';
            $responseBody = json_encode([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'Translated string']]]
                ]]
            ]);
        }
        fwrite(
            $client,
            "HTTP/1.1 {$status}\r\nContent-Type: application/json\r\nContent-Length: " .
                strlen($responseBody) . "\r\nConnection: close\r\n\r\n" . $responseBody
        );
        fclose($client);
    }
    fclose($server);
    exit(0);
}

fclose($server);
try {
    $connection = db();
} catch (Throwable) {
    posix_kill($pid, SIGTERM);
    pcntl_waitpid($pid, $status);
    fwrite(STDERR, "NOT_RUN: PostgreSQL unavailable or unconfigured\n");
    exit(2);
}

$transactionOpen = false;
try {
    if (pg_query($connection, 'BEGIN') === false) {
        throw new RuntimeException('Could not start test transaction');
    }
    $transactionOpen = true;

    $projectRes = queryParams(
        $connection,
        'INSERT INTO projects (name) VALUES ($1) RETURNING id',
        ['AI translation batch test']
    );
    $projectId = (int)pg_fetch_result($projectRes, 0, 'id');
    $versionRes = queryParams(
        $connection,
        'INSERT INTO source_versions (project_id, version_number) VALUES ($1, $2) RETURNING id',
        [$projectId, 'ai-batch-test']
    );
    $versionId = (int)pg_fetch_result($versionRes, 0, 'id');

    $stringIds = [];
    for ($index = 1; $index <= 7; $index++) {
        $stringRes = queryParams(
            $connection,
            'INSERT INTO strings (project_id, source_version_id, key_path, source_text)
             VALUES ($1, $2, $3, $4) RETURNING id',
            [$projectId, $versionId, 'key.' . $index, 'Source string ' . $index]
        );
        $stringIds[$index] = (int)pg_fetch_result($stringRes, 0, 'id');
    }
    queryParams(
        $connection,
        "INSERT INTO translations (string_id, locale, translated_text, status)
         VALUES ($1, 'en', 'Keep existing draft', 'draft'),
                ($2, 'en', 'Keep approved', 'approved')",
        [$stringIds[1], $stringIds[2]]
    );

    $translator = new AiTranslator($connection, 'test-key', 'http://127.0.0.1:' . $port);
    $firstBatch = $translator->translateNextBatch($versionId, 'en', 1, 5);
    same($firstBatch['processed'], 2, 'batch-persists-strings-before-provider-failure');
    same($firstBatch['remaining'], 3, 'batch-reports-untranslated-strings-after-failure');
    same($firstBatch['error'] instanceof RuntimeException, true, 'batch-exposes-provider-failure-for-resume');

    $secondBatch = $translator->translateNextBatch($versionId, 'en', 1, 5);
    same($secondBatch['processed'], 3, 'resumed-batch-translates-only-remaining-strings');
    same($secondBatch['remaining'], 0, 'resumed-batch-completes-all-eligible-strings');

    $savedRes = queryParams(
        $connection,
        'SELECT s.key_path, t.translated_text, t.status
         FROM strings s JOIN translations t ON t.string_id = s.id
         WHERE s.source_version_id = $1 AND t.locale = $2
         ORDER BY s.key_path',
        [$versionId, 'en']
    );
    $saved = [];
    while ($row = pg_fetch_assoc($savedRes)) {
        $saved[$row['key_path']] = [$row['translated_text'], $row['status']];
    }
    same($saved['key.1'], ['Keep existing draft', 'draft'], 'batch-preserves-existing-draft');
    same($saved['key.2'], ['Keep approved', 'approved'], 'batch-preserves-approved-translation');
    same($saved['key.7'], ['Translated string', 'draft'], 'batch-saves-translations-as-drafts');

    pcntl_waitpid($pid, $status);
    same(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, true, 'batch-mock-server-completed');
} catch (Throwable $exception) {
    $failed++;
    echo 'FAIL ai translation batch: ' . $exception->getMessage() . "\n";
    pcntl_waitpid($pid, $status);
} finally {
    if ($transactionOpen) {
        pg_query($connection, 'ROLLBACK');
    }
}

finish();
