<?php
declare(strict_types=1);

require_once __DIR__ . '/TmRepository.php';

final class SuggestionEngine
{
    public function __construct(private $connection)
    {
    }

    /**
     * @return array{source:string,suggestion:string,tier:string,confidence:float,details:string}
     */
    public function suggest(int $projectId, string $locale, string $sourceText): array
    {
        $sourceText = trim($sourceText);
        if ($projectId < 1 || $locale === '' || $sourceText === '') {
            throw new InvalidArgumentException('SUGGESTION_INVALID_INPUT');
        }

        $exactResult = queryParams(
            $this->connection,
            'SELECT translated_text
             FROM translation_memory
             WHERE locale = $1 AND source_text = $2
               AND (project_id = $3 OR project_id IS NULL)
             ORDER BY CASE WHEN project_id = $3 THEN 0 ELSE 1 END, id DESC
             LIMIT 1',
            [$locale, $sourceText, $projectId]
        );
        $exact = pg_fetch_assoc($exactResult);
        if ($exact) {
            return $this->result($sourceText, (string)$exact['translated_text'], 'tm_exact', 1.0, 'Khớp 100% trong Translation Memory.');
        }
        $best = null;
        $bestScore = 0.0;
        try {
            $tmResult = queryParams(
                $this->connection,
                'SELECT source_text, translated_text, similarity(lower(source_text), lower($1)) AS score
                 FROM translation_memory
                 WHERE locale = $2 AND (project_id = $3 OR project_id IS NULL)
                   AND lower(source_text) % lower($1)
                 ORDER BY score DESC, CASE WHEN project_id = $3 THEN 0 ELSE 1 END
                 LIMIT 10',
                [$sourceText, $locale, $projectId]
            );
            while ($row = pg_fetch_assoc($tmResult)) {
                if ((float)$row['score'] > $bestScore) {
                    $bestScore = (float)$row['score'];
                    $best = (string)$row['translated_text'];
                }
            }
        } catch (Throwable $exception) {
            error_log('TM_FUZZY_QUERY_FALLBACK: ' . $exception->getMessage());
            $tmResult = queryParams(
                $this->connection,
                'SELECT source_text, translated_text
                 FROM translation_memory
                 WHERE locale = $1 AND (project_id = $2 OR project_id IS NULL)
                 ORDER BY CASE WHEN project_id = $2 THEN 0 ELSE 1 END, id DESC
                 LIMIT 100',
                [$locale, $projectId]
            );
            while ($row = pg_fetch_assoc($tmResult)) {
                similar_text(mb_strtolower($sourceText), mb_strtolower((string)$row['source_text']), $score);
                if ($score / 100 > $bestScore) {
                    $bestScore = $score / 100;
                    $best = (string)$row['translated_text'];
                }
            }
        }
        $repository = new TmRepository($this->connection, dirname(__DIR__, 2) . '/storage/tm-repository');
        foreach ($repository->findSuggestions($locale, $sourceText, 50) as $row) {
            if ((float)$row['score'] > $bestScore) {
                $bestScore = (float)$row['score'];
                $best = (string)$row['translated_text'];
            }
            if ((float)$row['score'] === 1.0) {
                return $this->result(
                    $sourceText,
                    (string)$row['translated_text'],
                    'tm_repository_exact',
                    1.0,
                    'Khớp 100% trong file CSV của TM Repository.'
                );
            }
        }
        if ($best !== null && $bestScore >= 0.78) {
            return $this->result($sourceText, $best, 'tm_fuzzy', round($bestScore, 2), 'Khớp gần đúng trong Translation Memory/CSV Repository.');
        }

        $suggestion = $sourceText;
        $termCount = 0;
        $glossaryResult = queryParams(
            $this->connection,
            'SELECT term, translation
             FROM glossary
             WHERE locale = $1 AND (project_id = $2 OR project_id IS NULL)
             ORDER BY length(term) DESC
             LIMIT 500',
            [$locale, $projectId]
        );
        while ($term = pg_fetch_assoc($glossaryResult)) {
            $pattern = '/(?<![\p{L}\p{N}_])' . preg_quote((string)$term['term'], '/') . '(?![\p{L}\p{N}_])/iu';
            $updated = preg_replace($pattern, (string)$term['translation'], $suggestion, -1, $count);
            if ($updated !== null && $count > 0) {
                $suggestion = $updated;
                $termCount += $count;
            }
        }
        if ($termCount > 0) {
            return $this->result($sourceText, $suggestion, 'glossary', 0.65, "Áp dụng {$termCount} thuật ngữ từ glossary.");
        }

        return $this->result(
            $sourceText,
            $this->fallback($sourceText, $locale),
            'local_fallback',
            0.2,
            'Fallback cục bộ: giữ nguyên câu và áp dụng từ điển cơ bản nếu có.'
        );
    }

    private function result(string $source, string $suggestion, string $tier, float $confidence, string $details): array
    {
        return compact('source', 'suggestion', 'tier', 'confidence', 'details');
    }

    private function fallback(string $text, string $locale): string
    {
        if ($locale !== 'vi') {
            return $text;
        }
        $dictionary = [
            'Save' => 'Lưu',
            'Cancel' => 'Hủy',
            'Delete' => 'Xóa',
            'Settings' => 'Cài đặt',
            'Search' => 'Tìm kiếm',
            'Next' => 'Tiếp theo',
            'Previous' => 'Trước',
        ];
        foreach ($dictionary as $source => $translation) {
            $text = preg_replace('/\b' . preg_quote($source, '/') . '\b/i', $translation, $text) ?? $text;
        }
        return $text;
    }
}
