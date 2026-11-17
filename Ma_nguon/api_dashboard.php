<?php
declare(strict_types=1);

ob_start();
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$apiResponseSent = false;

$respond = static function (int $status, array $body) use (&$apiResponseSent): never {
    $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    $apiResponseSent = true;
    while (ob_get_level() > 0) {
        if (!ob_end_clean()) {
            break;
        }
    }
    header_remove('Location');
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo $json;
    exit;
};

register_shutdown_function(static function () use (&$apiResponseSent): void {
    if ($apiResponseSent) {
        return;
    }
    $error = error_get_last();
    if (!is_array($error) || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    error_log(sprintf('DASHBOARD_API_FATAL: %s in %s:%d', $error['message'], $error['file'], $error['line']));
    $apiResponseSent = true;
    while (ob_get_level() > 0) {
        if (!ob_end_clean()) {
            break;
        }
    }
    header_remove('Location');
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo '{"error":"Internal Server Error"}';
});

try {
    require_once __DIR__ . '/src/Database.php';
    require_once __DIR__ . '/src/AuthManager.php';
    require_once __DIR__ . '/src/Csrf.php';
    require_once __DIR__ . '/src/DashboardApiService.php';

    $connection = db();
    $auth = new AuthManager($connection);
    $user = $auth->getCurrentUser();
    if ($user === null) {
        $respond(401, ['error' => 'Unauthorized']);
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
    if ($exception->statusCode === 401) {
        $respond(401, ['error' => 'Unauthorized']);
    }
    $respond($exception->statusCode, ['success' => false, 'message' => $exception->getMessage()]);
} catch (JsonException) {
    $respond(400, ['success' => false, 'message' => 'Request body contains invalid JSON.']);
} catch (InvalidArgumentException $exception) {
    $respond(422, ['success' => false, 'message' => $exception->getMessage()]);
} catch (Throwable $exception) {
    error_log('DASHBOARD_API_FAILED: ' . $exception->getMessage());
    $respond(500, [
        'error' => 'Internal Server Error',
        'success' => false,
        'message' => 'The dashboard request could not be completed.',
    ]);
}
