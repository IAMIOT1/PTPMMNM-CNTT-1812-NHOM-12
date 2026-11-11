<?php
declare(strict_types=1);

require __DIR__ . '/helper.php';
require_once __DIR__ . '/../src/AuditLogPresenter.php';
require_once __DIR__ . '/../src/LanguageCatalog.php';

$vietnamese = require __DIR__ . '/../lang/vi.php';
$english = require __DIR__ . '/../lang/en.php';
same(array_diff_key($vietnamese, $english), [], 'english-dictionary-has-all-vietnamese-keys');
same(array_diff_key($english, $vietnamese), [], 'vietnamese-dictionary-has-all-english-keys');
same(isset($english['menu.activity'], $english['profile.recent_activity']), true, 'activity-labels-are-localized');
same(isset($english['menu.support'], $vietnamese['menu.support']), true, 'support-menu-label-is-localized');
same(isset(
    $english['chat.open'],
    $vietnamese['chat.open'],
    $english['chat.title'],
    $vietnamese['chat.title'],
    $english['chat.online'],
    $vietnamese['chat.online'],
    $english['chat.close'],
    $vietnamese['chat.close'],
    $english['chat.greeting_title'],
    $vietnamese['chat.greeting_title'],
    $english['chat.welcome'],
    $vietnamese['chat.welcome'],
    $english['chat.quick_heading'],
    $vietnamese['chat.quick_heading'],
    $english['chat.quick_status'],
    $vietnamese['chat.quick_status'],
    $english['chat.quick_sync'],
    $vietnamese['chat.quick_sync'],
    $english['chat.quick_shortcuts'],
    $vietnamese['chat.quick_shortcuts'],
    $english['chat.quick_projects'],
    $vietnamese['chat.quick_projects'],
    $english['chat.input_label'],
    $vietnamese['chat.input_label'],
    $english['chat.placeholder'],
    $vietnamese['chat.placeholder'],
    $english['chat.send'],
    $vietnamese['chat.send']
), true, 'floating-ai-chat-labels-are-localized');
$chatWidgetMarkup = file_get_contents(__DIR__ . '/../templates/tms-ai-chat.php');
$sharedHeaderMarkup = file_get_contents(__DIR__ . '/../templates/tms-header.php');
$chatClientMarkup = file_get_contents(__DIR__ . '/../tms-ai-chat.js');
$chatEndpointMarkup = file_get_contents(__DIR__ . '/../ai_chat.php');
$aiTranslatorMarkup = file_get_contents(__DIR__ . '/../src/AiTranslator.php');
$chatWidgetIsWired = str_contains($sharedHeaderMarkup, "require __DIR__ . '/tms-ai-chat.php'")
    && str_contains($chatWidgetMarkup, 'tms-ai-chat.js')
    && str_contains($chatWidgetMarkup, 'data-csrf-token')
    && str_contains($chatWidgetMarkup, 'aria-controls="tms-ai-chat-panel"');
same(str_contains($aiTranslatorMarkup, 'Bạn là trợ lý AI chuyên nghiệp cho hệ thống Quản lý Dịch thuật (TMS).')
    && str_contains($aiTranslatorMarkup, 'GEMINI_API_KEY_MISSING')
    && str_contains($chatEndpointMarkup, 'AiTranslator($connection)')
    && !str_contains($chatEndpointMarkup, 'AQ.Ab8RN6Kxzoje'),
    true,
    'ai-chat-uses-server-side-gemini-configuration');
same($chatWidgetIsWired, true, 'floating-ai-chat-is-wired-into-authenticated-tms-header');
$chatClientSupportsDragAndSend = str_contains($chatClientMarkup, "addEventListener('pointerdown'")
    && str_contains($chatClientMarkup, 'setPointerCapture')
    && str_contains($chatClientMarkup, 'clampButton(rect.left, rect.top, true)')
    && str_contains($chatClientMarkup, "fetch(root.dataset.endpoint || 'ai_chat.php'")
    && str_contains($chatClientMarkup, 'bubble.textContent = text');
same($chatClientSupportsDragAndSend, true, 'floating-ai-chat-supports-drag-snap-and-safe-message-rendering');
$chatClientSupportsQuickChipsAndTyping = str_contains($chatClientMarkup, 'appendTypingIndicator')
    && str_contains($chatClientMarkup, 'tms-ai-chat-typing-dot')
    && str_contains($chatClientMarkup, "closest('button[data-question-vi][data-question-en]')")
    && str_contains($chatClientMarkup, 'void sendMessage(question)');
same($chatClientSupportsQuickChipsAndTyping, true, 'floating-ai-chat-supports-typing-indicator-and-quick-send-chips');
$chatClientHasLocalFaqLookup = str_contains($chatClientMarkup, 'const predefinedFaqs =')
    && str_contains($chatClientMarkup, 'normalizeQuestion(knownQuestion) === normalizedQuestion')
    && str_contains($chatClientMarkup, 'const predefinedAnswer = findPredefinedAnswer(text)')
    && str_contains($chatClientMarkup, 'if (predefinedAnswer !== null)')
    && substr_count($chatClientMarkup, "                '📊 ") >= 2
    && substr_count($chatClientMarkup, "                '🔄 ") >= 2;
same($chatClientHasLocalFaqLookup, true, 'floating-ai-chat-answers-known-quick-suggestions-locally');
$chatWidgetHasQuickChips = substr_count($chatWidgetMarkup, 'class="tms-ai-chat-chip"') === 4
    && str_contains($chatWidgetMarkup, 'tms-ai-chat-greeting')
    && str_contains($chatWidgetMarkup, 'data-question-vi')
    && str_contains($chatWidgetMarkup, 'data-question-en');
same($chatWidgetHasQuickChips, true, 'floating-ai-chat-shows-greeting-and-four-bilingual-quick-chips');
$chatEndpointIsAuthenticatedAndBounded = str_contains($chatEndpointMarkup, 'new AuthManager($connection)')
    && str_contains($chatEndpointMarkup, 'Csrf::isValid($_POST)')
    && str_contains($chatEndpointMarkup, 'count($messages) > 10')
    && str_contains($chatEndpointMarkup, 'count($recentRequests) >= 12');
same($chatEndpointIsAuthenticatedAndBounded, true, 'floating-ai-chat-endpoint-requires-session-csrf-and-rate-limit');
same(isset($english['profile.notify_comments'], $english['profile.notify_projects']), true, 'notification-settings-are-localized');
same(isset($english['translation.language_options'], $vietnamese['translation.language_options']), true, 'language-options-label-is-localized');
same(isset($english['translation.language_no_results'], $vietnamese['translation.language_no_results']), true, 'language-empty-state-is-localized');
same(isset($english['translation.skip_non_text'], $vietnamese['translation.skip_non_text']), true, 'non-text-filter-label-is-localized');
same(isset($english['translation.visible_rows'], $vietnamese['translation.visible_rows']), true, 'visible-row-count-label-is-localized');
same(isset(
    $english['translation.select_page_summary'],
    $vietnamese['translation.select_page_summary'],
    $english['translation.select_all_matching'],
    $vietnamese['translation.select_all_matching'],
    $english['translation.global_selected'],
    $vietnamese['translation.global_selected'],
    $english['translation.clear_selection'],
    $vietnamese['translation.clear_selection'],
    $english['translation.global_status_only'],
    $vietnamese['translation.global_status_only']
), true, 'global-selection-messages-are-localized');

