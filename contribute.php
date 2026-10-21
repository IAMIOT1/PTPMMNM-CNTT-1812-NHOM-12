<?php
require __DIR__ . '/../src/Input.php';
$packs = require __DIR__ . '/../data/packs.php';
$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = validateContribution($_POST, $packs);
}
$selected = field($_POST, 'pack_id');
require __DIR__ . '/../templates/header.php';
?>
<div class="page-heading">
<h1>Gửi đề xuất bản dịch</h1>
<p>Điền thông tin để kiểm tra và xem trước. Chưa lưu bản dịch.</p>
</div>
<form class="registration-form" method="post">
    <label class="form-field" for="pack_id">Gói ngôn ngữ
<select id="pack_id" name="pack_id">
            <?php foreach ($packs as $pack): ?>
                <option value="<?= (int)$pack['id'] ?>"<?= (string)$pack['id'] === $selected ? ' selected' : '' ?>>
                    <?= e($pack['title']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="form-field" for="name">Người dịch
<input id="name" name="name" maxlength="120"
               value="<?= e(field($_POST, 'name')) ?>">
    </label>
    <label class="form-field" for="email">Email
<input id="email" name="email"
               value="<?= e(field($_POST, 'email')) ?>">
    </label>
    <label class="form-field" for="text">Bản dịch đề xuất
<textarea id="text" name="text" rows="4" maxlength="1000"><?= e(field($_POST, 'text')) ?></textarea>
    </label>
    <button>Kiểm tra</button>
</form>
<?php if ($result !== null): ?>
    <?php if ($result['errors']): ?>
        <div class="error-summary" role="alert">
<p class="error-messages"><?= e(implode(', ', $result['errors'])) ?></p>
<p>Thông tin chưa hợp lệ. Kiểm tra lại các thông tin đã nhập rồi gửi lại.</p>
</div>
    <?php else: ?>
        <section class="preview" role="status">
<h2>Xem trước đề xuất</h2>
<p class="status-message">PREVIEW_OK: <?= e($result['data']['name']) ?>
            — <?= e($result['data']['email']) ?></p>
        <p>Bản dịch: <?= e($result['data']['text']) ?></p>
        <p>Chỉ xem trước; chưa lưu Session hoặc CSDL, chưa đưa vào gói phát hành.</p>
</section>
    <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
