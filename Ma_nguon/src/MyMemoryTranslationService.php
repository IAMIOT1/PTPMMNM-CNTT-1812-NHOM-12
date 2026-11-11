<?php
declare(strict_types=1);

final class MyMemoryTranslationService
{
    private const REQUEST_DELAY_US = 1000000;
    private const MAX_RATE_LIMIT_RETRIES = 3;
    private const MAX_TRANSIENT_RETRIES = 2;
    private static float $lastRequestAt = 0.0;

    public function translate(string $sourceText, string $sourceLocale, string $targetLocale): string
    {
        $sourceText = trim($sourceText);
        $sourceLocale = strtolower(trim($sourceLocale));
        $targetLocale = strtolower(trim($targetLocale));

        if ($sourceText === '' || strlen($sourceText) > 50000) {
            throw new InvalidArgumentException('MYMEMORY_TEXT_INVALID');
        }
        if (!preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/i', $sourceLocale) ||
            !preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/i', $targetLocale)) {
            throw new InvalidArgumentException('MYMEMORY_LOCALE_INVALID');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('MYMEMORY_CURL_MISSING');
        }

        $email = trim((string)(getenv('MYMEMORY_EMAIL') ?: ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('MYMEMORY_EMAIL_INVALID');
        }
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
        $decoded = $responseDecoded ?? [];
        $responseStatus = (int)($decoded['responseStatus'] ?? 0);
        $translatedText = trim((string)($decoded['responseData']['translatedText'] ?? ''));
        if ($responseStatus !== 200 || $translatedText === '') {
            $message = (string)($decoded['responseDetails'] ?? 'empty translation response');
            throw new RuntimeException(
                'MYMEMORY_API_ERROR: ' . $this->sanitize($message),
                $responseStatus > 0 ? $responseStatus : 502
            );
        }
        return $translatedText;
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