$languageCatalog = LanguageCatalog::all();
$languageCodes = array_column($languageCatalog, 'code');
same(count($languageCatalog), 183, 'language-catalog-has-all-current-iso-639-1-codes');
same(count(array_unique($languageCodes)), count($languageCodes), 'language-catalog-codes-are-unique');
same(in_array('vi', $languageCodes, true) && in_array('en', $languageCodes, true) && in_array('zu', $languageCodes, true), true, 'language-catalog-includes-common-and-less-common-languages');
$catalogHasBilingualNames = count(array_filter(
    $languageCatalog,
    static fn(array $language): bool => $language['name_en'] !== '' && $language['name_vi'] !== ''
)) === count($languageCatalog);
same($catalogHasBilingualNames, true, 'language-catalog-has-english-and-vietnamese-names');

$globalKeys = [
    'header_subtitle',
    'process_import_btn',
    'menu.tm_repository',
    'menu.notifications_short',
    'workspace.title',
    'workspace.create_project',
    'workspace.page_title',
    'workspace.projects_description',
    'workspace.recent',
    'workspace.managed_projects',
    'workspace.average_progress',
    'tm.dropzone',
    'tm.file_name',
    'translation.untranslated',
    'translation.translated',
    'translation.approved',
    'translation.comments',
    'translation.bulk_selected_count',
    'translation.stop_progress',
    'translation.progress',
    'translation.rate_limited_error',
    'translation.deploy_language',
    'releases.history',
    'releases.version_lock_tab',
    'releases.api_sync_tab',
    'releases.version_lock_heading',
    'releases.record_count',
    'releases.actor',
    'releases.lock_empty',
    'releases.packages_heading',
    'releases.revoke_scope',
    'releases.endpoint',
    'releases.actions',
    'releases.sync_active',
    'releases.sync_revoked',
    'releases.revoke',
    'releases.sync_empty',
    'releases.sync_revoked_notice',
    'releases.sync_already_revoked',
    'releases.rollback_failed',
    'releases.rollback_record_failed',
    'releases.rollback_url',
    'releases.rollback_method',
    'releases.confirm_password',
    'releases.password_invalid',
    'releases.rollback_snapshot_missing',
    'releases.rollback_newer_first',
    'auth.login_title',
];
same(count(array_filter($globalKeys, static fn(string $key): bool => isset($english[$key], $vietnamese[$key]))), count($globalKeys), 'global-tms-labels-have-bilingual-dictionaries');

$releaseHistoryMarkup = file_get_contents(__DIR__ . '/../release_history.php');
$releaseHistoryUsesSeparateTabsAndCsrfProtectedRevoke = str_contains($releaseHistoryMarkup, 'tab=version_lock')
    && str_contains($releaseHistoryMarkup, 'tab=api_sync')
    && str_contains($releaseHistoryMarkup, "Csrf::isValid(\$_POST)")
    && str_contains($releaseHistoryMarkup, "type = 'api_sync' AND status = 'active'")
    && str_contains($releaseHistoryMarkup, "target_rollback_data")
    && str_contains($releaseHistoryMarkup, "rollback_method")
    && str_contains($releaseHistoryMarkup, 'name="password"')
    && str_contains($releaseHistoryMarkup, 'password_verify($confirmationPassword')
    && str_contains($releaseHistoryMarkup, 'releases.confirm_password')
    && str_contains($releaseHistoryMarkup, 'releases.password_invalid')
    && !str_contains($releaseHistoryMarkup, 'name="rollback_token"')
    && str_contains($releaseHistoryMarkup, 'release_sync_history');
same($releaseHistoryUsesSeparateTabsAndCsrfProtectedRevoke, true, 'release-history-has-tabs-and-protected-sync-revoke');
$allSyncEntrypointsRecordHistory = str_contains(file_get_contents(__DIR__ . '/../index.php'), "record_count, actor_user_id, new_data, target_rollback_data, requires_auth")
    && str_contains(file_get_contents(__DIR__ . '/../translate.php'), "record_count, actor_user_id, new_data, target_rollback_data, requires_auth");
same($allSyncEntrypointsRecordHistory, true, 'all-sync-back-entrypoints-record-api-history');
$translationLanguageHistoryIsRenderedOnServer = str_contains($workspaceMarkup = file_get_contents(__DIR__ . '/../index.php'), 'id="translation-history-container"')
    && str_contains($workspaceMarkup, 'Lịch sử các ngôn ngữ đã dịch')
    && str_contains($workspaceMarkup, "\$_SESSION['translated_history']")
    && str_contains($workspaceMarkup, '$currentHistoryLocale = strtoupper($currentLocale)')
    && str_contains($workspaceMarkup, '$historyLocaleUpper = strtoupper($historyLocale)')
    && str_contains($workspaceMarkup, '$historyLocaleIsActive = $historyLocaleUpper === strtoupper($currentLocale)')
    && str_contains($workspaceMarkup, '$historyLocaleUrl = $workspaceAction . \'&locale=\'')
    && str_contains($workspaceMarkup, 'aria-current="page"')
    && str_contains($workspaceMarkup, 'hover:bg-indigo-100')
    && str_contains($workspaceMarkup, 'list_translation_history')
    && str_contains($workspaceMarkup, 'data-open-translation-history')
    && str_contains($workspaceMarkup, 'translation-history-dialog')
    && str_contains($workspaceMarkup, 'data-rollback-text')
    && str_contains($workspaceMarkup, 'form.requestSubmit()')
    && str_contains($workspaceMarkup, 'Server returned HTML instead of JSON.')
    && str_contains($workspaceMarkup, 'const rawBody = await response.text()')
    && str_contains($workspaceMarkup, 'JSON.parse(rawBody)')
    && str_contains($workspaceMarkup, 'AS history_count')
    && str_contains($workspaceMarkup, 'trim((string)$row[\'translated_text\']) !== \'\' && (int)$row[\'history_count\'] > 0')
    && str_contains($workspaceMarkup, 'historyButton.hidden = input.value.trim() === \'\';')
    && str_contains($workspaceMarkup, 'latest_history.new_text')
    && str_contains($workspaceMarkup, 'ORDER BY h.created_at DESC, h.id DESC')
    && str_contains($workspaceMarkup, "THEN 'draft'")
    && str_contains($workspaceMarkup, '$_GET[\'locale\'] ?? $_GET[\'lang\']')
    && str_contains($workspaceMarkup, 'option?.click()')
    && !str_contains($workspaceMarkup, 'id="deployed-container"')
    && !str_contains($workspaceMarkup, 'window.deployedLanguages')
    && !str_contains($workspaceMarkup, 'window.renderDeployedLanguages')
    && !str_contains($workspaceMarkup, 'window.translatedHistory')
    && !str_contains($workspaceMarkup, 'new MutationObserver')
    && !str_contains($workspaceMarkup, 'window.renderTranslationHistory')
    && !str_contains($workspaceMarkup, 'window.handleLocaleSelection');
same($translationLanguageHistoryIsRenderedOnServer, true, 'translation-language-history-renders-on-server');

