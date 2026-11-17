<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/AuthManager.php';
require_once __DIR__ . '/src/Csrf.php';
require_once __DIR__ . '/src/DashboardApiService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$respond = static function (int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    exit;
};

try {
    $connection = db();
    $auth = new AuthManager($connection);
    $user = $auth->getCurrentUser();
    if ($user === null) {
        $respond(401, ['success' => false, 'message' => 'Your session has expired. Sign in again.']);
    }

    $service = new DashboardApiService($connection, $user);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        if (($_GET['resource'] ?? '') === 'support_requests') {
            $respond(200, ['success' => true, 'data' => $service->supportRequests()]);
        }
        $respond(200, ['success' => true, 'data' => $service->workspace(Csrf::token())]);
    }
    if ($method !== 'POST') {
        header('Allow: GET, POST');
        $respond(405, ['success' => false, 'message' => 'Method not allowed.']);
    }

    $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($contentType !== 'application/json') {
        $respond(415, ['success' => false, 'message' => 'Content-Type must be application/json.']);
    }
    $rawInput = file_get_contents('php://input');
    $payload = is_string($rawInput) && trim($rawInput) !== ''
        ? json_decode($rawInput, true, 512, JSON_THROW_ON_ERROR)
        : null;
    if (!is_array($payload) || array_is_list($payload)) {
        $respond(400, ['success' => false, 'message' => 'Request body must be a JSON object.']);
    }
    if (!Csrf::isValid($payload)) {
        $respond(403, ['success' => false, 'message' => 'The security token expired. Reload the workspace and try again.']);
    }
    $respond(200, ['success' => true, 'data' => $service->mutate($payload)]);
} catch (DashboardApiHttpException $exception) {
    $respond($exception->statusCode, ['success' => false, 'message' => $exception->getMessage()]);
} catch (JsonException) {
    $respond(400, ['success' => false, 'message' => 'Request body contains invalid JSON.']);
} catch (InvalidArgumentException $exception) {
    $respond(422, ['success' => false, 'message' => $exception->getMessage()]);
} catch (Throwable $exception) {
    error_log('DASHBOARD_API_FAILED: ' . $exception->getMessage());
    $respond(500, ['success' => false, 'message' => 'The dashboard request could not be completed.']);
}
