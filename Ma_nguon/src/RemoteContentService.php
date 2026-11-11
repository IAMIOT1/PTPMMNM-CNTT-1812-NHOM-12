<?php
declare(strict_types=1);

require_once __DIR__ . '/JsonParser.php';

abstract class RemoteResponseException extends RuntimeException
{
    private const MAX_RESPONSE_BODY_BYTES = 2097152;

    public readonly int $status;
    public readonly string $responseBody;
    public readonly bool $responseBodyTruncated;
    public readonly string $contentType;

    protected function __construct(string $reason, int $status, string $contentType, string $responseBody)
    {
        $this->contentType = trim($contentType);
        $this->status = $status;
        $this->responseBodyTruncated = strlen($responseBody) > self::MAX_RESPONSE_BODY_BYTES;
        $this->responseBody = substr($responseBody, 0, self::MAX_RESPONSE_BODY_BYTES);
        parent::__construct($reason);
    }
}

final class RemoteHttpException extends RemoteResponseException
{
    public function __construct(int $status, string $responseBody, string $contentType = '')
    {
        parent::__construct('REMOTE_HTTP_STATUS_' . $status, $status, $contentType, $responseBody);
    }
}

final class RemoteResponseFormatException extends RemoteResponseException
{
    public function __construct(string $reason, int $status, string $contentType, string $responseBody)
    {
        parent::__construct($reason, $status, $contentType, $responseBody);
    }
}

final class RemoteContentService
{
    private const MAX_RESPONSE_BYTES = 2097152;

