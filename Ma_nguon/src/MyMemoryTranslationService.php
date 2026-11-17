<?php
declare(strict_types=1);

final class MyMemoryTranslationService
{
    private const REQUEST_DELAY_US = 1000000;
    private const MAX_RATE_LIMIT_RETRIES = 3;
    private const MAX_TRANSIENT_RETRIES = 2;
    private const LANGUAGE_CODE_ALIASES = [
        'in' => 'id',
        'iw' => 'he',
        'ji' => 'yi',
        'jw' => 'jv',
        'mo' => 'ro',
    ];
    private static float $lastRequestAt = 0.0;

    public static function normalizeLanguageCode(string $locale, bool $allowAuto = false): string
    {
        $locale = strtolower(str_replace('_', '-', trim($locale)));
        if ($locale === '' || $locale === 'all' || $locale === 'auto') {
            if ($allowAuto) {
                return 'auto';
            }
            throw new InvalidArgumentException('MYMEMORY_LOCALE_INVALID');
        }
        if (preg_match('/^([a-z]{2,3})(?:-[a-z0-9]{2,8})*$/D', $locale, $matches) !== 1) {
            throw new InvalidArgumentException('MYMEMORY_LOCALE_INVALID');
        }

        $languageCode = $matches[1];
        return self::LANGUAGE_CODE_ALIASES[$languageCode] ?? $languageCode;
    }

    public function translate(string $sourceText, string $sourceLocale, string $targetLocale): string
    {
        return $this->translateWithMetadata($sourceText, $sourceLocale, $targetLocale)['translated_text'];
    }