$headerAndImportLabelsMatch = str_contains($workspaceMarkup = file_get_contents(__DIR__ . '/../index.php'), 'data-i18n="header_subtitle"')
    && str_contains($workspaceMarkup, 'data-i18n="process_import_btn"')
    && $vietnamese['header_subtitle'] === 'Hệ thống quản lý dịch thuật cấp độ doanh nghiệp tích hợp TM, AI, Glossary & RBAC'
    && $english['header_subtitle'] === 'Enterprise-grade translation management system integrated with TM, AI, Glossary & RBAC'
    && $vietnamese['process_import_btn'] === '🚀 Xử lý & Import'
    && $english['process_import_btn'] === '🚀 Process & Import';
same($headerAndImportLabelsMatch, true, 'header-subtitle-and-import-button-are-translated');
$languageSelectorSupportsLiveSearch = str_contains($workspaceMarkup, "data-locale-name-en=")
    && str_contains($workspaceMarkup, "data-locale-name-vi=")
    && str_contains($workspaceMarkup, "addEventListener('input'")
    && str_contains($workspaceMarkup, ".normalize('NFD')")
    && str_contains($workspaceMarkup, "firstVisible.closest('[data-locale-row]')?.click()")
    && str_contains($workspaceMarkup, 'translation.language_no_results');
same($languageSelectorSupportsLiveSearch, true, 'language-selector-searches-bilingual-names-and-selects-matches');
$languageSelectorSortsByEnglishDisplayName = str_contains($workspaceMarkup, "firstOption.dataset.localeNameEn.localeCompare(")
    && str_contains($workspaceMarkup, 'optionsContainer?.prepend(sortedOptions)');
same($languageSelectorSortsByEnglishDisplayName, true, 'language-options-and-search-results-sort-by-english-name');
$nonTextSelectionFilterIsDynamic = str_contains($workspaceMarkup, 'id="translation-skip-non-text-control"')
    && str_contains($workspaceMarkup, 'data-key-path=')
    && str_contains($workspaceMarkup, 'data-source-text=')
    && str_contains($workspaceMarkup, 'const hasNonText = allRowCheckboxes.some(isNonTextRow)')
    && str_contains($workspaceMarkup, '(?:^|[._-])(?:id|image|price)(?:$|[._-])')
    && str_contains($workspaceMarkup, 'false)$/i.test(sourceText)')
    && str_contains($workspaceMarkup, 'skipNonTextControl.hidden = !hasNonText')
    && str_contains($workspaceMarkup, 'applySelectAll(selectAll.checked)')
    && str_contains($workspaceMarkup, 'checkbox.checked = checked')
    && str_contains($workspaceMarkup, '&& (!skipNonText.checked || !isNonTextRow(checkbox))')
    && str_contains($workspaceMarkup, 'refreshNonTextControl();');
same($nonTextSelectionFilterIsDynamic, true, 'non-text-filter-is-shown-dynamically-and-respected-by-select-all');
$selectAllPosition = strpos($workspaceMarkup, 'id="translation-select-all"');
$headerStart = $selectAllPosition === false ? false : strrpos(substr($workspaceMarkup, 0, $selectAllPosition), '<thead>');
$headerEnd = $headerStart === false ? false : strpos($workspaceMarkup, '</thead>', $selectAllPosition);
$translationTableHeader = $headerStart !== false && $headerEnd !== false
    ? substr($workspaceMarkup, $headerStart, $headerEnd - $headerStart)
    : '';
$nonTextFilterIsPlacedInBulkToolbar = preg_match(
    '/id="translation-bulk-toolbar".*?id="translation-skip-non-text-control"/s',
    $workspaceMarkup
) === 1
    && $translationTableHeader !== ''
    && !str_contains($translationTableHeader, 'id="translation-skip-non-text-control"')
    && str_contains($workspaceMarkup, 'if (shouldHide && checkbox.checked) checkbox.checked = false');
same($nonTextFilterIsPlacedInBulkToolbar, true, 'non-text-filter-is-in-bulk-toolbar-and-deselects-selected-technical-rows');
$globalSelectionUsesCurrentFilters = str_contains($workspaceMarkup, 'id="translation-global-selection"')
    && str_contains($workspaceMarkup, 'id="translation-select-all-matching"')
    && str_contains($workspaceMarkup, 'id="translation-clear-global-selection"')
    && str_contains($workspaceMarkup, 'let isAllPagesSelected = false')
    && str_contains($workspaceMarkup, 'allMatching: globalAction')
    && str_contains($workspaceMarkup, 'filters,')
    && str_contains($workspaceMarkup, "\$payload['allMatching']")
    && str_contains($workspaceMarkup, 's.source_version_id = $1')
    && str_contains($workspaceMarkup, 's.project_id = $2')
    && str_contains($workspaceMarkup, 's.id = ANY(')
    && str_contains($workspaceMarkup, 'translation_locks l');
same($globalSelectionUsesCurrentFilters, true, 'global-selection-applies-filtered-bulk-status-on-the-server');
$nonTextRowsAreHiddenAndCountUpdated = str_contains($workspaceMarkup, 'id="translation-visible-row-count"')
    && str_contains($workspaceMarkup, 'row.hidden = shouldHide')
    && str_contains($workspaceMarkup, 'skipNonText.checked && isNonTextRow(checkbox)')
    && str_contains($workspaceMarkup, "textContent = String(visibleRows.length)")
    && str_contains($workspaceMarkup, 'checkbox.checked && !checkbox.closest(\'tr\')?.hidden');
same($nonTextRowsAreHiddenAndCountUpdated, true, 'non-text-rows-are-hidden-restored-and-visible-count-updated');
$translationConflictHandling = str_contains($workspaceMarkup, 'name="base_version"')
    && str_contains($workspaceMarkup, 'base_version:')
    && str_contains($workspaceMarkup, 'response.status === 409')
    && str_contains($workspaceMarkup, "result.error_code === 'CONFLICT'")
    && str_contains($workspaceMarkup, "'error_code' => 'CONFLICT'")
    && str_contains($workspaceMarkup, 'id="translation-conflict-dialog"')
    && str_contains($workspaceMarkup, 'Làm mới dòng này')
    && str_contains($workspaceMarkup, 'updated_by');
same($translationConflictHandling, true, 'translation-save-detects-conflicts-and-offers-row-refresh');
$secondaryTranslationPage = file_get_contents(__DIR__ . '/../translate.php');
$secondaryTranslationConflictHandling = str_contains($secondaryTranslationPage, 'name="base_version"')
    && str_contains($secondaryTranslationPage, 'FOR UPDATE OF t')
    && str_contains($secondaryTranslationPage, 'id="translation-conflict-dialog"')
    && str_contains($secondaryTranslationPage, 'bg-amber-50/60')
    && str_contains($secondaryTranslationPage, 'backdrop-blur-md')
    && str_contains($secondaryTranslationPage, '🕒');
same($secondaryTranslationConflictHandling, true, 'secondary-translation-page-uses-optimistic-locking');
$conflictModalVisuals = str_contains($workspaceMarkup, 'tms-conflict-card rounded-2xl')
    && str_contains($workspaceMarkup, 'backdrop-blur-md')
    && str_contains($workspaceMarkup, 'bg-amber-50/60')
    && str_contains($workspaceMarkup, 'hover:scale-[1.02]')
    && str_contains($workspaceMarkup, '🕒');
