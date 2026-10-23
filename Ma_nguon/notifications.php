<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/AuthManager.php';
require_once __DIR__ . '/src/Csrf.php';

$connection = db();
$auth = new AuthManager($connection);
$user = $auth->getCurrentUser();
if (!$user) {
    $auth->redirectToLogin('/index.php');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}

Csrf::verify($_POST);
$notificationId = (int)($_POST['notification_id'] ?? 0);
if ($notificationId > 0) {
    queryParams(
        $connection,
        'UPDATE notifications SET is_read = TRUE WHERE id = $1 AND user_id = $2',
        [$notificationId, (int)$user['id']]
    );
}

$returnTo = AuthManager::safeReturnPath((string)($_POST['return_to'] ?? '/index.php'));
header('Location: ' . $returnTo, true, 303);
exit;
