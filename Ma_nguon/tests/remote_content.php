<?php
declare(strict_types=1);

require __DIR__ . '/helper.php';
require __DIR__ . '/../src/RemoteContentService.php';

$remoteHttpException = new RemoteHttpException(503, '<h1>Unavailable</h1><p>Try again soon.</p>');
same($remoteHttpException->getMessage(), 'REMOTE_HTTP_STATUS_503', 'sync-http-error-preserves-status-code');
same($remoteHttpException->responseBody, '<h1>Unavailable</h1><p>Try again soon.</p>', 'sync-http-error-preserves-response-body');
$longRemoteHttpException = new RemoteHttpException(500, str_repeat('x', 1000));
same(strlen($longRemoteHttpException->responseBody), 1000, 'sync-http-error-response-body-is-forwarded-completely');
same($longRemoteHttpException->contentType, '', 'sync-http-error-content-type-is-available');
$oversizedRemoteHttpException = new RemoteHttpException(500, str_repeat('x', 2097153));
same(strlen($oversizedRemoteHttpException->responseBody), 2097152, 'sync-http-error-response-body-is-bounded');
same($oversizedRemoteHttpException->responseBodyTruncated, true, 'sync-http-error-reports-truncated-response-body');

same(
    RemoteContentService::syncMethodForEndpoint('https://api.npoint.io/93819dd3680918a3d9ea', 'POST'),
    'PUT',
    'sync-npoint-endpoint-always-uses-put'
);
same(
    RemoteContentService::syncMethodForEndpoint('https://example.com/api/translations', 'POST'),
    'POST',
    'sync-other-endpoints-keep-selected-method'
);
same(
    RemoteContentService::endpointForHistory('https://example.com/api/translations?token=secret&tenant=42#sync'),
    'https://example.com/api/translations',
    'sync-history-endpoint-omits-query-and-fragment-secrets'
);
same(
    json_decode(
        RemoteContentService::wrapNpointTranslations('{"menu":{"play":"Bắt đầu"}}'),
        true,
        512,
        JSON_THROW_ON_ERROR
    ),
    ['translations' => ['menu' => ['play' => 'Bắt đầu']]],
    'sync-npoint-payload-is-wrapped-in-root-object'
);
same(
    RemoteContentService::wrapNpointTranslations('{"translations":{"menu":{"play":"Bắt đầu"}}}'),
    '{"translations":{"menu":{"play":"Bắt đầu"}}}',
    'sync-npoint-wrapper-is-idempotent-for-rollback-payloads'
);
same(
    str_starts_with(
        RemoteContentService::wrapNpointTranslations('[{"key":"hello","value":"Xin chào"}]'),
        '{"translations":'
    ),
    true,
    'sync-npoint-list-payload-has-object-root'
);

try {
    RemoteContentService::wrapNpointTranslations('[]');
    same(false, true, 'sync-npoint-rejects-empty-payload');
} catch (InvalidArgumentException $exception) {
    same($exception->getMessage(), 'REMOTE_JSON_BODY_INVALID', 'sync-npoint-rejects-empty-payload');
}

RemoteContentService::validateJsonResponse('application/json; charset=utf-8', '{"success":true}');
RemoteContentService::validateJsonResponse('application/vnd.example+json', '{"success":true}');
same(true, true, 'sync-accepts-json-content-types-and-valid-json');

try {
    RemoteContentService::validateJsonResponse('text/html; charset=utf-8', '<html>Not JSON</html>');
    same(false, true, 'sync-rejects-non-json-content-type');
} catch (RemoteResponseFormatException $exception) {
    same($exception->getMessage(), 'REMOTE_RESPONSE_NOT_JSON', 'sync-rejects-non-json-content-type');
    same($exception->contentType, 'text/html; charset=utf-8', 'sync-non-json-error-exposes-content-type');
    same($exception->responseBody, '<html>Not JSON</html>', 'sync-non-json-error-preserves-response-body');
}

try {
    RemoteContentService::validateJsonResponse('application/json', '<html>Not JSON</html>');
    same(false, true, 'sync-rejects-invalid-json-body');
} catch (RemoteResponseFormatException $exception) {
    same($exception->getMessage(), 'REMOTE_RESPONSE_INVALID_JSON', 'sync-rejects-invalid-json-body');
}

RemoteContentService::validateJsonResponse('', '', 204);
same(true, true, 'sync-allows-empty-204-response');

try {
    RemoteContentService::validateJsonResponse('', '', 200);
    same(false, true, 'sync-rejects-empty-non-204-response');
} catch (RemoteResponseFormatException $exception) {
    same($exception->getMessage(), 'REMOTE_RESPONSE_EMPTY', 'sync-rejects-empty-non-204-response');
}

