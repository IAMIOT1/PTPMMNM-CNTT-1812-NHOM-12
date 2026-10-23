<?php if ($currentUser): ?>
<details class="tms-notification-dropdown">
    <summary class="tms-notification-button" aria-label="Thông báo, <?= (int)$unreadNotificationCount ?> chưa đọc">
        <svg class="tms-bell-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none">
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <?php if ($unreadNotificationCount > 0): ?>
            <span class="tms-notification-count"><?= $unreadNotificationCount > 99 ? '99+' : $unreadNotificationCount ?></span>
        <?php endif; ?>
    </summary>
    <div class="tms-notification-menu">
        <div class="tms-notification-title">
            <span>Thông báo</span>
            <span class="tms-notification-unread"><?= (int)$unreadNotificationCount ?> chưa đọc</span>
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
                                    <button type="submit" class="tms-notification-mark-read">Đánh dấu đã đọc</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="tms-notification-empty">Bạn chưa có thông báo nào.</p>
        <?php endif; ?>
    </div>
</details>
<details class="tms-profile-dropdown">
    <summary class="tms-avatar-button" aria-label="Mở menu người dùng" title="<?= htmlspecialchars($currentUser['username'], ENT_QUOTES, 'UTF-8') ?>">
        <span class="tms-avatar" aria-hidden="true"><?= htmlspecialchars(strtoupper(substr((string)$currentUser['username'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span>
        <span class="tms-avatar-caret" aria-hidden="true"></span>
    </summary>
    <div class="tms-profile-menu">
        <div class="tms-profile-heading">
            <span class="tms-profile-name"><?= htmlspecialchars($currentUser['username'], ENT_QUOTES, 'UTF-8') ?></span>
            <span class="tms-profile-id">ID: <?= (int)$currentUser['id'] ?></span>
        </div>
        <div class="tms-profile-divider" role="separator"></div>
        <a class="tms-profile-menu-link" href="profile.php">
            <span class="tms-profile-menu-icon" aria-hidden="true">◉</span>
            <span>Trang cá nhân</span>
        </a>
        <a class="tms-profile-menu-link tms-profile-logout" href="index.php?action=logout">
            <span class="tms-profile-menu-icon" aria-hidden="true">↪</span>
            <span>Đăng xuất</span>
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
<?php else: ?>
<nav class="flex items-center gap-3">
    <a href="login.php" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Đăng nhập</a>
    <a href="register.php" class="rounded-lg border border-indigo-600 px-4 py-2 text-sm font-semibold text-indigo-600 hover:bg-indigo-50">Đăng ký</a>
</nav>
<?php endif; ?>