    /**
     * @return array{translated_text:string,fallback:bool,source_locale:string,target_locale:string,requested_target_locale:string,fallback_reason:?string}
     */
    public function translateWithMetadata(string $sourceText, string $sourceLocale, string $targetLocale): array
    {
        $sourceText = trim($sourceText);
        $sourceLocale = self::normalizeLanguageCode($sourceLocale, true);
        $targetLocale = self::normalizeLanguageCode($targetLocale);

        if ($sourceText === '' || strlen($sourceText) > 50000) {
            throw new InvalidArgumentException('MYMEMORY_TEXT_INVALID');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('MYMEMORY_CURL_MISSING');
        }

        $email = trim((string)(getenv('MYMEMORY_EMAIL') ?: ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('MYMEMORY_EMAIL_INVALID');
        }
        $decoded = $this->requestTranslation($sourceText, $sourceLocale, $targetLocale, $email);
        $translatedText = trim((string)($decoded['responseData']['translatedText'] ?? ''));
        $responseStatus = (int)($decoded['responseStatus'] ?? 0);
        if ($responseStatus === 200 && $translatedText !== '') {
            return [
                'translated_text' => $translatedText,
                'fallback' => false,
                'source_locale' => $sourceLocale,
                'target_locale' => $targetLocale,
                'requested_target_locale' => $targetLocale,
                'fallback_reason' => null,
            ];
        }

        $details = trim((string)($decoded['responseDetails'] ?? ''));
        $targetUnsupported = preg_match('/INVALID TARGET LANGUAGE/i', $details) === 1
            || ($responseStatus === 200 && $translatedText === '');
        if ($targetUnsupported && $targetLocale !== 'en') {
            $fallbackReason = preg_match('/INVALID TARGET LANGUAGE/i', $details) === 1
                ? 'MyMemory does not support the selected target language.'
                : ($details !== '' ? $this->sanitize($details) : 'No translation was available for the selected language.');
            if ($sourceLocale === 'en') {
                return [
                    'translated_text' => $sourceText,
                    'fallback' => true,
                    'source_locale' => 'en',
                    'target_locale' => 'en',
                    'requested_target_locale' => $targetLocale,
                    'fallback_reason' => $fallbackReason,
                ];
            }
            $fallbackResponse = $this->requestTranslation($sourceText, $sourceLocale, 'en', $email);
            $fallbackText = trim((string)($fallbackResponse['responseData']['translatedText'] ?? ''));
            if ((int)($fallbackResponse['responseStatus'] ?? 0) === 200 && $fallbackText !== '') {
                return [
                    'translated_text' => $fallbackText,
                    'fallback' => true,
                    'source_locale' => $sourceLocale,
                    'target_locale' => 'en',
                    'requested_target_locale' => $targetLocale,
                    'fallback_reason' => $fallbackReason,
                ];
            }

            $fallbackDetails = trim((string)($fallbackResponse['responseDetails'] ?? ''));
            if ($fallbackDetails === '') {
                $fallbackDetails = 'empty translation response';
            }
            throw new RuntimeException(
                'MYMEMORY_UNSUPPORTED_TARGET_LANGUAGE: ' . $targetLocale .
                '; English fallback failed: ' . $this->sanitize($fallbackDetails),
                422
            );
        }

        $message = $details !== '' ? $details : 'empty translation response';
        throw new RuntimeException(
            'MYMEMORY_API_ERROR: ' . $this->sanitize($message),
            $responseStatus > 0 ? $responseStatus : 502
        );
    }

    private function requestTranslation(
        string $sourceText,
        string $sourceLocale,
        string $targetLocale,
        string $email
    ): array {
        $url = 'https://api.mymemory.translated.net/get?' . http_build_query([
            'q' => $sourceText,
            'langpair' => $sourceLocale . '|' . $targetLocale,
            ...($email !== '' ? ['de' => $email] : []),
        ], '', '&', PHP_QUERY_RFC3986);
        $responseDecoded = null;
        for ($attempt = 0; $attempt <= self::MAX_RATE_LIMIT_RETRIES; $attempt++) {
            $this->waitForRequestSlot();
            $handle = curl_init($url);
            if ($handle === false) {
                throw new RuntimeException('MYMEMORY_CURL_INIT_FAILED');
            }

            try {
                if (!curl_setopt_array($handle, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0.0.0 Safari/537.36',
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_HTTPHEADER => ['Accept: application/json'],
                ])) {
                    throw new RuntimeException('MYMEMORY_CURL_CONFIG_FAILED');
                }
                $response = curl_exec($handle);
                $statusCode = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
                if ($response === false) {
                    $curlMessage = $this->sanitize(curl_error($handle));
                    if ($attempt < self::MAX_TRANSIENT_RETRIES) {
                        usleep(self::REQUEST_DELAY_US * (2 ** $attempt));
                        $statusCode = 599;
                        $response = '';
                    } else {
                        throw new RuntimeException('MYMEMORY_CURL_FAILED: ' . $curlMessage);
                    }
                }
            } finally {
                curl_close($handle);
            }
            self::$lastRequestAt = microtime(true);

            if ($statusCode === 599) {
                continue;
            }
            if ($statusCode === 429) {
                if ($attempt < self::MAX_RATE_LIMIT_RETRIES) {
                    usleep(self::REQUEST_DELAY_US * (2 ** $attempt));
                    continue;
                }
                throw new RuntimeException(
                    'MYMEMORY_RATE_LIMITED: HTTP 429; retry after a short delay.',
                    429
                );
            }
            if ($statusCode >= 500 && $attempt < self::MAX_TRANSIENT_RETRIES) {
                usleep(self::REQUEST_DELAY_US * (2 ** $attempt));
                continue;
            }
            if ($statusCode < 200 || $statusCode >= 300) {
                throw new RuntimeException('MYMEMORY_HTTP_ERROR: HTTP ' . $statusCode, $statusCode);
            }
            try {
                $responseDecoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('MYMEMORY_INVALID_JSON', 0, $exception);
            }
            if ((int)($responseDecoded['responseStatus'] ?? 0) === 429) {
                if ($attempt < self::MAX_RATE_LIMIT_RETRIES) {
                    sleep(2 ** ($attempt + 1));
                    continue;
                }
                throw new RuntimeException('MYMEMORY_RATE_LIMITED: API response status 429.', 429);
            }
            break;
        }
        if (!is_array($responseDecoded)) {
            throw new RuntimeException('MYMEMORY_INVALID_RESPONSE');
        }
        return $responseDecoded;
    }

    private function waitForRequestSlot(): void
    {
        $elapsed = microtime(true) - self::$lastRequestAt;
        $remaining = self::REQUEST_DELAY_US / 1000000 - $elapsed;
        if ($remaining > 0) {
            usleep((int)ceil($remaining * 1000000));
        }
    }

    private function sanitize(string $message): string
    {
        return preg_replace('/[\r\n]+/', ' ', trim($message)) ?: 'unknown cURL error';
    }
}
