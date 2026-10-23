<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ProjectAccess.php';
require_once __DIR__ . '/NotificationManager.php';

final class ReviewManager {
    public function __construct(private PgSql\Connection $connection) {}

    /**
     * Thêm hoặc cập nhật trạng thái kiểm duyệt cho một bản dịch (approved, rejected, pending)
     */
    public function submitReview(int $translationId, int $reviewerId, string $status, string $comment = ''): int {
        $allowedStatus = ['approved', 'rejected', 'pending', 'needs_review'];
        if (!in_array($status, $allowedStatus, true)) {
            throw new InvalidArgumentException("Trạng thái kiểm duyệt không hợp lệ: {$status}");
        }

        $contextRes = queryParams(
            $this->connection,
            'SELECT sv.project_id, u.id, u.role
             FROM translations t
             JOIN strings s ON s.id = t.string_id
             JOIN source_versions sv ON sv.id = s.source_version_id
             JOIN users u ON u.id = $2
             WHERE t.id = $1',
            [$translationId, $reviewerId]
        );
        $context = pg_fetch_assoc($contextRes);
        if (!$context) {
            throw new RuntimeException('REVIEW_TARGET_OR_USER_NOT_FOUND');
        }
        if (!ProjectAccess::canReview(
            $this->connection,
            (int)$context['project_id'],
            ['id' => (int)$context['id'], 'role' => $context['role']]
        )) {
            throw new DomainException('PROJECT_REVIEW_PERMISSION_DENIED');
        }

        // 1. Lưu lịch sử review vào bảng reviews
        $sqlReview = 'INSERT INTO reviews (translation_id, reviewer_id, status, comment) VALUES ($1, $2, $3, $4) RETURNING id';
        $res = queryParams($this->connection, $sqlReview, [$translationId, $reviewerId, $status, $comment]);
        $row = pg_fetch_assoc($res);
        $reviewId = (int)$row['id'];

        $ownerRes = queryParams(
            $this->connection,
            'SELECT user_id, status FROM translations WHERE id = $1',
            [$translationId]
        );
        $translationOwner = pg_fetch_assoc($ownerRes);

        // 2. Cập nhật trạng thái tương ứng trong bảng translations
        $sqlTranslation = 'UPDATE translations SET status = $1, updated_at = clock_timestamp() WHERE id = $2';
        queryParams($this->connection, $sqlTranslation, [$status, $translationId]);
        if (
            $translationOwner &&
            $translationOwner['user_id'] !== null &&
            (int)$translationOwner['user_id'] !== $reviewerId &&
            $translationOwner['status'] !== $status &&
            in_array($status, ['approved', 'needs_review', 'rejected'], true)
        ) {
            NotificationManager::create(
                $this->connection,
                (int)$translationOwner['user_id'],
                $status === 'approved'
                    ? 'Bản dịch của bạn đã được duyệt.'
                    : 'Bản dịch của bạn cần được xem xét và chỉnh sửa.'
            );
        }

        return $reviewId;
    }
}
