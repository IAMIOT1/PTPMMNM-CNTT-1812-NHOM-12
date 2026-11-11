<?php
declare(strict_types=1);

require __DIR__ . '/helper.php';
require __DIR__ . '/../src/AiTranslator.php';

if (!function_exists('pcntl_fork')) {
    fwrite(STDERR, "NOT_RUN: pcntl extension unavailable\n");
    exit(2);
}
if (!function_exists('curl_init')) {
    fwrite(STDERR, "NOT_RUN: PHP cURL extension unavailable\n");
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
    $primaryModelPath = '/v1beta/models/gemini-3.8-flash:generateContent';
    $fallbackModelPath = '/v1beta/models/gemini-3.6-flash:generateContent';
    for ($requestIndex = 0; $requestIndex < 10; $requestIndex++) {
        $client = stream_socket_accept($server, 10);
        if ($client === false) {
            fclose($server);
            exit(1);
        }

        $headers = '';
        while (($line = fgets($client)) !== false && trim($line) !== '') {
            $headers .= $line;
        }
        preg_match('/Content-Length:\s*(\d+)/i', $headers, $matches);
        $requestBody = isset($matches[1])
            ? stream_get_contents($client, (int)$matches[1])
            : '';
        $request = json_decode((string)$requestBody, true);
        $prompt = (string)($request['systemInstruction']['parts'][0]['text'] ?? '');
        $requestLine = strtok($headers, "\r\n");
        $expectedModelPath = $requestIndex === 5 || $requestIndex === 7
            ? $fallbackModelPath
            : $primaryModelPath;
        $expectedRequestPrefix = 'POST ' . $expectedModelPath . '?key=test-gemini-key ';
        $isValidRequest = is_string($requestLine) && str_starts_with($requestLine, $expectedRequestPrefix);

        if ($requestIndex === 9) {
            $contents = $request['contents'] ?? [];
            $isValidChat = $isValidRequest &&
                str_contains($prompt, 'AI assistant inside a translation management system') &&
                ($contents[0]['role'] ?? '') === 'user' &&
                ($contents[0]['parts'][0]['text'] ?? '') === 'How do I review translations?' &&
                ($request['generationConfig']['temperature'] ?? null) === 0.3;
            $status = $isValidChat ? '200 OK' : '400 Bad Request';
            $responseBody = $isValidChat
                ? json_encode(['candidates' => [[
                    'content' => ['parts' => [['text' => 'Review the draft and approve it.']]]
                ]]])
                : json_encode(['error' => ['message' => 'mock chat request validation failed']]);
        } elseif ($requestIndex < 2 || $requestIndex === 5 || $requestIndex === 7) {
            $expectedLocale = $requestIndex === 1 ? 'ja' : 'en';
            $hasExpectedPrompt = str_contains($prompt, $expectedLocale) &&
                ($expectedLocale === 'en'
                    ? str_contains($prompt, '{name}') &&
                        str_contains($prompt, '{count}') &&
                        str_contains($prompt, '{key}') &&
                        str_contains($prompt, '{item_name}')
                    : str_contains($prompt, '{item_name}'));
            $status = $isValidRequest && $hasExpectedPrompt ? '200 OK' : '400 Bad Request';
            $responseBody = $status === '200 OK'
                ? json_encode([
                    'candidates' => [[
                        'content' => ['parts' => [[
                            'text' => $expectedLocale === 'en'
                                ? 'Save {name}'
                                : 'これを削除 {item_name}'
                        ]]]
                    ]]
                ], JSON_UNESCAPED_UNICODE)
                : json_encode(['error' => ['message' => 'mock request validation failed: ' .
                    json_encode([
                        'path' => $isValidRequest,
                        'prompt' => $hasExpectedPrompt,
                        'line' => $requestLine,
                        'expected' => $expectedRequestPrefix
                    ])]]);
        } elseif ($requestIndex >= 2 && $requestIndex <= 4) {
            $status = '503 Service Unavailable';
            $responseBody = json_encode([
                'error' => ['message' => 'Service temporarily unavailable.']
            ]);
        } elseif ($requestIndex === 6) {
            $status = '404 Not Found';
            $responseBody = json_encode([
                'error' => ['message' => 'Model was not found or is not supported for generateContent.']
            ]);
        } else {
            $status = '429 Too Many Requests';
            $responseBody = json_encode([
                'error' => ['message' => 'quota exceeded; test-gemini-key must remain secret']
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
$envPath = tempnam(sys_get_temp_dir(), 'tms-gemini-env-');
if ($envPath === false) {
    fwrite(STDERR, "NOT_RUN: could not create temporary environment file\n");
    exit(2);
}
file_put_contents(
    $envPath,
    "\xEF\xBB\xBF# Gemini test configuration\r\n export GEMINI_API_KEY = \"test-gemini-key\" # test value\r\n"
);
putenv('GEMINI_API_KEY');
putenv('GEMINI_ENV_FILE=' . $envPath);
unset($_ENV['GEMINI_API_KEY'], $_SERVER['GEMINI_API_KEY']);
$translator = new AiTranslator(
    null,
    null,
    'http://127.0.0.1:' . $port
);
unlink($envPath);
putenv('GEMINI_ENV_FILE');

try {
    same(
        $translator->translateText('Save {name}', 'en'),
        'Save {name}',
        'gemini-single-endpoint-translates-and-preserves-placeholders'
    );
    same(
        $translator->translateText('Delete this {item_name}', 'ja'),
        'これを削除 {item_name}',
        'gemini-single-endpoint-uses-target-locale'
    );
    same(
        $translator->translateText('Save {name}', 'en'),
        'Save {name}',
        'gemini-overload-503-falls-back-to-another-flash-model'
    );
    same(
        $translator->translateText('Save {name}', 'en'),
        'Save {name}',
        'gemini-unknown-model-404-falls-back-without-retrying-same-model'
    );
} catch (Throwable $e) {
    $failed++;
    echo 'FAIL gemini-rest-request: ' . $e->getMessage() . "\n";
}

try {
    $translator->translateText('Any text', 'en');
    same(false, true, 'gemini-provider-errors-are-detailed');
} catch (RuntimeException $e) {
    same(
        str_contains($e->getMessage(), 'HTTP 429 rate limit or account quota exceeded') &&
            str_contains($e->getMessage(), 'quota exceeded') &&
            !str_contains($e->getMessage(), 'test-gemini-key'),
        true,
        'gemini-single-endpoint-reports-provider-errors-with-key-redacted'
    );
}

same(
    $translator->chat([['role' => 'user', 'text' => 'How do I review translations?']], 'en'),
    'Review the draft and approve it.',
    'gemini-chat-sends-tms-context-and-returns-assistant-response'
);
try {
    $translator->chat([['role' => 'assistant', 'text' => 'Unexpected start']], 'en');
    same(false, true, 'gemini-chat-rejects-invalid-history');
} catch (InvalidArgumentException $exception) {
    same($exception->getMessage(), 'AI_CHAT_INVALID_HISTORY', 'gemini-chat-rejects-invalid-history');
}

try {
    (new AiTranslator(null, ''))->translateText('Save', 'en');
    same(false, true, 'gemini-missing-key-is-reported');
} catch (RuntimeException $e) {
    same(
        str_starts_with($e->getMessage(), 'GEMINI_API_KEY_MISSING'),
        true,
        'gemini-missing-key-is-reported'
    );
}

try {
    $translator->translateText('Save', 'not_a_locale');
    same(false, true, 'unsupported-locale-is-rejected');
} catch (InvalidArgumentException $e) {
    same($e->getMessage(), 'AI_TRANSLATION_UNSUPPORTED_LOCALE', 'unsupported-locale-is-rejected');
}

same(AiTranslator::resolveTargetLanguage('zh-TW'), ['zh-TW', 'Chinese (Traditional)'], 'traditional-chinese-locale-is-resolved');
same(AiTranslator::resolveTargetLanguage('Japanese'), ['ja', 'Japanese'], 'language-name-is-resolved');

pcntl_waitpid($pid, $status);
same(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, true, 'gemini-mock-server-completed');

finish();
