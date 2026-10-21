<?php
require __DIR__ . '/../src/Input.php';
require __DIR__ . '/../src/PackFilter.php';
$packs = require __DIR__ . '/../data/packs.php';
$query = field($_GET, 'q');
$platform = field($_GET, 'platform');
$incompleteOnly = field($_GET, 'incomplete') === '1';
$visible = filterPacks($packs, $query, $platform, $incompleteOnly);
$platforms = array_values(array_unique(array_column($packs, 'platform')));
require __DIR__ . '/../templates/header.php';
?>
<div class="page-heading page-heading--list page-search">
<h1>Gói ngôn ngữ</h1>
<p>Tìm gói theo tên, nền tảng và tiến độ dịch để quyết định gói nào có thể phát hành.</p>
</div>
<form class="search-form" method="get">
    <label class="form-field" for="q">Từ khóa
<input id="q" name="q" value="<?= e($query) ?>">
    </label>
    <label class="form-field" for="platform">Nền tảng
<select id="platform" name="platform">
            <option value="">Tất cả</option>
            <?php foreach ($platforms as $p): ?>
                <option value="<?= e($p) ?>"<?= $p === $platform ? ' selected' : '' ?>><?= e($p) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="filter-checkbox" for="incomplete">
<input type="checkbox" id="incomplete" name="incomplete" value="1"<?= $incompleteOnly ? ' checked' : '' ?>>
        Chỉ gói chưa dịch xong
    </label>
    <button>Tìm</button>
</form>
<p class="result-count">Kết quả: <?= count($visible) ?></p>
<section class="event-list" aria-label="Danh sách gói ngôn ngữ">
<?php foreach ($visible as $pack): ?>
    <article class="event-item event-card">
<div class="event-card-content">
        <h2 class="event-title"><?= e($pack['title']) ?></h2>
<p class="event-meta">
        Ngôn ngữ: <?= e($pack['language']) ?>. Nền tảng: <?= e($pack['platform']) ?>.
        Đã dịch <?= (int)$pack['translated'] ?>/<?= (int)$pack['total'] ?>
        chuỗi (<?= progressPercent($pack) ?>%), còn thiếu <?= missingStrings($pack) ?> chuỗi.
    </p>
<p class="event-status">
        <?= missingStrings($pack) === 0 ? 'Đủ điều kiện phát hành' : 'Chưa đủ điều kiện phát hành' ?>
    </p>
</div>
</article>
<?php endforeach; ?>
</section>
<?php if (!$visible): ?>
    <p class="empty-state">Không có kết quả.<span>Thử tìm với một từ khóa khác.</span></p>
<?php endif; ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