same($conflictModalVisuals, true, 'conflict-modal-has-enhanced-diff-visuals');
$translationJsonResponseHandling = str_contains($workspaceMarkup, 'new URL(window.location.pathname, window.location.origin)')
    && str_contains($workspaceMarkup, "formEndpoint.origin !== window.location.origin")
    && str_contains($workspaceMarkup, 'endpoint.search = formEndpoint.search')
    && str_contains($workspaceMarkup, 'readTranslationSaveResponse')
    && str_contains($workspaceMarkup, 'await response.text()')
    && str_contains($workspaceMarkup, '<!doctype\\s+html')
    && str_contains($workspaceMarkup, 'Server returned HTML instead of JSON')
    && str_contains($workspaceMarkup, 'body: rawBody.slice(0, 2000)')
    && str_contains($workspaceMarkup, "header('Content-Type: application/json; charset=utf-8');")
    && str_contains($workspaceMarkup, 'while (ob_get_level() > 0) {');
same($translationJsonResponseHandling, true, 'translation-save-validates-endpoint-and-parses-json-safely');
$projectDetailRoute = file_get_contents(__DIR__ . '/../project_detail.php');
$projectDetailRoutesTranslationRequests = str_contains($workspaceMarkup, '$workspaceAction')
    && str_contains($projectDetailRoute, "require __DIR__ . '/index.php';")
    && str_contains($projectDetailRoute, "'save_translation';")
    && str_contains($projectDetailRoute, "header('Content-Type: application/json; charset=utf-8');");
same($projectDetailRoutesTranslationRequests, true, 'project-detail-save-route-dispatches-json-requests');
$projectManagementLayoutUsesSharedGrid = str_contains($workspaceMarkup, 'grid grid-cols-1 lg:grid-cols-12 gap-6 w-full col-span-12 mb-8 items-stretch')
    && str_contains($workspaceMarkup, "max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 w-full space-y-6")
    && str_contains($workspaceMarkup, 'order-2 w-full lg:col-span-1 tms-project-admin-column tms-import-column')
    && str_contains($workspaceMarkup, 'w-full col-span-12 min-w-0 h-full flex flex-col <?= ($currentProjectRole === \'Reviewer\' || $currentProjectRole === \'Translator\') ? \'lg:col-span-12\' : \'lg:col-span-7\' ?>')
    && str_contains($workspaceMarkup, '<div class="tms-export-column">')
    && str_contains($workspaceMarkup, 'tms-export-panel tms-accent-indigo w-full')
    && str_contains($workspaceMarkup, 'tms-project-admin-stack')
    && str_contains($workspaceMarkup, '$projectImportMarkup = ob_get_clean()')
    && str_contains($workspaceMarkup, 'tms-members-table-wrap mt-5 w-full max-h-[480px] overflow-y-auto overflow-x-hidden')
    && str_contains($workspaceMarkup, 'h-full flex flex-col lg:col-span-5 tms-project-admin-column tms-project-admin-stack')
    && str_contains($workspaceMarkup, 'tms-bottom-layout-row grid grid-cols-1 lg:grid-cols-12 gap-6 items-start w-full col-span-12')
    && str_contains($workspaceMarkup, 'tms-bottom-section tms-reviewer-audit-section w-full col-span-12 tms-accent-slate')
    && str_contains($workspaceMarkup, 'tms-bottom-section tms-reviewer-performance-section tms-member-performance w-full col-span-12 tms-accent-indigo')
    && str_contains($workspaceMarkup, 'tms-reviewer-language-section w-full col-span-12')
    && str_contains($workspaceMarkup, 'tms-reviewer-glossary-section tms-glossary-panel w-full col-span-12')
    && str_contains($workspaceMarkup, 'tms-reviewer-translation-section tms-main-panel tms-translation-panel tms-accent-indigo w-full col-span-12')
    && str_contains($workspaceMarkup, 'tms-reviewer-glossary-section')
    && str_contains(file_get_contents(__DIR__ . '/../tms.css'), 'grid-template-columns: minmax(0, 1fr);')
    && str_contains($workspaceMarkup, 'tms-members-table w-full table-fixed')
    && !str_contains($workspaceMarkup, 'tms-members-table-wrap overflow-x-auto')
    && !str_contains($workspaceMarkup, 'col-lg-8 tms-project-member-column')
    && !str_contains($workspaceMarkup, 'col-lg-4 tms-project-admin-column tms-export-column');
same($projectManagementLayoutUsesSharedGrid, true, 'project-management-cards-use-balanced-grid');
$projectManagementCssUsesTwoColumnFlow = str_contains(file_get_contents(__DIR__ . '/../tms.css'), 'grid-template-columns: repeat(12, minmax(0, 1fr));')
    && str_contains(file_get_contents(__DIR__ . '/../tms.css'), '.tms-import-column {')
    && str_contains(file_get_contents(__DIR__ . '/../tms.css'), '.tms-project-layout-row > .tms-project-admin-stack {')
    && str_contains(file_get_contents(__DIR__ . '/../tms.css'), '.tms-export-column {')
    && str_contains(file_get_contents(__DIR__ . '/../tms.css'), 'grid-column: 2;')
    && str_contains(file_get_contents(__DIR__ . '/../tms.css'), 'grid-template-columns: minmax(0, 1fr);')
    && str_contains(file_get_contents(__DIR__ . '/../tms.css'), '.tms-members-table {')
    && str_contains(file_get_contents(__DIR__ . '/../tms.css'), 'table-layout: fixed;')
    && str_contains(file_get_contents(__DIR__ . '/../tms.css'), 'overflow-x: hidden;');
same($projectManagementCssUsesTwoColumnFlow, true, 'project-management-css-uses-two-column-flow');
$projectRoleBasedUi = str_contains($workspaceMarkup, '$isProjectLeader = $currentProjectRole === \'Team Lead\'')
    && str_contains($workspaceMarkup, 'defined(\'TMS_PROJECT_DETAIL\') && $currentVersion && $isProjectLeader')
    && str_contains($workspaceMarkup, "\$currentProjectRole === 'Team Lead' || \$currentProjectRole === 'Reviewer'")
    && str_contains($workspaceMarkup, 'defined(\'TMS_PROJECT_DETAIL\') && $isAuthenticated && $isProjectLeader')
    && str_contains($workspaceMarkup, '!ProjectAccess::canManageSourceVersion($conn, $projectId, (int)$currentUser[\'id\'])')
    && str_contains($workspaceMarkup, '!ProjectAccess::canManageRelease($conn, $projectId, (int)$currentUser[\'id\'])');
same($projectRoleBasedUi, true, 'project-management-ui-is-role-aware-and-backend-guarded');

$workspaceKeys = [
    'workspace.title',
    'workspace.page_title',
    'workspace.projects_description',
    'workspace.recent',
    'workspace.managed_projects',
    'workspace.average_progress',
    'workspace.no_projects',
    'workspace.quick_access',
    'workspace.create_project_hint',
    'workspace.create_project',
    'workspace.quick_access',
    'workspace.search_empty',
    'workspace.no_projects',
    'workspace.create_project_hint',
];
$workspaceLabelsAreMarked = true;
foreach ($workspaceKeys as $key) {
    $workspaceLabelsAreMarked = $workspaceLabelsAreMarked && str_contains($workspaceMarkup, 'data-i18n="' . $key . '"');
}
$workspaceLabelsAreMarked = $workspaceLabelsAreMarked
    && str_contains($workspaceMarkup, 'data-i18n-placeholder="workspace.search_placeholder"')
    && str_contains($workspaceMarkup, 'data-i18n-placeholder="workspace.project_name_placeholder"')
    && str_contains($workspaceMarkup, 'data-i18n-placeholder="workspace.project_description_placeholder"');
