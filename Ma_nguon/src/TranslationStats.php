<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

final class TranslationStats {
    public function __construct(private PgSql\Connection $connection) {}

    /**
     * Tính toán thống kê tiến độ dịch cho phiên bản nguồn và ngôn ngữ cụ thể
     */
    public function getProgress(int $projectId, int $sourceVersionId, string $locale): array {
        // 1. Tổng số chuỗi trong phiên bản nguồn
        $sqlTotal = "SELECT COUNT(*) AS total FROM strings WHERE project_id = $1 AND source_version_id = $2";
        $resTotal = queryParams($this->connection, $sqlTotal, [$projectId, $sourceVersionId]);
        $total = (int)pg_fetch_result($resTotal, 0, 'total');

        if ($total === 0) {
            return [
                'total' => 0,
                'translated' => 0,
                'approved' => 0,
                'percentage' => 0.0
            ];
        }

        // 2. Số lượng đã dịch và số lượng đã được phê duyệt
        $sqlTrans = "SELECT COUNT(t.id) AS translated_count, 
                            SUM(CASE WHEN t.status = 'approved' THEN 1 ELSE 0 END) AS approved_count
                     FROM strings s
                     LEFT JOIN translations t ON t.string_id = s.id AND t.locale = $3
                     WHERE s.project_id = $1 AND s.source_version_id = $2";
        
        $resTrans = queryParams($this->connection, $sqlTrans, [$projectId, $sourceVersionId, $locale]);
        $row = pg_fetch_assoc($resTrans);

        $translated = (int)$row['translated_count'];
        $approved = (int)$row['approved_count'];
        $percentage = round(($translated / $total) * 100, 2);

        return [
            'total' => $total,
            'translated' => $translated,
            'approved' => $approved,
            'percentage' => $percentage
        ];
    }
}
