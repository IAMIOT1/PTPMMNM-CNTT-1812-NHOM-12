<?php
require_once __DIR__ . '/../src/ProjectAccess.php';
if ($currentUser):
?>
<?php require __DIR__ . '/theme-toggle.php'; ?>
<a class="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
   href="tm_repository.php" data-i18n="menu.tm_repository">Kho TM</a>
<?php if (ProjectAccess::isSystemAdmin($currentUser)): ?>
    <a class="inline-flex items-center gap-2 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm font-semibold text-indigo-800 hover:bg-indigo-100"
       href="admin_users.php">
        <span aria-hidden="true">🛡️</span>
        <span data-i18n="menu.system_admin">Quản trị hệ thống</span>
    </a>
<?php endif; ?>
<details class="tms-notification-dropdown">
    <summary class="tms-notification-button" aria-label="Thông báo, <?= (int)$unreadNotificationCount ?> chưa đọc" data-i18n-aria-label="menu.notifications_accessible" data-i18n-aria-label-count="<?= (int)$unreadNotificationCount ?>">
        <svg class="tms-bell-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none">
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <?php if ($unreadNotificationCount > 0): ?>
            <span class="tms-notification-count"><?= $unreadNotificationCount > 99 ? '99+' : $unreadNotificationCount ?></span>
        <?php endif; ?>
    </summary>
    <div class="tms-notification-menu">
        <div class="tms-notification-title">
            <span data-i18n="menu.notifications_short">Thông báo</span>
            <span class="tms-notification-unread"><span><?= (int)$unreadNotificationCount ?></span> <span data-i18n="menu.unread">chưa đọc</span></span>
        </div>
        <div class="tms-profile-divider" role="separator"></div>
        <?php if ($notifications): ?>
            <div class="tms-notification-list">
                <?php foreach ($notifications as $notification): ?>
                    <div class="tms-notification-item <?= $notification['is_read'] === 't' ? '' : 'is-unread' ?>">
                        <span class="tms-notification-dot" aria-hidden="true"></span>
                        <div class="tms-notification-content">
                            <p><?= htmlspecialchars($notification['message'], ENT_QUOTES, 'UTF-8') ?></p>
                            <time><?= htmlspecialchars($notification['created_at'], ENT_QUOTES, 'UTF-8') ?></time>
                            <?php if ($notification['is_read'] !== 't'): ?>
                                <form method="post" action="notifications.php">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="notification_id" value="<?= (int)$notification['id'] ?>">
                                    <input type="hidden" name="return_to" value="<?= htmlspecialchars((string)($_SERVER['REQUEST_URI'] ?? '/index.php'), ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="tms-notification-mark-read" data-i18n="menu.mark_read">Đánh dấu đã đọc</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="tms-notification-empty" data-i18n="menu.no_notifications">Bạn chưa có thông báo nào.</p>
        <?php endif; ?>
    </div>