same($workspaceLabelsAreMarked, true, 'workspace-static-labels-have-i18n-markers');

$workspaceKeyReferences = [];
preg_match_all('/data-i18n(?:-(?:aria-label|title|placeholder|alt))?="(workspace\.[^"]+)"/', $workspaceMarkup, $workspaceKeyMatches);
$workspaceKeyReferences = array_values(array_unique($workspaceKeyMatches[1]));
$missingWorkspaceDictionaryKeys = array_values(array_filter(
    $workspaceKeyReferences,
    static fn(string $key): bool => !isset($english[$key], $vietnamese[$key])
));
same($missingWorkspaceDictionaryKeys, [], 'all-workspace-markup-keys-exist-in-both-dictionaries');

$dynamicWorkspaceKeys = [
    'translation.untranslated',
    'translation.approved',
    'translation.rejected',
    'translation.needs_review',
    'translation.translated',
];
$missingDynamicWorkspaceKeys = array_values(array_filter(
    $dynamicWorkspaceKeys,
    static fn(string $key): bool => !isset($english[$key], $vietnamese[$key])
));
same($missingDynamicWorkspaceKeys, [], 'dynamic-workspace-status-keys-exist-in-both-dictionaries');

$workspaceDictionaryKeys = [
    'workspace.title',
    'workspace.projects_description',
    'workspace.recent',
    'workspace.managed_projects',
    'workspace.average_progress',
    'workspace.search_placeholder',
    'workspace.project_name_placeholder',
    'workspace.project_description_placeholder',
];
$workspaceDictionaryIsBilingual = count(array_filter(
    $workspaceDictionaryKeys,
    static fn(string $key): bool => isset($english[$key], $vietnamese[$key])
)) === count($workspaceDictionaryKeys);
same($workspaceDictionaryIsBilingual, true, 'workspace-labels-have-vietnamese-and-english-translations');

$detailAndAuditKeys = [
    'project.back_to_my_projects',
    'project.project_label',
    'project.file_format_label',
    'audit.title',
    'audit.export_report',
    'audit.filter_action',
    'audit.filter_user_id',
    'audit.action_type',
    'audit.performer',
    'audit.change_details',
    'audit.timestamp',
    'audit.member_performance',
    'audit.keys_touched',
    'audit.updates',
    'audit.system',
    'audit.source_imported',
    'audit.bulk_updated',
    'audit.translation_updated',
    'audit.ai_translated',
    'audit.version_frozen',
    'audit.release_exported',
    'audit.translations_synced',
    'audit.package_exported',
    'audit.glossary_added',
    'audit.glossary_updated',
    'audit.glossary_deleted',
    'audit.generic_action',
];
$detailAndAuditKeysAreBilingual = count(array_filter(
    $detailAndAuditKeys,
    static fn(string $key): bool => isset($english[$key], $vietnamese[$key])
)) === count($detailAndAuditKeys);
same($detailAndAuditKeysAreBilingual, true, 'project-detail-and-audit-labels-have-bilingual-dictionaries');

$detailAndAuditMarkupKeys = [];
preg_match_all('/data-i18n(?:-(?:aria-label|title|placeholder|alt))?="((?:project|audit)\.[^"]+)"/', $workspaceMarkup, $detailAndAuditMatches);
$detailAndAuditMarkupKeys = array_values(array_unique($detailAndAuditMatches[1]));
$missingDetailAndAuditKeys = array_values(array_filter(
    $detailAndAuditMarkupKeys,
    static fn(string $key): bool => !isset($english[$key], $vietnamese[$key])
));
same($missingDetailAndAuditKeys, [], 'all-project-detail-and-audit-markup-keys-exist');

$importDescription = AuditLogPresenter::describe(
    'IMPORT_SOURCE',
    'User [doanh] import phiên bản [v2.1]'
);
same($importDescription, [
    'key' => 'audit.source_imported',
    'params' => ['username' => 'doanh', 'version' => 'v2.1'],
], 'audit-import-details-use-translatable-template');
$bulkDescription = AuditLogPresenter::describe(
    'BULK_UPDATE_TRANSLATIONS',
    'User [doanh] cập nhật hàng loạt [en]: key 10: nội dung [a] → [b], trạng thái [draft] → [approved]; key 11: nội dung [c] → [d], trạng thái [draft] → [approved]'
);
same($bulkDescription, [
    'key' => 'audit.bulk_updated',
    'params' => ['username' => 'doanh', 'count' => '2', 'locale' => 'en'],
], 'audit-bulk-details-use-translatable-template');
$globalBulkDescription = AuditLogPresenter::describe(
    'BULK_UPDATE_TRANSLATIONS',
    'User [doanh] cập nhật hàng loạt [en]: trạng thái [approved], cập nhật [12] bản ghi trong kết quả lọc'
);
same($globalBulkDescription, [
    'key' => 'audit.bulk_updated',
    'params' => ['username' => 'doanh', 'count' => '12', 'locale' => 'en'],
], 'audit-global-bulk-details-report-updated-record-count');
$fallbackAuditDescription = AuditLogPresenter::describe('NEW_SYSTEM_ACTION', 'Unrecognized detail');
same($fallbackAuditDescription, [
    'key' => 'audit.generic_action',
    'params' => ['action' => 'NEW_SYSTEM_ACTION'],
], 'unknown-audit-actions-use-translated-fallback');

$interpolatedKeys = [
    'translation.bulk_selected_count',
    'translation.select_page_summary',
    'translation.select_all_matching',
    'translation.global_selected',
    'translation.stop_progress',
    'translation.progress',
    'translation.rate_limited_error',
    'translation.batch_progress',
    'translation.batch_started',
    'translation.batch_failures',
    'translation.batch_failures_with_forced_saves',
    'translation.batch_failure_details',
];
$interpolatedKeys = array_merge($interpolatedKeys, [
    'project.project_label',
    'audit.keys_touched',
    'audit.updates',
    'audit.source_imported',
    'audit.bulk_updated',
    'audit.translation_updated',
    'audit.ai_translated',
    'audit.version_frozen',
    'audit.release_exported',
    'audit.translations_synced',
    'audit.package_exported',
    'audit.glossary_added',
    'audit.glossary_updated',
    'audit.glossary_deleted',
    'audit.generic_action',
]);
$interpolationPlaceholdersMatch = true;
foreach ($interpolatedKeys as $key) {
    preg_match_all('/\{([a-z0-9_]+)\}/i', $vietnamese[$key], $vietnameseMatches);
    preg_match_all('/\{([a-z0-9_]+)\}/i', $english[$key], $englishMatches);
    $vietnamesePlaceholders = $vietnameseMatches[1];
    $englishPlaceholders = $englishMatches[1];
    sort($vietnamesePlaceholders);
    sort($englishPlaceholders);
    $interpolationPlaceholdersMatch = $interpolationPlaceholdersMatch && $vietnamesePlaceholders === $englishPlaceholders;
}
same($interpolationPlaceholdersMatch, true, 'dynamic-localization-placeholders-match-across-languages');

