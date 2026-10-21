<?php
declare(strict_types=1);

final class RegistrationPolicy
{
    public function decide(
        int $capacity,
        int $registered,
        bool $eventOpen,
        bool $waitlistEnabled
    ): string {
        // Sự kiện đóng thì từ chối đăng ký
        if (!$eventOpen) {
            return 'rejected';
        }

        // Kiểm tra dữ liệu không hợp lệ
        if ($capacity <= 0 || $registered < 0 || $registered > $capacity) {
            return 'rejected';
        }

        // Còn chỗ thì xác nhận đăng ký
        if ($registered < $capacity) {
            return 'confirmed';
        }

        // Hết chỗ nhưng cho phép danh sách chờ
        if ($waitlistEnabled) {
            return 'waiting';
        }

        // Hết chỗ và không cho phép danh sách chờ
        return 'rejected';
    }
}
