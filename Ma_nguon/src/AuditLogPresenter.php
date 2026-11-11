<?php
declare(strict_types=1);

final class AuditLogPresenter {
    public static function describe(string $actionType, string $details): array {
        preg_match('/User \[(.*?)\]/', $details, $userMatch);
        $username = $userMatch[1] ?? '';

        return match ($actionType) {
            'IMPORT_SOURCE' => self::withMatch(
                'audit.source_imported',
                ['username' => $username],
                '/import phiên bản \[(.*?)\]/',
                $details,
                'version'
            ),
            'BULK_UPDATE_TRANSLATIONS' => self::describeBulkUpdate($details, $username),
            'UPDATE_TRANSLATION' => self::withMatch(
                'audit.translation_updated',
                ['username' => $username],
                '/bản dịch \[(.*?)\]/',
                $details,
                'locale'
            ),
            'AI_TRANSLATE' => self::describeAiTranslation($details, $username),
            'FREEZE_SOURCE_VERSION' => self::withMatch(
                'audit.version_frozen',
                ['username' => $username],
                '/chốt phiên bản \[(.*?)\]/',
                $details,
                'version'
            ),
            'EXPORT_RELEASE' => self::describeReleaseExport($details, $username),
            'SYNC_APPROVED_TRANSLATIONS' => self::describeSync($details, $username),
            'EXPORT_TRANSLATION_PACKAGE' => self::describePackageExport($details, $username),
            'ADD_GLOSSARY', 'UPDATE_GLOSSARY', 'DELETE_GLOSSARY' => self::describeGlossaryChange(
                $actionType,
                $details,
                $username
            ),
            default => ['key' => 'audit.generic_action', 'params' => ['action' => $actionType]],
        };
    }

    private static function withMatch(
        string $key,
        array $params,
        string $pattern,
        string $details,
        string $parameter
    ): array {
        preg_match($pattern, $details, $match);
        $params[$parameter] = $match[1] ?? '';
        return ['key' => $key, 'params' => $params];
    }

    private static function describeAiTranslation(string $details, string $username): array {
        preg_match('/tự động dịch (\d+) chuỗi \[(.*?)\]/', $details, $match);
        return [
            'key' => 'audit.ai_translated',
            'params' => [
                'username' => $username,
                'count' => $match[1] ?? '0',
                'locale' => $match[2] ?? '',
            ],
        ];
    }

    private static function describeBulkUpdate(string $details, string $username): array {
        $count = preg_match_all('/\bkey \d+:/', $details);
        if ($count === 0 && preg_match('/cập nhật \[(\d+)\] bản ghi/', $details, $countMatch)) {
            $count = (int)$countMatch[1];
        }
        preg_match('/cập nhật hàng loạt \[(.*?)\]/', $details, $localeMatch);
        return [
            'key' => 'audit.bulk_updated',
            'params' => [
                'username' => $username,
                'count' => (string)$count,
                'locale' => $localeMatch[1] ?? '',
            ],
        ];
    }

    private static function describeReleaseExport(string $details, string $username): array {
        preg_match('/xuất bản gói \[(.*?)\] v([^\s]+)/', $details, $match);
        return [
            'key' => 'audit.release_exported',
            'params' => [
                'username' => $username,
                'locale' => $match[1] ?? '',
                'version' => $match[2] ?? '',
            ],
        ];
    }

    private static function describeSync(string $details, string $username): array {
        preg_match('/đã duyệt \[(.*?)\] bằng ([A-Z]+)/', $details, $match);
        return [
            'key' => 'audit.translations_synced',
            'params' => [
                'username' => $username,
                'locale' => $match[1] ?? '',
                'method' => $match[2] ?? '',
            ],
        ];
    }

    private static function describePackageExport(string $details, string $username): array {
        preg_match('/locale=([^;]+); format=([^;]+);/', $details, $match);
        return [
            'key' => 'audit.package_exported',
            'params' => [
                'username' => $username,
                'locale' => $match[1] ?? '',
                'format' => $match[2] ?? '',
            ],
        ];
    }

    private static function describeGlossaryChange(string $actionType, string $details, string $username): array {
        $pattern = $actionType === 'DELETE_GLOSSARY'
            ? '/locale \[(.*?)\]/'
            : '/thuật ngữ \[(.*?)\]/';
        preg_match($pattern, $details, $match);
        return [
            'key' => match ($actionType) {
                'ADD_GLOSSARY' => 'audit.glossary_added',
                'UPDATE_GLOSSARY' => 'audit.glossary_updated',
                default => 'audit.glossary_deleted',
            },
            'params' => [
                'username' => $username,
                'locale' => $match[1] ?? '',
            ],
        ];
    }
}