    public function fetch(
        string $url,
        string $method = 'GET',
        ?string $jsonBody = null,
        ?string $bearerToken = null,
        ?int &$responseStatus = null,
        ?string &$responseContentType = null,
        bool $expectJsonResponse = false
    ): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('CURL_EXTENSION_MISSING');
        }

        [$parts, $address] = self::validatePublicUrl($url);
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH'], true)) {
            throw new InvalidArgumentException('REMOTE_METHOD_INVALID');
        }
        $host = strtolower(rtrim((string)$parts['host'], '.'));
        if ($jsonBody !== null && $host === 'api.npoint.io') {
            $method = 'PUT';
        }
        if ($jsonBody !== null) {
            try {
                json_decode($jsonBody, false, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidArgumentException('REMOTE_JSON_BODY_INVALID', 0, $exception);
            }
            if ($host === 'api.npoint.io') {
                $jsonBody = self::wrapNpointTranslations($jsonBody);
                error_log(sprintf(
                    'TMS npoint payload prepared: bytes=%d sha256=%s',
                    strlen($jsonBody),
                    hash('sha256', $jsonBody)
                ));
                try {
                    self::writeNpointDebugFile('npoint_debug.json', $jsonBody);
                } catch (Throwable $exception) {
                    error_log('TMS npoint payload debug logging failed: ' . $exception->getMessage());
                }
            }
        }
        if ($method !== 'GET' && $parts['scheme'] !== 'https') {
            throw new InvalidArgumentException('REMOTE_HTTPS_REQUIRED');
        }
        if ($bearerToken !== null &&
            $bearerToken !== '' &&
            !preg_match('/^[A-Za-z0-9\-._~+\/]+=*$/D', $bearerToken)) {
            throw new InvalidArgumentException('REMOTE_TOKEN_INVALID');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('REMOTE_REQUEST_INIT_FAILED');
        }

        $headers = [
            'Accept: ' . ($jsonBody !== null || $expectJsonResponse
                ? 'application/json'
                : 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'),
            'Accept-Language: vi-VN,vi;q=0.9,en-US;q=0.8,en;q=0.7',
            'Cache-Control: no-cache',
        ];
        if ($jsonBody !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($bearerToken !== null && $bearerToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $bearerToken;
        }

        $port = (int)($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80));
        $insecureLocalTls = getenv('APP_ENV') === 'local';
        try {
            $configured = curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                CURLOPT_FOLLOWLOCATION => $method === 'GET',
                CURLOPT_MAXREDIRS => $method === 'GET' ? 5 : 0,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => !$insecureLocalTls,
                CURLOPT_SSL_VERIFYHOST => $insecureLocalTls ? 0 : 2,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RESOLVE => [$parts['host'] . ':' . $port . ':' . $address],
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_POSTFIELDS => $jsonBody,
            ]);
            if (!$configured) {
                throw new RuntimeException('REMOTE_REQUEST_CONFIGURATION_FAILED');
            }

            $body = curl_exec($handle);
            $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $responseStatus = $status;
            $contentType = (string)(curl_getinfo($handle, CURLINFO_CONTENT_TYPE) ?: '');
            $responseContentType = $contentType;
            $curlErrno = curl_errno($handle);
            $curlError = curl_error($handle);
            if ($body === false || $curlErrno !== CURLE_OK) {
                $detail = sprintf(
                    'REMOTE_REQUEST_FAILED: cURL %d%s; HTTP %d',
                    $curlErrno,
                    $curlError !== '' ? ' - ' . preg_replace('/[\r\n]+/', ' ', $curlError) : '',
                    $status
                );
                throw new RuntimeException($detail, $curlErrno);
            }
        } finally {
            curl_close($handle);
        }
        if ($status < 200 || $status >= 300) {
            if ($host === 'api.npoint.io' && $status >= 400) {
                try {
                    self::writeNpointDebugFile('npoint_error_response.log', $body);
                } catch (Throwable $exception) {
                    error_log('TMS npoint error response logging failed: ' . $exception->getMessage());
                }
            }
            throw new RemoteHttpException($status, $body, $contentType);
        }
        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            throw new RuntimeException('REMOTE_RESPONSE_TOO_LARGE_HTTP_STATUS_' . $status);
        }
        if ($jsonBody !== null) {
            self::validateJsonResponse($contentType, $body, $status);
        }
        if ($method === 'GET' && trim($body) === '') {
            throw new RuntimeException('REMOTE_EMPTY_RESPONSE_HTTP_STATUS_' . $status);
        }
        return $body;
    }

    public static function syncMethodForEndpoint(string $url, string $method): string
    {
        $method = strtoupper($method);
        if (!in_array($method, ['POST', 'PUT'], true)) {
            throw new InvalidArgumentException('REMOTE_METHOD_INVALID');
        }

        $host = strtolower(rtrim((string)(parse_url($url, PHP_URL_HOST) ?: ''), '.'));
        return $host === 'api.npoint.io' ? 'PUT' : $method;
    }

    public static function endpointForHistory(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new InvalidArgumentException('REMOTE_URL_INVALID');
        }

        $host = strtolower(rtrim((string)$parts['host'], '.'));
        $endpoint = strtolower((string)$parts['scheme']) . '://' . $host;
        if (isset($parts['port'])) {
            $endpoint .= ':' . (int)$parts['port'];
        }
        return $endpoint . (string)($parts['path'] ?? '');
    }

    public function fetchJsonSnapshot(
        string $url,
        ?string $bearerToken = null,
        ?int &$responseStatus = null
    ): string {
        $contentType = '';
        $body = $this->fetch($url, 'GET', null, $bearerToken, $responseStatus, $contentType, true);
        self::validateJsonResponse($contentType, $body, $responseStatus ?? 200);
        try {
            $data = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('REMOTE_RESPONSE_INVALID_JSON', 0, $exception);
        }
        if (!is_object($data) && !is_array($data)) {
            throw new InvalidArgumentException('REMOTE_RESPONSE_JSON_OBJECT_REQUIRED');
        }
        return $body;
    }

    public static function prepareSyncPayload(string $url, string $jsonBody): string
    {
        $host = strtolower(rtrim((string)(parse_url($url, PHP_URL_HOST) ?: ''), '.'));
        return $host === 'api.npoint.io'
            ? self::wrapNpointTranslations($jsonBody)
            : $jsonBody;
    }

    public static function currentSyncData(
        PgSql\Connection $connection,
        int $projectId,
        string $endpoint
    ): ?string {
        $activeResult = queryParams(
            $connection,
            "SELECT new_data::TEXT AS data
             FROM release_sync_history
             WHERE project_id = $1 AND type = 'api_sync'
               AND endpoint_url = $2 AND status = 'active' AND new_data IS NOT NULL
             ORDER BY created_at DESC, id DESC
             LIMIT 1",
            [$projectId, $endpoint]
        );
        $activeRow = pg_fetch_assoc($activeResult);
        if ($activeRow) {
            return (string)$activeRow['data'];
        }

        $baselineResult = queryParams(
            $connection,
            "SELECT target_rollback_data::TEXT AS data
             FROM release_sync_history
             WHERE project_id = $1 AND type = 'api_sync'
               AND endpoint_url = $2 AND target_rollback_data IS NOT NULL
             ORDER BY created_at, id
             LIMIT 1",
            [$projectId, $endpoint]
        );
        $baselineRow = pg_fetch_assoc($baselineResult);
        return $baselineRow ? (string)$baselineRow['data'] : null;
    }

    public static function wrapNpointTranslations(string $jsonBody): string
    {
        try {
            $payloadData = json_decode($jsonBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('REMOTE_JSON_BODY_INVALID', 0, $exception);
        }
        if (!is_array($payloadData) || $payloadData === []) {
            throw new InvalidArgumentException('REMOTE_JSON_BODY_INVALID');
        }

        if (array_keys($payloadData) === ['translations'] && is_array($payloadData['translations'])) {
            return json_encode($payloadData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return json_encode(['translations' => $payloadData], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function writeNpointDebugFile(string $filename, string $contents): void
    {
        if (!in_array($filename, ['npoint_debug.json', 'npoint_error_response.log'], true)) {
            throw new InvalidArgumentException('NPOINT_DEBUG_FILENAME_INVALID');
        }

        $storageRoot = dirname(__DIR__, 2) . '/storage';
        $documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $storageParent = realpath(dirname($storageRoot));
        $resolvedStorageRoot = realpath($storageRoot);
        if ($storageParent === false || is_link($storageRoot)) {
            throw new RuntimeException('NPOINT_DEBUG_STORAGE_UNSAFE');
        }
        $storageLocation = $resolvedStorageRoot !== false
            ? $resolvedStorageRoot
            : $storageParent . DIRECTORY_SEPARATOR . basename($storageRoot);
        if (
            $documentRoot !== false &&
            ($storageLocation === $documentRoot ||
                str_starts_with($storageLocation, rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))
        ) {
            throw new RuntimeException('NPOINT_DEBUG_STORAGE_INSIDE_DOCUMENT_ROOT');
        }

        $debugDirectory = $storageRoot . '/sync-back-debug';
        if (!is_dir($debugDirectory) && !mkdir($debugDirectory, 0700, true) && !is_dir($debugDirectory)) {
            throw new RuntimeException('NPOINT_DEBUG_DIRECTORY_CREATE_FAILED');
        }
        if (is_link($debugDirectory) || !is_writable($debugDirectory) || !chmod($debugDirectory, 0700)) {
            throw new RuntimeException('NPOINT_DEBUG_DIRECTORY_UNSAFE');
        }

        $path = $debugDirectory . '/' . $filename;
        if (is_link($path)) {
            throw new RuntimeException('NPOINT_DEBUG_FILE_UNSAFE');
        }
        $written = file_put_contents($path, $contents, LOCK_EX);
        if ($written !== strlen($contents)) {
            throw new RuntimeException('NPOINT_DEBUG_FILE_WRITE_FAILED');
        }
        if (!chmod($path, 0600)) {
            throw new RuntimeException('NPOINT_DEBUG_FILE_PERMISSIONS_FAILED');
        }
    }

    public static function validateJsonResponse(string $contentType, string $body, int $status = 200): void
    {
        if (trim($body) === '') {
            if ($status === 204) {
                return;
            }
            throw new RemoteResponseFormatException('REMOTE_RESPONSE_EMPTY', $status, $contentType, $body);
        }
        if (preg_match('~^application/(?:[a-z0-9!#$&^_.+-]+\+)?json(?:\s*;|$)~i', trim($contentType)) !== 1) {
            throw new RemoteResponseFormatException('REMOTE_RESPONSE_NOT_JSON', $status, $contentType, $body);
        }
        try {
            json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RemoteResponseFormatException('REMOTE_RESPONSE_INVALID_JSON', $status, $contentType, $body);
        }
    }

    public static function parseJson(string $json): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('INGESTION_JSON_INVALID', 0, $exception);
        }
        if (!is_array($data) || $data === []) {
            throw new InvalidArgumentException('INGESTION_JSON_OBJECT_REQUIRED');
        }
        $flat = JsonParser::flatten($data);
        if ($flat === []) {
            throw new InvalidArgumentException('INGESTION_JSON_OBJECT_REQUIRED');
        }
        foreach ($flat as $key => $value) {
            if (trim((string)$key) === '') {
                throw new InvalidArgumentException('INGESTION_JSON_KEY_REQUIRED');
            }
        }
        return $data;
    }

    public static function parseHtml(string $html): array
    {
        if (trim($html) === '') {
            throw new InvalidArgumentException('INGESTION_HTML_EMPTY');
        }
        $blockedKeyword = self::blockedPageKeyword($html);
        if ($blockedKeyword !== null) {
            throw new InvalidArgumentException('INGESTION_HTML_BLOCKED_' . $blockedKeyword);
        }

        if (!class_exists('DOMDocument')) {
            return self::parseHtmlWithoutDom($html);
        }
        $document = new DOMDocument();
        $previousUseErrors = libxml_use_internal_errors(true);
        try {
            $encodedHtml = function_exists('mb_convert_encoding')
                ? mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8')
                : $html;
            $loaded = @$document->loadHTML(
                '<?xml encoding="UTF-8">' . $encodedHtml,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseErrors);
        }
        if (!$loaded) {
            throw new InvalidArgumentException('INGESTION_HTML_INVALID');
        }

        $data = [];
        $seen = [];
        $body = $document->getElementsByTagName('body')->item(0);
        if ($body !== null) {
            foreach (self::extractHtmlTextSegments($body) as $text) {
                self::appendHtmlText($data, $seen, $text);
            }
        }
        if ($data === []) {
            throw new InvalidArgumentException('INGESTION_HTML_NO_TEXT');
        }
        return $data;
    }

    private static function extractHtmlTextSegments(DOMNode $node): array
    {
        if ($node instanceof DOMText) {
            return [$node->nodeValue ?? ''];
        }

        if ($node instanceof DOMElement) {
            $tag = strtolower($node->tagName);
            if (in_array($tag, ['script', 'style', 'noscript', 'head', 'meta', 'link'], true)
                || $node->hasAttribute('hidden')
                || strtolower($node->getAttribute('aria-hidden')) === 'true'
                || preg_match('/(?:^|;)\s*(?:display\s*:\s*none|visibility\s*:\s*hidden)\s*(?:;|$)/i', $node->getAttribute('style'))) {
                return [];
            }
            if ($tag === 'input' && in_array(strtolower($node->getAttribute('type')), ['button', 'submit'], true)) {
                return [$node->getAttribute('value')];
            }
        }

        $segments = [];
        $pendingText = '';
        foreach ($node->childNodes as $child) {
            $childIsBlock = $child instanceof DOMElement && self::isHtmlBlockBoundary($child);
            if ($childIsBlock) {
                if (trim($pendingText) !== '') {
                    $segments[] = $pendingText;
                }
                $pendingText = '';
                foreach (self::extractHtmlTextSegments($child) as $segment) {
                    $segments[] = $segment;
                }
                continue;
            }

            foreach (self::extractHtmlTextSegments($child) as $text) {
                $pendingText .= $text;
            }
        }
        if (trim($pendingText) !== '') {
            $segments[] = $pendingText;
        }
        return $segments;
    }

    private static function isHtmlBlockBoundary(DOMElement $element): bool
    {
        $tag = strtolower($element->tagName);
        if ($tag === 'input' && in_array(strtolower($element->getAttribute('type')), ['button', 'submit'], true)) {
            return true;
        }
        if (in_array($tag, ['a', 'span', 'div'], true)
            && preg_match('/(?:btn|button)/i', $element->getAttribute('class'))) {
            return true;
        }
        return in_array($tag, [
            'address', 'article', 'aside', 'blockquote', 'body', 'caption',
            'dd', 'div', 'dl', 'dt', 'fieldset', 'figcaption', 'figure',
            'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header',
            'br', 'button', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'table',
            'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
        ], true);
    }

    private static function appendHtmlText(array &$data, array &$seen, string $text, bool $decodeEntities = false): void
    {
        if ($decodeEntities) {
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $line = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($line === '') {
            return;
        }
        $key = hash('sha256', $line);
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $data['page']['text_' . str_pad((string)(count($data['page'] ?? []) + 1), 4, '0', STR_PAD_LEFT)] = $line;
    }

    private static function blockedPageKeyword(string $html): ?string
    {
        $sample = strtolower(substr(strip_tags($html), 0, 200000));
        foreach (['cloudflare', 'access denied', 'captcha'] as $keyword) {
            if (str_contains($sample, $keyword)) {
                return strtoupper(str_replace(' ', '_', $keyword));
            }
        }
        return null;
    }

    private static function parseHtmlWithoutDom(string $html): array
    {
        $html = preg_replace(
            '~<(script|style|noscript|head)\b[^>]*>.*?</\1\s*>~is',
            ' ',
            $html
        ) ?? $html;
        $html = preg_replace(
            '~<([a-z][a-z0-9:-]*)\b(?=[^>]*(?:\shidden(?:\s|=|>)|\saria-hidden\s*=\s*["\']?true\b|\sstyle\s*=\s*["\'][^"\']*(?:display\s*:\s*none|visibility\s*:\s*hidden)))[^>]*>.*?</\1\s*>~is',
            ' ',
            $html
        ) ?? $html;
        $html = preg_replace('~<!--.*?-->~s', ' ', $html) ?? $html;
        $html = preg_replace('~<(?:meta|link)\b[^>]*\/?>~i', ' ', $html) ?? $html;
        $html = preg_replace_callback(
            '~<input\b([^>]*)>~i',
            static function (array $match): string {
                $attributes = $match[1];
                if (!preg_match('/\btype\s*=\s*(?:"(button|submit)"|\'(button|submit)\'|(button|submit)(?:\s|$))/i', $attributes)) {
                    return ' ';
                }
                if (!preg_match('/\bvalue\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $attributes, $valueMatch)) {
                    return ' ';
                }
                $value = $valueMatch[1] !== '' ? $valueMatch[1] : ($valueMatch[2] !== '' ? $valueMatch[2] : ($valueMatch[3] ?? ''));
                $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                return '<button>' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</button>';
            },
            $html
        ) ?? $html;
        $html = preg_replace('/[\r\n\t]+/u', ' ', $html) ?? $html;
        $data = [];
        $seen = [];
        $html = preg_replace(
            '~</?(?:address|article|aside|blockquote|body|button|caption|dd|div|dl|dt|fieldset|figcaption|figure|footer|form|h[1-6]|header|hr|li|main|nav|ol|p|pre|section|table|tbody|td|tfoot|th|thead|tr|ul|br)\b[^>]*>~i',
            "\n",
            $html
        ) ?? $html;
        $textNodes = preg_split('/[\r\n]+/u', strip_tags($html)) ?: [];
        foreach ($textNodes as $textNode) {
            self::appendHtmlText($data, $seen, $textNode, true);
        }
        if ($data === []) {
            throw new InvalidArgumentException('INGESTION_HTML_NO_TEXT');
        }
        return $data;
    }

    public static function approvedPackage(PgSql\Connection $connection, int $versionId, string $locale): string
    {
        $result = queryParams(
            $connection,
            "SELECT s.key_path, t.translated_text
             FROM strings s
             JOIN translations t ON t.string_id = s.id
             WHERE s.source_version_id = $1 AND t.locale = $2
               AND t.status = 'approved'
             ORDER BY s.key_path",
            [$versionId, $locale]
        );
        $flat = [];
        while ($row = pg_fetch_assoc($result)) {
            $flat[$row['key_path']] = $row['translated_text'];
        }
        if ($flat === []) {
            throw new RuntimeException('SYNC_NO_APPROVED_TRANSLATIONS');
        }
        return json_encode(
            JsonParser::unflatten($flat),
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Resolve and pin a public IPv4 address so requests cannot reach private hosts
     * or be redirected to an unvalidated destination.
     */
    public static function validatePublicUrl(string $url): array
    {
        if (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('REMOTE_URL_INVALID');
        }
        $parts = parse_url($url);
        if (!is_array($parts) ||
            !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true) ||
            empty($parts['host']) ||
            isset($parts['user']) ||
            isset($parts['pass'])) {
            throw new InvalidArgumentException('REMOTE_URL_INVALID');
        }

        $host = strtolower((string)$parts['host']);
        $resolvedAddresses = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            ? [$host]
            : (gethostbynamel($host) ?: []);
        foreach ($resolvedAddresses as $address) {
            if (filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            )) {
                $parts['host'] = $host;
                $parts['scheme'] = strtolower((string)$parts['scheme']);
                return [$parts, $address];
            }
        }
        throw new InvalidArgumentException('REMOTE_HOST_NOT_PUBLIC');
    }
}