</details>
<details class="tms-profile-dropdown">
    <summary class="tms-avatar-button" aria-label="Mở menu người dùng" data-i18n-aria-label="menu.open_account" title="<?= htmlspecialchars($currentUser['username'], ENT_QUOTES, 'UTF-8') ?>">
        <span class="tms-avatar" aria-hidden="true"><?= htmlspecialchars(strtoupper(substr((string)$currentUser['username'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span>
        <span class="tms-avatar-caret" aria-hidden="true"></span>
    </summary>
    <div class="tms-profile-menu">
        <div class="tms-profile-heading">
            <span class="tms-profile-avatar" aria-hidden="true"><?= htmlspecialchars(strtoupper(substr((string)$currentUser['username'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span>
            <span class="tms-profile-name"><?= htmlspecialchars($currentUser['username'], ENT_QUOTES, 'UTF-8') ?></span>
            <span class="tms-profile-id"><span data-i18n="menu.identity_id">ID định danh</span>: <?= (int)$currentUser['id'] ?></span>
            <span class="tms-profile-email"><?= htmlspecialchars((string)($currentUser['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="tms-profile-divider" role="separator"></div>
        <a class="tms-profile-menu-link" href="profile.php#account-settings">
            <span class="tms-profile-menu-icon" aria-hidden="true"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="10" cy="6.25" r="3"/><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 16.25a5.5 5.5 0 0 1 11 0"/></svg></span>
            <span data-i18n="menu.account_settings">Cài đặt tài khoản</span>
        </a>
        <a class="tms-profile-menu-link" href="profile.php#change-password">
            <span class="tms-profile-menu-icon" aria-hidden="true"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3.25" y="8.25" width="13.5" height="9" rx="1.75"/><path stroke-linecap="round" stroke-linejoin="round" d="M6.25 8.25V5.75a3.75 3.75 0 0 1 7.5 0v2.5m-3.75 3.25v2.5"/></svg></span>
            <span data-i18n="menu.change_password">Đổi mật khẩu</span>
        </a>
        <a class="tms-profile-menu-link" href="profile.php#recent-activity">
            <span class="tms-profile-menu-icon" aria-hidden="true"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M3.5 10a6.5 6.5 0 1 0 1.7-4.4L3.5 7.5"/><path stroke-linecap="round" stroke-linejoin="round" d="M3.5 4.5v3h3m3.5-1v4l2.5 1.5"/></svg></span>
            <span data-i18n="menu.activity">Nhật ký hoạt động</span>
        </a>
        <a class="tms-profile-menu-link" href="profile.php#notification-settings">
            <span class="tms-profile-menu-icon" aria-hidden="true"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M10 2.75v1m0 12.5v1m7.25-7.25h-1m-12.5 0h-1m12.38-5.13-.71.71m-8.84 8.84-.71.71m10.26 0-.71-.71m-8.84-8.84-.71-.71"/><circle cx="10" cy="10" r="4.25"/></svg></span>
            <span data-i18n="menu.notifications">Cài đặt thông báo</span>
        </a>
        <a class="tms-profile-menu-link" href="profile.php#support-requests">
            <span class="tms-profile-menu-icon" aria-hidden="true"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="10" cy="10" r="7.25"/><path stroke-linecap="round" stroke-linejoin="round" d="M7.9 7.5a2.2 2.2 0 1 1 3.8 1.5c-.9.85-1.7 1.1-1.7 2.5m0 2.25h.01"/></svg></span>
            <span data-i18n="menu.support">Hỗ trợ &amp; Báo cáo sự cố</span>
        </a>
        <div class="tms-language-menu" aria-label="Ngôn ngữ">
            <div class="tms-language-menu-heading">
                <svg aria-hidden="true" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="10" cy="10" r="7.25"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.9 7.25h14.2M2.9 12.75h14.2M10 2.75c1.9 2 2.85 4.42 2.85 7.25S11.9 15.25 10 17.25C8.1 15.25 7.15 12.83 7.15 10S8.1 4.75 10 2.75Z"/></svg>
                <span data-i18n="menu.language">Ngôn ngữ / Language</span>
            </div>
            <div class="tms-language-choices">
                <button type="button" data-language-choice="vi" aria-pressed="false" data-i18n="language.vi">🇻🇳 Tiếng Việt</button>
                <button type="button" data-language-choice="en" aria-pressed="false" data-i18n="language.en">🇬🇧 English</button>
            </div>
        </div>
        <div class="tms-profile-divider tms-profile-logout-divider" role="separator"></div>
        <a class="tms-profile-menu-link tms-profile-logout" href="index.php?action=logout">
            <span class="tms-profile-menu-icon" aria-hidden="true"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M8 3.25H4.75A1.5 1.5 0 0 0 3.25 4.75v10.5a1.5 1.5 0 0 0 1.5 1.5H8m4-3 3.5-3.75L12 6.25m3.5 3.75H7.25"/></svg></span>
            <span data-i18n="menu.logout">Đăng xuất</span>
        </a>
    </div>
</details>
<script>
    (() => {
        const dropdowns = document.currentScript.parentElement.querySelectorAll('details');
        document.addEventListener('click', (event) => {
            dropdowns.forEach((dropdown) => {
                if (dropdown.open && !dropdown.contains(event.target)) dropdown.open = false;
            });
        });
        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;
            dropdowns.forEach((dropdown) => {
                if (dropdown.open) {
                    dropdown.open = false;
                    dropdown.querySelector('summary').focus();
                }
            });
        });
    })();
</script>
<script src="tms-i18n.js" defer></script>
<?php require __DIR__ . '/tms-ai-chat.php'; ?>
<?php else: ?>
<nav class="flex items-center gap-3">
    <?php require __DIR__ . '/theme-toggle.php'; ?>
    <a href="login.php" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Đăng nhập</a>
    <a href="register.php" class="rounded-lg border border-indigo-600 px-4 py-2 text-sm font-semibold text-indigo-600 hover:bg-indigo-50">Đăng ký</a>
</nav>
<?php endif; ?>