$workspaceMarkup = file_get_contents(__DIR__ . '/../index.php');
same(str_contains($workspaceMarkup, '$batchRows = json_decode'), true, 'translation-batch-endpoint-accepts-row-array');
same(str_contains($workspaceMarkup, 'count($batchRows) > 20'), true, 'translation-batch-endpoint-caps-batch-at-twenty');
same(str_contains($workspaceMarkup, "\$forcePlaceholderSave = \$_POST['force_placeholders'] ?? '0';"), true, 'translation-batch-endpoint-parses-explicit-placeholder-opt-in');
same(str_contains($workspaceMarkup, "if (!\$placeholdersValid && !\$forcePlaceholderSave)"), true, 'translation-batch-endpoint-rejects-placeholder-mismatch-by-default');
same(str_contains($workspaceMarkup, "'code' => 'PLACEHOLDER_MISMATCH'"), true, 'translation-batch-endpoint-identifies-force-saveable-mismatch');
same(str_contains($workspaceMarkup, "force_placeholders: forcePlaceholderSave ? '1' : '0'"), true, 'translation-frontend-sends-placeholder-opt-in');
same(str_contains($workspaceMarkup, 'id="google-auto-translate-force-placeholders-control"'), true, 'translation-frontend-offers-placeholder-override');
same(str_contains($workspaceMarkup, 'name="translator_provider"')
    && str_contains($workspaceMarkup, 'value="mymemory"')
    && str_contains($workspaceMarkup, 'value="google"')
    && str_contains($workspaceMarkup, 'value="libretranslate"')
    && str_contains($workspaceMarkup, 'translator_provider: providerSelect?.value || \'mymemory\''),
    true,
    'translation-frontend-selects-provider');
$providerService = file_get_contents(__DIR__ . '/../src/TranslationProviderService.php');
same(str_contains($workspaceMarkup, 'TranslationProviderService')
    && str_contains($workspaceMarkup, '$_POST[\'translator_provider\']')
    && str_contains($workspaceMarkup, '$translatorProvider')
    && str_contains($workspaceMarkup, '->translate(')
    && str_contains($providerService, 'https://translate.googleapis.com/translate_a/single')
    && str_contains($providerService, 'TMS_LIBRETRANSLATE_URL')
    && str_contains($providerService, 'QUOTA_EXCEEDED'),
    true,
    'translation-backend-supports-provider-selection');
same(str_contains($workspaceMarkup, 'splitIntoBatches(pendingRows)'), true, 'translation-frontend-chunks-pending-rows');
same(str_contains($workspaceMarkup, 'setTimeout(resolve, 250)'), true, 'translation-frontend-delays-between-batches');
same(str_contains($workspaceMarkup, 'translation.batch_progress'), true, 'translation-frontend-reports-batch-progress');
same(str_contains($workspaceMarkup, 'failedBatches.push'), true, 'translation-frontend-continues-after-batch-failure');
same(str_contains($workspaceMarkup, 'if (pendingRows.length <= 10)'), true, 'translation-frontend-uses-sequential-path-for-ten-or-fewer-rows');
same(str_contains($workspaceMarkup, 'for (const [rowIndex, row] of pendingRows.entries())'), true, 'translation-small-batches-are-processed-in-order');
same(str_contains($workspaceMarkup, 'await processBatch([row], rowIndex, pendingRows.length, true)'), true, 'translation-sequential-path-awaits-each-single-row');
same(str_contains($workspaceMarkup, '} else {' . "\n                            const batches = splitIntoBatches(pendingRows);"), true, 'translation-large-batches-use-chunked-concurrency-path');
same(str_contains($workspaceMarkup, 'const workerCount = Math.min(5, batches.length)'), true, 'translation-pool-caps-concurrent-workers-at-five');
same(str_contains($workspaceMarkup, 'Promise.allSettled('), true, 'translation-pool-waits-for-limited-workers');
same(str_contains($workspaceMarkup, 'activeTranslatedIds.add(stringId)'), true, 'translation-progress-counts-successful-rows-once');
same(str_contains($workspaceMarkup, 'translatedRows = Math.max(translatedRows, reportedProgress)'), true, 'translation-progress-is-monotonic-across-workers');
same(str_contains($workspaceMarkup, 'Math.ceil(rows.length / 15)'), true, 'translation-pool-aims-for-fifteen-rows-per-batch');

$autoTargetsValid = true;
$autoLabelsConsistent = true;
$knownLabels = [];
foreach ([$vietnamese, $english] as $dictionary) {
    foreach ($dictionary['__auto'] as $label => $key) {
        $autoTargetsValid = $autoTargetsValid && isset($dictionary[$key]);
        $autoLabelsConsistent = $autoLabelsConsistent && (!isset($knownLabels[$label]) || $knownLabels[$label] === $key);
        $knownLabels[$label] = $key;
    }
}
same($autoTargetsValid, true, 'global-auto-labels-reference-existing-keys');
same($autoLabelsConsistent, true, 'global-auto-labels-have-consistent-keys');

$applicationPages = [
    'login.php',
    'register.php',
    'tm_repository.php',
    'release_history.php',
    'release_view.php',
    'translation_comments.php',
];
$pagesLoadGlobalLocalization = true;
foreach ($applicationPages as $page) {
    $pagesLoadGlobalLocalization = $pagesLoadGlobalLocalization
        && str_contains(file_get_contents(__DIR__ . '/../' . $page), 'tms-i18n.js');
}
foreach (['index.php', 'translate.php', 'profile.php'] as $page) {
    $pagesLoadGlobalLocalization = $pagesLoadGlobalLocalization
        && str_contains(file_get_contents(__DIR__ . '/../' . $page), 'templates/tms-header.php');
}
$adminPageMarkup = file_get_contents(__DIR__ . '/../admin_users.php');
$pagesLoadGlobalLocalization = $pagesLoadGlobalLocalization
    && str_contains($adminPageMarkup, '<script src="tms-i18n.js" defer></script>');
$adminPageIsStandalone = !str_contains($adminPageMarkup, "templates/tms-header.php")
    && !str_contains($adminPageMarkup, "require __DIR__ . '/index.php'")
    && !str_contains($adminPageMarkup, 'Dự án của tôi')
    && !str_contains($adminPageMarkup, 'Tạo dự án mới')
    && str_contains($adminPageMarkup, 'Danh sách người dùng');
same($adminPageIsStandalone, true, 'admin-page-is-separate-from-user-workspace');
$adminNavigationPointsToUserManagement = str_contains(
    file_get_contents(__DIR__ . '/../templates/tms-header.php'),
    'href="admin_users.php"'
);
same($adminNavigationPointsToUserManagement, true, 'admin-navigation-opens-standalone-admin-page');
$sharedHeaderMarkup = file_get_contents(__DIR__ . '/../templates/tms-header.php');
same(str_contains($sharedHeaderMarkup, 'href="profile.php#support-requests"'), true, 'account-menu-links-to-support-form');
$profilePageMarkup = file_get_contents(__DIR__ . '/../profile.php');
same(str_contains($profilePageMarkup, 'id="support-requests"'), true, 'support-menu-target-exposes-ticket-form');
same(str_contains($profilePageMarkup, 'name="action" value="submit_support_ticket"'), true, 'support-form-submits-ticket');
$projectDetailIncludesWorkspace = str_contains(file_get_contents(__DIR__ . '/../project_detail.php'), "require __DIR__ . '/index.php'");
$sharedHeaderLoadsLocalization = str_contains(
    file_get_contents(__DIR__ . '/../templates/tms-header.php'),
    '<script src="tms-i18n.js" defer></script>'
);
$i18nScript = file_get_contents(__DIR__ . '/../tms-i18n.js');
$localizationWaitsForDocumentReady = str_contains(
    $i18nScript,
    "document.addEventListener('DOMContentLoaded', initializeWhenReady, { once: true })"
);
$localizationLoadsSavedLanguageFirst = preg_match(
    '/const initialLanguage = readLanguage\(\);\s+const dictionary = await loadDictionary\(initialLanguage\);/',
    $i18nScript
) === 1;
$localizationScansEntireBody = str_contains(
    $i18nScript,
    'applyMarkedTranslations(document.body, dictionary)'
);
$localizationTranslatesPlaceholders = str_contains(
    $i18nScript,
    "['data-i18n-placeholder', 'placeholder']"
);
$localizationIgnoresStaleLanguageLoads = str_contains(
    $i18nScript,
    'if (changeToken !== languageChangeToken) return;'
);
$workspaceTranslationTraceExists = str_contains(
    $i18nScript,
    "console.log('[TMS i18n] Workspace translation trace'"
) && str_contains($i18nScript, 'dictionaryUrl: `${dictionaryUrl}?lang=${encodeURIComponent(language)}`');
$auditMarkup = str_contains(
    $workspaceMarkup,
    'data-i18n="audit.system">Hệ thống</span>'
) && str_contains($workspaceMarkup, 'AuditLogPresenter::describe')
    && !str_contains($workspaceMarkup, 'htmlspecialchars($log[\'details\'])');
