<?php
require __DIR__ . '/helper.php';

$manifest = json_decode(
    file_get_contents(__DIR__ . '/../manifest.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
same($manifest['name'] ?? null, 'IAMIOT', 'root-pwa-name');
same($manifest['id'] ?? null, '/', 'root-pwa-stable-id');
same($manifest['scope'] ?? null, '/', 'root-pwa-domain-scope');
same($manifest['start_url'] ?? null, '/', 'root-pwa-launches-from-site-root');
same($manifest['display'] ?? null, 'standalone', 'root-pwa-standalone-display');

$iconsUseRootUrls = isset($manifest['icons'])
    && count($manifest['icons']) >= 2
    && array_reduce(
        $manifest['icons'],
        static fn(bool $valid, array $icon): bool => $valid
            && str_starts_with((string)($icon['src'] ?? ''), '/icons/'),
        true
    );
same($iconsUseRootUrls, true, 'root-pwa-icons-use-root-assets');

$phpPages = [
    'index.php',
    'forgot_password.php',
    'reset_password.php',
    'verify_email.php',
    'release_view.php',
    'release_history.php',
    'translation_comments.php',
    'tm_repository.php',
    'resources/views/dang_nhap.php',
    'resources/views/dang_ky.php',
    'resources/views/ho_so_ca_nhan.php',
    'resources/views/giao_dien_dich.php',
    'resources/views/quan_ly_nguoi_dung.php',
];
$allPhpPagesUseSharedPwaHead = array_reduce(
    $phpPages,
    static fn(bool $valid, string $page): bool => $valid
        && str_contains(file_get_contents(__DIR__ . '/../' . $page), 'pwa-head.php'),
    true
);
same($allPhpPagesUseSharedPwaHead, true, 'php-pages-use-shared-pwa-head');
same(
    str_contains(file_get_contents(__DIR__ . '/../templates/header.php'), 'pwa-head.php'),
    true,
    'shared-public-header-loads-pwa-head'
);

$worker = file_get_contents(__DIR__ . '/../sw.js');
$workerSkipsDynamicEndpoints = str_contains($worker, "url.pathname.endsWith('.php')")
    && str_contains($worker, "url.pathname.startsWith('/api/')")
    && str_contains($worker, 'STATIC_DESTINATIONS.has(request.destination)');
same($workerSkipsDynamicEndpoints, true, 'root-worker-caches-static-assets-only');

$dockerfile = file_get_contents(__DIR__ . '/../Dockerfile');
$staticBypassRule = 'RewriteRule ^(icons/|manifest\.json|sw\.js) - [L]';
$firstRewriteRulePosition = strpos($dockerfile, 'RewriteRule');
$staticBypassPosition = strpos($dockerfile, $staticBypassRule);
$dashboardFallbackPosition = strpos($dockerfile, 'RewriteRule ^ index.html [L]');
$existingFileConditionPosition = strpos($dockerfile, 'RewriteCond %{REQUEST_FILENAME} !-f');
$existingDirectoryConditionPosition = strpos($dockerfile, 'RewriteCond %{REQUEST_FILENAME} !-d');
$apacheServesIconsDirectly = str_contains($dockerfile, 'DocumentRoot /var/www/html')
    && $firstRewriteRulePosition !== false
    && $firstRewriteRulePosition === $staticBypassPosition
    && $staticBypassPosition !== false
    && $dashboardFallbackPosition !== false
    && $staticBypassPosition < $dashboardFallbackPosition
    && $existingFileConditionPosition !== false
    && $existingDirectoryConditionPosition !== false
    && $existingFileConditionPosition < $dashboardFallbackPosition
    && $existingDirectoryConditionPosition < $dashboardFallbackPosition
    && str_contains($dockerfile, '<Directory /var/www/html/icons>')
    && str_contains($dockerfile, 'Require all granted');
same($apacheServesIconsDirectly, true, 'apache-serves-pwa-icons-directly');

$dashboardReferencesRootManifest = str_contains(
    file_get_contents(__DIR__ . '/../dashboard-ui/index.html'),
    'href="/manifest.json"'
);
$dashboardLoadsRootPwaScript = str_contains(
    file_get_contents(__DIR__ . '/../dashboard-ui/src/main.tsx'),
    "pwaScript.src = '/pwa.js'"
);
same($dashboardReferencesRootManifest && $dashboardLoadsRootPwaScript, true, 'dashboard-uses-root-pwa-assets');

finish();
