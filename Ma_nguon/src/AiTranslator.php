<?php
declare(strict_types=1);

class AiTranslator {
    private $conn;
    private string $apiKey;

    public function __construct($conn, string $apiKey = '') {
        $this->conn = $conn;
        $this->apiKey = $apiKey ?: getenv('AI_API_KEY') ?: '';
    }

    public function autoTranslateMissing(int $versionId, string $targetLocale, int $userId): int {
        $sql = "SELECT s.id AS string_id, s.source_text 
                FROM strings s
                LEFT JOIN translations t ON t.string_id = s.id AND t.locale = $1
                WHERE s.source_version_id = $2 AND (t.translated_text IS NULL OR t.translated_text = '')";
        
        $res = queryParams($this->conn, $sql, [$targetLocale, $versionId]);
        $translatedCount = 0;

        if (pg_query($this->conn, 'BEGIN') === false) {
            throw new RuntimeException('AI_TRANSLATION_TRANSACTION_FAILED');
        }
        try {
            queryParams($this->conn, "SELECT set_config('tms.user_id', $1, TRUE)", [(string)$userId]);
            while ($row = pg_fetch_assoc($res)) {
                $stringId = (int)$row['string_id'];
                $sourceText = $row['source_text'];
                $translatedText = $this->translateWithAi($sourceText, $targetLocale);

                if ($translatedText !== '') {
                    queryParams(
                        $this->conn,
                        "INSERT INTO translations (string_id, locale, translated_text, status, user_id)
                         VALUES ($1, $2, $3, 'draft', $4)
                         ON CONFLICT (string_id, locale) 
                         DO UPDATE SET translated_text = EXCLUDED.translated_text,
                                       user_id = EXCLUDED.user_id,
                                       updated_at = clock_timestamp()",
                        [$stringId, $targetLocale, $translatedText, $userId]
                    );
                    $translatedCount++;
                }
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

    private function translateWithAi(string $text, string $targetLocale): string {
        $localeNames = [
            'vi' => 'Đã dịch AI (vi): ',
            'en' => 'AI Trans (en): ',
            'ja' => 'AI翻訳 (ja): '
        ];
        $prefix = $localeNames[$targetLocale] ?? "AI ({$targetLocale}): ";
        return $prefix . $text;
    }
}
