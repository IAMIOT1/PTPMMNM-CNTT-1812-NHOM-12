<?php
declare(strict_types=1);

final class TranslationProviderService
{
    public const MYMEMORY = 'mymemory';
    public const GOOGLE = 'google';
    public const LIBRETRANSLATE = 'libretranslate';

    public function __construct(private readonly MyMemoryTranslationService $myMemory)
    {
    }

    public function translate(string $provider, string $text, string $sourceLocale, string $targetLocale): string
    {
        return match ($provider) {
            self::MYMEMORY => $this->myMemory->translate($text, $sourceLocale, $targetLocale),
            self::GOOGLE => $this->translateGoogle($text, $sourceLocale, $targetLocale),
            self::LIBRETRANSLATE => $this->translateLibre($text, $sourceLocale, $targetLocale),
            default => throw new InvalidArgumentException('TRANSLATOR_PROVIDER_INVALID'),
        };
    }

    private function translateGoogle(string $text, string $sourceLocale, string $targetLocale): string
    {
        $decoded = $this->requestJson(
            'https://translate.googleapis.com/translate_a/single?' . http_build_query([
                'client' => 'gtx',
                'sl' => $sourceLocale,
                'tl' => $targetLocale,
                'dt' => 't',
                'q' => $text,
            ], '', '&', PHP_QUERY_RFC3986),
            'GOOGLE'
        );
        $translated = '';
        foreach (($decoded[0] ?? []) as $segment) {
            $translated .= (string)($segment[0] ?? '');
        }
        if (trim($translated) === '') {
            throw new RuntimeException('GOOGLE_EMPTY_RESPONSE');
        }
        return trim($translated);
    }

    private function translateLibre(string $text, string $sourceLocale, string $targetLocale): string
    {
        $url = rtrim((string)(getenv('TMS_LIBRETRANSLATE_URL') ?: 'https://libretranslate.com'), '/') . '/translate';
        $payload = [
            'q' => $text,
            'source' => $sourceLocale,
            'target' => $targetLocale,
            'format' => 'text',
        ];
        $apiKey = trim((string)(getenv('TMS_LIBRETRANSLATE_API_KEY') ?: ''));
        if ($apiKey !== '') {
            $payload['api_key'] = $apiKey;
        }
        $decoded = $this->requestJson($url, 'LIBRETRANSLATE', $payload);
        $translated = trim((string)($decoded['translatedText'] ?? ''));
        if ($translated === '') {
            throw new RuntimeException('LIBRETRANSLATE_EMPTY_RESPONSE');
        }
        return $translated;
    }

    private function requestJson(string $url, string $provider, ?array $payload = null): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException($provider . '_CURL_MISSING');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException($provider . '_CURL_INIT_FAILED');
        }
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ];
        if ($payload !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }
        curl_setopt_array($handle, $options);
        $response = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($response === false) {
            throw new RuntimeException($provider . '_CURL_FAILED: ' . preg_replace('/[\r\n]+/', ' ', $error));
        }
        if ($status === 429) {
            throw new RuntimeException($provider . '_RATE_LIMITED: HTTP 429', 429);
        }
        if ($status === 402 || $status === 403) {
            throw new RuntimeException($provider . '_QUOTA_EXCEEDED: HTTP ' . $status, $status);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException($provider . '_HTTP_ERROR: HTTP ' . $status, $status);
        }
        try {
            return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException($provider . '_INVALID_JSON', 0, $exception);
        }
    }
}
