<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/AuthManager.php';
require_once __DIR__ . '/src/ProjectAccess.php';

$connection = db();
$auth = new AuthManager($connection);
$currentUser = $auth->getCurrentUser();
if ($currentUser === null) {
    $auth->redirectToLogin((string)($_SERVER['REQUEST_URI'] ?? '/project_detail.php'));
}

$projectId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($projectId === false || $projectId === null || $projectId < 1) {
    header('Location: index.php', true, 303);
    exit;
}

$accessResult = queryParams(
    $connection,
    'SELECT p.id
     FROM projects p
     WHERE p.id = $1
       AND (p.created_by = $2 OR EXISTS (
           SELECT 1 FROM project_members pm
           WHERE pm.project_id = p.id AND pm.user_id = $2
       ))',
    [$projectId, (int)$currentUser['id']]
);
if (!pg_fetch_assoc($accessResult)) {
    http_response_code(404);
    header('Location: index.php?error=project_not_found', true, 303);
    exit;
}

$_GET['project_id'] = $projectId;
define('TMS_PROJECT_DETAIL', true);
require __DIR__ . '/index.php';
