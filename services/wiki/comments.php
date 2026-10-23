<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/providerauth.php';
require_once __DIR__ . '/../common/PageModule.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$sessionKey = getCompanySessionKey($conn);
$providerAccountId = null;

$hasWikiAccess = hasServiceAccess($sessionKey, SERVICE_WIKI, $providerAccountId);

$wiki = new PageModule($conn, 'wiki');
$userId = $hasWikiAccess ? $wiki->resolveRequestUser() : null;

if ((!$hasWikiAccess) or (!$userId)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

function requireViewablePage(PageModule $wiki, int $pageId, int $userId): array {
    $page = $wiki->getPage($pageId);
    if (!$page) {
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
        exit;
    }
    if (!$wiki->canViewPage($page, $userId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    return $page;
}

// 1. GET: Hämta alla kommentarer för en wiki-sida (?page_id=13&sort=ASC)
if ($method === 'GET') {
    $pageId = isset($_GET['page_id']) ? (int)$_GET['page_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
    $sort = strtoupper(trim((string)($_GET['sort'] ?? 'ASC')));

    if (!$pageId) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input']);
        exit;
    }

    requireViewablePage($wiki, $pageId, (int)$userId);
    $comments = $wiki->getComments($pageId, $sort);

    echo json_encode([
        'success' => true,
        'page_id' => $pageId,
        'count' => count($comments),
        'comments' => $comments
    ]);
    exit;
}

// 2. POST: Skapa en ny kommentar
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    $pageId = isset($_GET['page_id']) ? (int)$_GET['page_id'] : (int)($input['page_id'] ?? 0);

    if ((!$pageId) or (!isset($input['content']))) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input']);
        exit;
    }

    requireViewablePage($wiki, $pageId, (int)$userId);

    try {
        $commentId = $wiki->addComment($pageId, (int)$userId, $input['content']);
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Comment created',
            'comment_id' => $commentId,
            'page_id' => $pageId
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Request could not be completed']);
    }
    exit;
}

// 3. PUT: Redigera en kommentar (?id=X)
if ($method === 'PUT') {
    $commentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }

    if ((!$commentId) or (!isset($input['content']))) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input']);
        exit;
    }

    if (!$wiki->canModifyComment($commentId, (int)$userId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    try {
        $wiki->updateComment($commentId, $input['content']);
        echo json_encode([
            'success' => true,
            'message' => 'Comment updated',
            'comment_id' => $commentId
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Request could not be completed']);
    }
    exit;
}

// 4. DELETE: Ta bort en kommentar (?id=X)
if ($method === 'DELETE') {
    $commentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if (!$commentId) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input']);
        exit;
    }

    if (!$wiki->canModifyComment($commentId, (int)$userId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    try {
        $wiki->deleteComment($commentId);
        echo json_encode([
            'success' => true,
            'message' => 'Comment deleted',
            'comment_id' => $commentId
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Request could not be completed']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
