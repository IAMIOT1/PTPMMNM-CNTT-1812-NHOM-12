<?php
declare(strict_types=1);

require_once __DIR__ . '/VariableValidator.php';

class AiTranslator {
    private $conn;
    private string $apiKey;
    private string $apiUrl;

    public function __construct(
        $conn,
        ?string $apiKey = null,
        string $apiUrl = ''
    ) {
        $this->conn = $conn;
        $this->apiKey = $apiKey ?? self::loadApiKey();
        $this->apiUrl = rtrim(
            $apiUrl !== '' ? $apiUrl : (getenv('GEMINI_API_URL') ?: 'https://generativelanguage.googleapis.com'),
            '/'
        );
    }

    public function autoTranslateAll(int $versionId, string $targetLocale, int $userId): int {
        if ($versionId < 1 || $userId < 1) {
            throw new InvalidArgumentException('AI_TRANSLATION_INVALID_CONTEXT');
        }
        [$targetLocale] = self::resolveTargetLanguage($targetLocale);

        $rows = [];
        $result = queryParams(
            $this->conn,
            "SELECT id AS string_id, source_text
             FROM strings
             WHERE source_version_id = $1
             ORDER BY id",
            [$versionId]
        );
        while ($row = pg_fetch_assoc($result)) {
            $sourceText = (string)$row['source_text'];
            $rows[] = [
                'string_id' => (int)$row['string_id'],
                'translated_text' => $this->translateText($sourceText, $targetLocale)
            ];
        }

        if ($rows === []) {
            return 0;
        }

        if (pg_query($this->conn, 'BEGIN') === false) {
            throw new RuntimeException('AI_TRANSLATION_TRANSACTION_FAILED');
        }

        $translatedCount = 0;
        try {
            queryParams($this->conn, "SELECT set_config('tms.user_id', $1, TRUE)", [(string)$userId]);
            foreach ($rows as $row) {
                $upsertResult = queryParams(
                    $this->conn,
                    "INSERT INTO translations (string_id, locale, translated_text, status, user_id)
                     VALUES ($1, $2, $3, 'draft', $4)
                     ON CONFLICT (string_id, locale) DO UPDATE
                     SET translated_text = EXCLUDED.translated_text,
                         status = 'draft',
                         updated_at = CURRENT_TIMESTAMP,
                         user_id = EXCLUDED.user_id
                     RETURNING id",
                    [$row['string_id'], $targetLocale, $row['translated_text'], $userId]
                );
                $translatedCount += pg_num_rows($upsertResult);
            }

            if (pg_query($this->conn, 'COMMIT') === false) {
                throw new RuntimeException('AI_TRANSLATION_COMMIT_FAILED');
            }
        } catch (Throwable $e) {
            if (pg_transaction_status($this->conn) !== PGSQL_TRANSACTION_IDLE) {
                pg_query($this->conn, 'ROLLBACK');
            }
            throw $e;
        }

        return $translatedCount;
    }

    public function translateText(string $text, string $targetLocale): string {
        [$locale, $language] = self::resolveTargetLanguage($targetLocale);
        if (trim($text) === '') {
            return '';
        }
        if ($this->apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY_MISSING: Set GEMINI_API_KEY in the project .env file or process environment.');
        }
        if (!filter_var($this->apiUrl, FILTER_VALIDATE_URL) ||
            !in_array(parse_url($this->apiUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new RuntimeException('GEMINI_API_URL_INVALID');
        }

        $prompt = 'Translate the source text into the language identified by BCP 47 locale "' .
            $locale . '" (' . $language . '). Return only the complete translated text, without a prefix, ' .
            'explanation, markdown, or quotation marks. Preserve every source placeholder exactly, ' .
            'including every brace-delimited variable such as {name}, {count}, {key}, and {item_name}; ' .
            'do not translate, remove, duplicate, or alter any placeholder.';
        $payload = json_encode([
            'systemInstruction' => [
                'parts' => [['text' => $prompt]]
            ],
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $text]]]
            ],
            'generationConfig' => [
                'temperature' => 0
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if (!function_exists('curl_init')) {
            throw new RuntimeException('GEMINI_CURL_EXTENSION_MISSING: Enable the PHP cURL extension.');
        }

        $url = $this->apiUrl . '/v1beta/models/gemini-3.8-flash:generateContent?key=' . rawurlencode($this->apiKey);
        $curlHandle = curl_init($url);
        if ($curlHandle === false) {
            throw new RuntimeException('GEMINI_CURL_INIT_FAILED');
        }

        try {
            $configured = curl_setopt_array($curlHandle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json'
                ],
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
            ]);
            if (!$configured) {
                throw new RuntimeException('GEMINI_CURL_CONFIGURATION_FAILED');
            }

            $response = curl_exec($curlHandle);
            $statusCode = (int)curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
            if ($response === false) {
                $curlError = curl_error($curlHandle);
                $curlErrorCode = curl_errno($curlHandle);
                throw new RuntimeException(
                    'GEMINI_CURL_REQUEST_FAILED: cURL error #' . $curlErrorCode . ': ' .
                    $this->sanitizeDiagnostic($curlError) . '; HTTP status ' . $statusCode . '.'
                );
            }
        } finally {
            curl_close($curlHandle);
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $providerMessage = $this->extractProviderError($response);
            $details = 'HTTP ' . $statusCode . ' ' . $this->httpStatusHint($statusCode) . '.';
            if ($providerMessage !== '') {
                $details .= ' Provider: ' . $providerMessage;
            }
            throw new RuntimeException('GEMINI_API_HTTP_ERROR: ' . $details);
        }