$auditPresenterIsLoaded = str_contains(
    $workspaceMarkup,
    "require_once __DIR__ . '/src/AuditLogPresenter.php';"
);
$dictionaryFetchDisablesCache = str_contains($i18nScript, "cache: 'no-store'");
$dictionaryEndpointDisablesCache = str_contains(
    file_get_contents(__DIR__ . '/../i18n.php'),
    "header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0')"
);
$themePages = [
    'index.php',
    'translate.php',
    'login.php',
    'register.php',
    'profile.php',
    'admin_users.php',
    'tm_repository.php',
    'release_history.php',
    'release_view.php',
    'translation_comments.php',
];
$allThemePagesLoadSwitcher = true;
foreach ($themePages as $page) {
    $pageMarkup = file_get_contents(__DIR__ . '/../' . $page);
    $allThemePagesLoadSwitcher = $allThemePagesLoadSwitcher
        && str_contains($pageMarkup, 'tms-theme.js')
        && (str_contains($pageMarkup, 'templates/tms-header.php') || str_contains($pageMarkup, 'templates/theme-toggle.php'));
}
$themeScript = file_get_contents(__DIR__ . '/../tms-theme.js');
$themeScriptPersistsAndAppliesBothModes = str_contains($themeScript, "localStorage.getItem(storageKey)")
    && str_contains($themeScript, "localStorage.setItem(storageKey, theme)")
    && str_contains($themeScript, "classList.toggle('tms-dark'")
    && str_contains($themeScript, "classList.toggle('tms-light'");
$themeSwitcherMarkup = file_get_contents(__DIR__ . '/../templates/theme-toggle.php');
$themeSwitcherUsesModeIcons = str_contains($themeSwitcherMarkup, 'data-theme-icon="moon"')
    && str_contains($themeSwitcherMarkup, 'data-theme-icon="sun"')
    && str_contains($themeSwitcherMarkup, '<svg')
    && !str_contains($themeSwitcherMarkup, 'tms-theme-toggle-label')
    && str_contains($themeScript, 'moonIcon.hidden = isDark')
    && str_contains($themeScript, 'sunIcon.hidden = !isDark');
$themeStyles = file_get_contents(__DIR__ . '/../tms.css');
$themeStylesSupportBothPalettes = str_contains($themeStyles, 'body:not(.tms-light)')
    && str_contains($themeStyles, 'body.tms-light')
    && str_contains($themeStyles, 'body.tms-light #tms-ai-chat-panel')
    && str_contains($themeStyles, 'body.tms-light .tms-ai-chat-header')
    && str_contains($themeStyles, 'body.tms-light .tms-ai-chat-greeting')
    && str_contains($themeStyles, 'body.tms-light .tms-ai-chat-chip')
    && str_contains($themeStyles, 'body.tms-light .tms-ai-chat-message.is-assistant')
    && str_contains($themeStyles, 'body.tms-light .tms-ai-chat-input')
    && str_contains($themeStyles, '#06b6d4')
    && str_contains($themeStyles, '#ec4899')
    && str_contains($themeStyles, '#8b5cf6')
    && str_contains($themeStyles, '#84cc16');
$syncMarkup = file_get_contents(__DIR__ . '/../index.php');
$translateMarkup = file_get_contents(__DIR__ . '/../translate.php');
$syncClient = file_get_contents(__DIR__ . '/../tms-sync-back.js');
$syncService = file_get_contents(__DIR__ . '/../src/RemoteContentService.php');
$syncBackHandlesResponsesSafely = str_contains($syncClient, "form.addEventListener('submit'")
    && str_contains($syncClient, "form.querySelector('#sync-back-url, #sync-url')")
    && str_contains($syncClient, 'const endpoint = urlInput.value.trim()')
    && str_contains($syncClient, "endpoint.includes('[object')")
    && str_contains($syncClient, '!/^https?:\\/\\//i.test(endpoint)')
    && str_contains($syncClient, 'console.log("Endpoint đang gọi:", endpoint)')
    && str_contains($syncClient, 'const endpoint = urlInput.value.trim()')
    && str_contains($syncClient, "form.getAttribute('action')?.trim() || window.location.href")
    && !str_contains($syncClient, 'form.action')
    && str_contains($syncClient, 'fetch(requestUrl.href, {')
    && str_contains($syncClient, "hostname === 'netlify.app' || hostname.endsWith('.netlify.app')")
    && str_contains($syncClient, "Lỗi: Không thể trỏ API Endpoint về chính trang web TMS.")
    && str_contains($syncClient, 'if (isTmsWebsiteEndpoint(parsedEndpoint))')
    && str_contains($syncClient, "'Content-Type': 'application/json'")
    && str_contains($syncClient, "'X-TMS-Sync-Back': '1'")
    && str_contains($syncClient, 'const requestPayload = Object.fromEntries(new FormData(form).entries())')
    && str_contains($syncClient, 'body: JSON.stringify(requestPayload)')
    && str_contains($syncClient, 'const responseText = await response.text()')
    && str_contains($syncClient, "response.headers.get('content-type')")
    && str_contains($syncClient, 'if (isHtmlResponse(responseContentType, responseText))')
    && str_contains($syncClient, 'Máy chủ trả về trang HTML (HTTP ${response.status}). Bạn đang gọi nhầm vào một trang web thay vì API JSON.')
    && str_contains($syncClient, 'endpoint: parsedEndpoint.href')
    && str_contains($syncClient, 'Destination endpoint returned HTML instead of JSON.')
    && str_contains($syncClient, '!response.ok || result.success !== true')
    && str_contains($syncClient, "result.remote_content_type || ''")
    && str_contains($syncClient, 'showToast(error instanceof Error ? error.message')
    && str_contains($syncMarkup, 'data-sync-back-form')
    && str_contains($syncMarkup, 'tms-sync-back.js')
    && str_contains($syncMarkup, '<option value="PUT" selected>PUT</option>')
    && str_contains($translateMarkup, 'data-sync-back-form')
    && str_contains($translateMarkup, 'tms-sync-back.js')
    && str_contains($translateMarkup, '<option value="PUT" selected>PUT</option>')
    && str_contains($syncMarkup, 'RemoteContentService::validatePublicUrl($syncUrl)')
    && str_contains($syncMarkup, '$syncRemoteErrorDetail')
    && str_contains($syncMarkup, "'remote_status' => \$syncRemoteStatusCode")
    && str_contains($syncMarkup, "'remote_response' => \$syncRemoteSuccessResponse")
    && str_contains($syncMarkup, '$syncRemoteErrorDetail = $exception->responseBody')
    && str_contains($syncMarkup, 'http_response_code($exception instanceof RemoteHttpException ? $exception->status : 502)')
    && str_contains($translateMarkup, 'RemoteContentService::validatePublicUrl($syncUrl)')
    && str_contains($translateMarkup, '$syncRemoteErrorDetail')
    && str_contains($translateMarkup, "'remote_status' => \$syncRemoteStatusCode")
    && str_contains($translateMarkup, "'remote_response' => \$syncRemoteSuccessResponse")
    && str_contains($translateMarkup, '$syncRemoteErrorDetail = $exception->responseBody')
    && str_contains($translateMarkup, 'http_response_code($exception instanceof RemoteHttpException ? $exception->status : 502)');
