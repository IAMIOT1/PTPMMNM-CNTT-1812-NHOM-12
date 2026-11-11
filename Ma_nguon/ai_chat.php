<?php
declare(strict_types=1);

ini_set('display_errors', '0');
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/AuthManager.php';
require_once __DIR__ . '/src/Csrf.php';
require_once __DIR__ . '/src/AiTranslator.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$requestLocale = ($_POST['locale'] ?? 'vi') === 'en' ? 'en' : 'vi';
$localizedMessage = static fn(string $vietnamese, string $english): string =>
    $requestLocale === 'en' ? $english : $vietnamese;
$respond = static function (array $payload, int $statusCode = 200): never {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    $respond(['success' => false, 'message' => 'Only POST requests are supported.'], 405);
}

try {
    $connection = db();
    $auth = new AuthManager($connection);
    $currentUser = $auth->getCurrentUser();
    if ($currentUser === null) {
        $respond([
            'success' => false,
            'message' => $localizedMessage('Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.', 'Your session has expired. Please sign in again.')
        ], 401);
    }
    if (!Csrf::isValid($_POST)) {
        $respond([
            'success' => false,
            'message' => $localizedMessage('Yêu cầu không hợp lệ. Hãy tải lại trang rồi thử lại.', 'The request could not be verified. Reload the page and try again.')
        ], 403);
    }

    $locale = $_POST['locale'] ?? 'vi';
    if (!is_string($locale) || !in_array($locale, ['vi', 'en'], true)) {
        $respond([
            'success' => false,
            'message' => $localizedMessage('Ngôn ngữ trò chuyện không hợp lệ.', 'The chat language is invalid.')
        ], 400);
    }
    $rawMessages = $_POST['messages'] ?? '';
    if (!is_string($rawMessages) || strlen($rawMessages) > 100000) {
        $respond([
            'success' => false,
            'message' => $localizedMessage('Nội dung hội thoại quá dài.', 'The conversation is too large.')
        ], 413);
    }
    try {
        $messages = json_decode($rawMessages, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        $respond([
            'success' => false,
            'message' => $localizedMessage('Định dạng hội thoại không hợp lệ.', 'The conversation format is invalid.')
        ], 400);
    }
    if (!is_array($messages) || !array_is_list($messages) || count($messages) < 1 || count($messages) > 10) {
        $respond([
            'success' => false,
            'message' => $localizedMessage('Mỗi lượt chỉ gửi tối đa 10 tin nhắn gần nhất.', 'Send up to ten recent messages at a time.')
        ], 400);
    }

    $now = time();
    $recentRequests = $_SESSION['ai_chat_requests'] ?? [];
    if (!is_array($recentRequests)) {
        $recentRequests = [];
    }
    $recentRequests = array_values(array_filter(
        $recentRequests,
        static fn($timestamp): bool => is_int($timestamp) && $timestamp > $now - 60
    ));
    if (count($recentRequests) >= 12) {
        $_SESSION['ai_chat_requests'] = $recentRequests;
        $respond([
            'success' => false,
            'message' => $localizedMessage('Bạn gửi quá nhiều tin nhắn. Vui lòng chờ một phút rồi thử lại.', 'Too many messages. Please wait a minute and try again.')
        ], 429);
    }
    $recentRequests[] = $now;
    $_SESSION['ai_chat_requests'] = $recentRequests;

    $reply = (new AiTranslator($connection))->chat($messages, $locale);
    $respond(['success' => true, 'reply' => $reply]);
} catch (InvalidArgumentException $exception) {
    $respond([
        'success' => false,
        'message' => $localizedMessage('Tin nhắn hoặc lịch sử hội thoại không hợp lệ.', 'The message or conversation is invalid.')
    ], 400);
} catch (Throwable $exception) {
    error_log('TMS_AI_CHAT_FAILED: ' . $exception->getMessage());
    $message = str_starts_with($exception->getMessage(), 'GEMINI_API_KEY_MISSING')
        ? $localizedMessage('Trợ lý AI chưa được cấu hình trên máy chủ.', 'AI chat is not configured on this server yet.')
        : $localizedMessage('Trợ lý AI hiện không thể phản hồi. Vui lòng thử lại sau.', 'The AI assistant could not respond right now. Please try again.');
    $respond(['success' => false, 'message' => $message], 502);
}