        try {
            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('GEMINI_API_RESPONSE_INVALID: API response was not valid JSON.', 0, $e);
        }

        $parts = $decoded['candidates'][0]['content']['parts'] ?? [];
        $translatedText = '';
        foreach ($parts as $part) {
            if (isset($part['text']) && is_string($part['text'])) {
                $translatedText .= $part['text'];
            }
        }
        $translatedText = trim($translatedText);
        if ($translatedText === '') {
            $finishReason = $decoded['candidates'][0]['finishReason'] ?? '';
            $reason = is_string($finishReason) && $finishReason !== ''
                ? ' Finish reason: ' . $this->sanitizeDiagnostic($finishReason) . '.'
                : '';
            throw new RuntimeException('GEMINI_API_TRANSLATION_MISSING: response did not include translated text.' . $reason);
        }
        if (!VariableValidator::validate($text, $translatedText)) {
            throw new RuntimeException('AI_TRANSLATION_PLACEHOLDER_MISMATCH: Gemini changed source placeholders.');
        }

        return $translatedText;
    }

    public static function resolveTargetLanguage(string $target): array {
        $target = trim($target);
        $languages = [
            'vi' => 'Vietnamese',
            'en' => 'English',
            'ja' => 'Japanese',
            'zh' => 'Chinese (Simplified)',
            'ko' => 'Korean',
            'fr' => 'French',
            'de' => 'German',
            'es' => 'Spanish',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'ru' => 'Russian',
            'ar' => 'Arabic',
            'hi' => 'Hindi',
            'th' => 'Thai',
            'id' => 'Indonesian',
            'ms' => 'Malay',
            'nl' => 'Dutch',
            'sv' => 'Swedish',
            'pl' => 'Polish',
            'tr' => 'Turkish',
            'uk' => 'Ukrainian',
            'cs' => 'Czech',
            'da' => 'Danish',
            'fi' => 'Finnish',
            'no' => 'Norwegian',
            'he' => 'Hebrew',
            'el' => 'Greek',
            'ro' => 'Romanian',
            'hu' => 'Hungarian'
        ];
        $aliases = [
            'vietnamese' => 'vi',
            'tiếng việt' => 'vi',
            'english' => 'en',
            'japanese' => 'ja',
            '日本語' => 'ja',
            'chinese' => 'zh',
            'chinese (simplified)' => 'zh',
            'simplified chinese' => 'zh',
            '中文' => 'zh',
            'chinese (traditional)' => 'zh-TW',
            'traditional chinese' => 'zh-TW',
            '한국어' => 'ko',
            'korean' => 'ko',
            'french' => 'fr',
            'german' => 'de',
            'spanish' => 'es',
            'italian' => 'it',
            'portuguese' => 'pt',
            'russian' => 'ru',
            'arabic' => 'ar',
            'hindi' => 'hi',
            'thai' => 'th',
            'indonesian' => 'id',
            'malay' => 'ms',
            'dutch' => 'nl',
            'swedish' => 'sv',
            'polish' => 'pl',
            'turkish' => 'tr',
            'ukrainian' => 'uk',
            'czech' => 'cs',
            'danish' => 'da',
            'finnish' => 'fi',
            'norwegian' => 'no',
            'hebrew' => 'he',
            'greek' => 'el',
            'romanian' => 'ro',
            'hungarian' => 'hu'
        ];

        $languageKey = strtolower($target);
        if (isset($aliases[$languageKey])) {
            $code = $aliases[$languageKey];
            $primaryCode = strtolower(explode('-', $code)[0]);
            $language = $primaryCode === 'zh' && $code === 'zh-TW'
                ? 'Chinese (Traditional)'
                : $languages[$primaryCode];
            return [$code, $language];
        }

        if (!preg_match('/^[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8})*$/', $target)) {
            throw new InvalidArgumentException('AI_TRANSLATION_UNSUPPORTED_LOCALE');
        }

        $parts = explode('-', $target);
        $code = strtolower(array_shift($parts));
        foreach ($parts as &$part) {
            $part = strlen($part) === 2 ? strtoupper($part) : $part;
        }
        unset($part);
        $code = implode('-', [$code, ...$parts]);
        $language = $languages[strtolower(explode('-', $code)[0])] ?? null;
        if ($language === null) {
            $language = 'the language identified by BCP 47 tag "' . $code . '"';
        } elseif (
            str_starts_with($code, 'zh-TW') ||
            str_starts_with($code, 'zh-HK') ||
            str_starts_with($code, 'zh-MO') ||
            str_starts_with($code, 'zh-Hant')
        ) {
            $language = 'Chinese (Traditional)';
        }

        return [$code, $language];
    }

    private static function loadApiKey(): string {
        $environmentValue = getenv('GEMINI_API_KEY');
        if (is_string($environmentValue) && trim($environmentValue) !== '') {
            return trim($environmentValue);
        }
        foreach ([$_ENV, $_SERVER] as $environment) {
            if (isset($environment['GEMINI_API_KEY']) &&
                is_string($environment['GEMINI_API_KEY']) &&
                trim($environment['GEMINI_API_KEY']) !== '') {
                return trim($environment['GEMINI_API_KEY']);
            }
        }

        $envFiles = [];
        $configuredEnvFile = getenv('GEMINI_ENV_FILE');
        if (is_string($configuredEnvFile) && trim($configuredEnvFile) !== '') {
            $envFiles[] = trim($configuredEnvFile);
        }
        $envFiles[] = dirname(__DIR__) . '/.env';
        $envFiles[] = dirname(dirname(__DIR__)) . '/.env';
        $workingDirectory = getcwd();
        if (is_string($workingDirectory) && $workingDirectory !== '') {
            $envFiles[] = $workingDirectory . '/.env';
        }
        $envFiles = array_unique($envFiles);

        foreach ($envFiles as $envFile) {
            if (!is_file($envFile)) {
                continue;
            }
            if (!is_readable($envFile)) {
                throw new RuntimeException('GEMINI_ENV_FILE_UNREADABLE');
            }

            $lines = file($envFile, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                throw new RuntimeException('GEMINI_ENV_FILE_UNREADABLE');
            }
            foreach ($lines as $line) {
                $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
                if (!preg_match('/^\s*(?:export\s+)?GEMINI_API_KEY\s*=\s*(.*?)\s*$/', $line, $matches)) {
                    continue;
                }

                $value = trim($matches[1]);
                if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                    $quote = $value[0];
                    $closingQuote = strrpos($value, $quote);
                    $trailingText = $closingQuote === false
                        ? ''
                        : trim(substr($value, $closingQuote + 1));
                    if ($closingQuote === false ||
                        ($closingQuote === 0) ||
                        ($trailingText !== '' && !str_starts_with($trailingText, '#'))) {
                        throw new RuntimeException('GEMINI_ENV_FILE_INVALID');
                    }
                    $wasDoubleQuoted = $quote === '"';
                    $value = substr($value, 1, $closingQuote - 1);
                    if ($wasDoubleQuoted) {
                        $value = stripcslashes($value);
                    }
                } else {
                    $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
                }
                return trim($value);
            }
        }

        return '';
    }

    private function extractProviderError(string $response): string {
        try {
            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return '';
        }
        $message = $decoded['error']['message'] ?? '';
        return is_string($message) ? $this->sanitizeDiagnostic($message) : '';
    }

    private function httpStatusHint(int $statusCode): string {
        return match ($statusCode) {
            400 => 'request was rejected; check model and request parameters',
            401, 403 => 'API key is invalid or lacks permission',
            404 => 'endpoint or model was not found',
            429 => 'rate limit or account quota exceeded',
            408, 504 => 'API request timed out',
            default => $statusCode >= 500 ? 'Google AI provider server error' : 'request rejected'
        };
    }

    private function sanitizeDiagnostic(string $message): string {
        $message = str_replace(["\r", "\n", "\0"], ' ', $message);
        if ($this->apiKey !== '') {
            $message = str_replace($this->apiKey, '[redacted]', $message);
        }
        $message = preg_replace('/([?&]key=)[^&\s]+/i', '$1[redacted]', $message) ?? $message;
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);
        if (strlen($message) > 240) {
            $message = substr($message, 0, 237) . '...';
        }
        return $message;
    }
}