$syncSendsAuthenticatedJsonSafely = str_contains($syncService, "'Content-Type: application/json'")
    && str_contains($syncService, "'Accept: ' . (\$jsonBody !== null")
    && str_contains($syncService, "'Authorization: Bearer ' . \$bearerToken")
    && str_contains($syncService, 'CURLOPT_CUSTOMREQUEST => $method')
    && str_contains($syncService, 'CURLOPT_POSTFIELDS => $jsonBody')
    && str_contains($syncService, "getenv('APP_ENV') === 'local'")
    && str_contains($syncService, 'CURLOPT_SSL_VERIFYPEER => !$insecureLocalTls')
    && str_contains($syncService, 'CURLOPT_SSL_VERIFYHOST => $insecureLocalTls ? 0 : 2')
    && str_contains($syncService, 'CURLOPT_CONNECTTIMEOUT => 10')
    && str_contains($syncService, 'CURLOPT_TIMEOUT => 30')
    && str_contains($syncService, '?int &$responseStatus = null')
    && str_contains($syncService, '$responseStatus = $status')
    && str_contains($syncService, '$jsonBody !== null && $host === \'api.npoint.io\'')
    && str_contains($syncService, '$jsonBody = self::wrapNpointTranslations($jsonBody)')
    && str_contains($syncService, "['translations' => \$payloadData]")
    && str_contains($syncService, "JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR")
    && str_contains($syncService, "self::writeNpointDebugFile('npoint_debug.json', \$jsonBody)")
    && str_contains($syncService, "self::writeNpointDebugFile('npoint_error_response.log', \$body)")
    && str_contains($syncService, "['npoint_debug.json', 'npoint_error_response.log']")
    && str_contains($syncService, 'chmod($path, 0600)')
    && str_contains($syncService, 'NPOINT_DEBUG_STORAGE_INSIDE_DOCUMENT_ROOT')
    && str_contains($syncService, '$body === false || $curlErrno !== CURLE_OK')
    && str_contains($syncService, 'finally {')
    && str_contains($syncService, "if (\$method === 'GET' && trim(\$body) === '')")
    && str_contains($syncService, 'new RemoteHttpException($status, $body, $contentType)')
    && str_contains($syncService, "return \$host === 'api.npoint.io' ? 'PUT' : \$method")
    && str_contains($syncService, 'json_decode($jsonBody, false, 512, JSON_THROW_ON_ERROR)')
    && str_contains($syncService, 'JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR')
    && str_contains($syncMarkup, "file_get_contents('php://input')")
    && str_contains($syncMarkup, 'json_decode($rawSyncBackInput, true, 512, JSON_THROW_ON_ERROR)')
    && str_contains($syncMarkup, "(\$_SERVER['HTTP_X_TMS_SYNC_BACK'] ?? '') === '1'")
    && str_contains($syncMarkup, "\$requestContentType !== 'application/json'")
    && str_contains($syncMarkup, 'set_exception_handler(static function (Throwable $exception)')
    && str_contains($syncMarkup, "'status' => 500")
    && str_contains($syncMarkup, "'error' => 'PHP Error: Lỗi hệ thống khi xử lý yêu cầu đồng bộ.'")
    && str_contains($syncMarkup, 'catch (Throwable $exception)')
    && str_contains($translateMarkup, "file_get_contents('php://input')")
    && str_contains($translateMarkup, 'json_decode($rawSyncBackInput, true, 512, JSON_THROW_ON_ERROR)')
    && str_contains($translateMarkup, "\$requestContentType !== 'application/json'")
    && str_contains($translateMarkup, 'set_exception_handler(static function (Throwable $exception)')
    && str_contains($translateMarkup, "'status' => \$statusCode")
    && str_contains($translateMarkup, "'error' => \$statusCode >= 500")
    && str_contains($translateMarkup, 'catch (Throwable $exception)')
    && str_contains($syncService, 'CURLINFO_CONTENT_TYPE')
    && str_contains($syncService, 'validateJsonResponse($contentType, $body, $status)')
    && str_contains($syncService, 'class RemoteHttpException')
    && str_contains($syncMarkup, 'REMOTE_REQUEST_FAILED')
    && str_contains($syncMarkup, 'http_response_code(502)')
    && str_contains($translateMarkup, 'REMOTE_REQUEST_FAILED')
    && str_contains($translateMarkup, 'http_response_code(502)');
same($pagesLoadGlobalLocalization && $projectDetailIncludesWorkspace && $sharedHeaderLoadsLocalization, true, 'all-enterprise-pages-load-global-localization');
same($localizationWaitsForDocumentReady, true, 'global-localization-starts-after-dom-content-loaded');
same($localizationLoadsSavedLanguageFirst, true, 'global-localization-loads-saved-language-first');
same($localizationScansEntireBody, true, 'global-localization-scans-the-entire-document-body');
same($localizationTranslatesPlaceholders, true, 'global-localization-translates-placeholder-attributes');
same($localizationIgnoresStaleLanguageLoads, true, 'global-localization-does-not-apply-stale-language-loads');
same($workspaceTranslationTraceExists, true, 'workspace-translation-debug-trace-includes-language-and-endpoint');
same($dictionaryFetchDisablesCache && $dictionaryEndpointDisablesCache, true, 'dictionary-endpoint-and-fetch-bypass-stale-cache');
same($auditMarkup && $auditPresenterIsLoaded, true, 'audit-details-and-system-labels-use-localized-presenter');
same($allThemePagesLoadSwitcher, true, 'all-enterprise-pages-load-the-shared-theme-switcher');
same($themeScriptPersistsAndAppliesBothModes, true, 'theme-switcher-persists-and-applies-dark-and-light-modes');
same($themeSwitcherUsesModeIcons, true, 'theme-switcher-shows-moon-or-sun-icon-without-visible-label');
same($themeStylesSupportBothPalettes, true, 'shared-theme-styles-define-dark-and-vibrant-light-palettes');
same($syncBackHandlesResponsesSafely, true, 'sync-back-validates-endpoint-and-handles-ajax-errors');
same($syncSendsAuthenticatedJsonSafely, true, 'sync-back-sends-json-and-optional-bearer-token-over-verified-tls');

finish();