$jsonData = RemoteContentService::parseJson('{"menu":{"play":"Play"},"footer":"About"}');
same(
    JsonParser::flatten($jsonData),
    ['menu.play' => 'Play', 'footer' => 'About'],
    'ingestion-json-flattens-translatable-strings'
);

$htmlData = RemoteContentService::parseHtml(
    '<html><body><h1>Welcome &amp; enjoy</h1><p>Start <strong>now</strong>.</p>' .
    '<script>ignore this</script><style>.hidden{display:none}</style></body></html>'
);
same(
    array_values($htmlData['page']),
    ['Welcome & enjoy', 'Start now.'],
    'ingestion-html-extracts-visible-text-and-ignores-script-and-style'
);

$broadHtmlData = RemoteContentService::parseHtml(
    '<!doctype html><html><body>' .
    '<head><title>Page title is not visible</title><meta name="description" content="metadata"></head>' .
    '<header><a>Site navigation text</a></header>' .
    '<main><h2>Translated heading</h2><p>Read <strong>the full</strong> story.</p>' .
    '<div><span>Visible span copy</span><a>Open article</a></div>' .
    '<ul><li>Useful list item</li><li>A</li></ul><p>Useful list item</p>' .
    '<label>Account label</label><figure><figcaption>Photo caption</figcaption></figure>' .
    '<p>Yes</p><p>Line one' . "\n\t" . 'and line two</p><p hidden>Hidden text is ignored</p>' .
    '<p style="display:none">Invisible text is ignored</p>' .
    '<script>javascript source should not appear</script>' .
    '<style>.hidden { display: none; }</style></main></body></html>'
);
same(
    array_values($broadHtmlData['page']),
    [
        'Site navigation text',
        'Translated heading',
        'Read the full story.',
        'Visible span copyOpen article',
        'Useful list item',
        'A',
        'Account label',
        'Photo caption',
        'Yes',
        'Line one and line two',
    ],
    'ingestion-html-recursively-extracts-all-text-and-skips-only-system-or-hidden-content'
);

$interactiveHtmlData = RemoteContentService::parseHtml(
    '<html><body><main>' .
    '<button>Save <span>draft</span></button>' .
    '<input type="button" value="Click here">' .
    '<input type="submit" value="Send message">' .
    '<a class="btn btn-primary">Contact support</a>' .
    '<div class="button secondary">More actions</div>' .
    '<span class="icon-button">Close panel</span>' .
    '<p>Outside content stays visible</p>' .
    '</main></body></html>'
);
same(
    array_values($interactiveHtmlData['page']),
    [
        'Save draft',
        'Click here',
        'Send message',
        'Contact support',
        'More actions',
        'Close panel',
        'Outside content stays visible',
    ],
    'ingestion-html-extracts-button-and-interactive-element-labels'
);

try {
    RemoteContentService::parseJson('not-json');
    same(false, true, 'ingestion-invalid-json-is-rejected');
} catch (InvalidArgumentException $exception) {
    same($exception->getMessage(), 'INGESTION_JSON_INVALID', 'ingestion-invalid-json-is-rejected');
}

try {
    RemoteContentService::parseJson('{"empty":[]}');
    same(false, true, 'ingestion-empty-json-source-is-rejected');
} catch (InvalidArgumentException $exception) {
    same($exception->getMessage(), 'INGESTION_JSON_OBJECT_REQUIRED', 'ingestion-empty-json-source-is-rejected');
}

try {
    (new RemoteContentService())->fetch('http://127.0.0.1/internal');
    same(false, true, 'ingestion-rejects-private-network-host');
} catch (InvalidArgumentException $exception) {
    same($exception->getMessage(), 'REMOTE_HOST_NOT_PUBLIC', 'ingestion-rejects-private-network-host');
}

try {
    (new RemoteContentService())->fetch('http://8.8.8.8/api', 'POST', '{}');
    same(false, true, 'sync-requires-https');
} catch (InvalidArgumentException $exception) {
    same($exception->getMessage(), 'REMOTE_HTTPS_REQUIRED', 'sync-requires-https');
}

try {
    (new RemoteContentService())->fetch('https://8.8.8.8/api', 'POST', '{}', "token\r\nInjected: true");
    same(false, true, 'sync-rejects-invalid-bearer-token');
} catch (InvalidArgumentException $exception) {
    same($exception->getMessage(), 'REMOTE_TOKEN_INVALID', 'sync-rejects-invalid-bearer-token');
}

finish();
