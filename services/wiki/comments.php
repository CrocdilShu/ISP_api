<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/providerauth.php';
require_once __DIR__ . '/../common/PageModule.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$sessionKey = getCompanySessionKey($conn);
$providerAccountId = null;

$hasWikiAccess = hasServiceAccess($sessionKey, SERVICE_WIKI, $providerAccountId);
if (!$hasWikiAccess) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No rights to access the wiki service (invalid company session key)']);
    exit;
}

$wiki = new PageModule($conn, 'wiki');
$denialReason = null;
$actingUser = $wiki->resolveRequestUser($denialReason);

if (!$actingUser) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => $denialReason ?? 'Unauthorized: valid X-User-Token required']);
    exit;
}
$userId = (int)$actingUser['id'];

function requireViewableWikiPage(PageModule $wiki, int $pageId, int $userId): array {
    $page = $wiki->getPage($pageId);
    if (!$page) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Page not found']);
        exit;
    }
    if (!$wiki->canViewPage($page, $userId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No rights to view or comment on pages in this private wiki']);
        exit;
    }
    return $page;
}

// 1. GET: List all comments for a page (?page_id=13&sort=ASC)
if ($method === 'GET') {
    $pageId = isset($_GET['page_id']) ? (int)$_GET['page_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
    $sort = strtoupper(trim((string)($_GET['sort'] ?? 'ASC')));

    if ($pageId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid input: page_id is required']);
        exit;
    }

    requireViewableWikiPage($wiki, $pageId, $userId);
    $comments = $wiki->getComments($pageId, $sort);

    echo json_encode([
        'success' => true,
        'message' => 'Comments listed',
        'page_id' => $pageId,
        'count' => count($comments),
        'comments' => $comments
    ]);
    exit;
}

// 2. POST: Create a comment
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    $pageId = isset($_GET['page_id']) ? (int)$_GET['page_id'] : (int)($input['page_id'] ?? 0);

    if ($pageId <= 0 || !isset($input['content']) || trim($wiki->normalizePlainText($input['content'])) === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid input: page_id and non-empty text content are required']);
        exit;
    }

    requireViewableWikiPage($wiki, $pageId, $userId);

    try {
        $comment = $wiki->addComment($pageId, $userId, $input['content']);
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Comment created',
            'comment' => $comment
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Could not create comment']);
    }
    exit;
}

// 3. PUT: Edit a comment (?id=X)
if ($method === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    $commentId = isset($_GET['id']) ? (int)$_GET['id'] : (int)($input['comment_id'] ?? ($input['id'] ?? 0));

    if ($commentId <= 0 || !isset($input['content'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid input: comment id and content are required']);
        exit;
    }

    if (!$wiki->canModifyComment($commentId, $userId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No rights to edit this comment (requires comment author, wiki owner, co_owner, or admin)']);
        exit;
    }

    $newText = $wiki->updateComment($commentId, $input['content']);
    echo json_encode([
        'success' => true,
        'message' => 'Comment updated',
        'comment_id' => $commentId,
        'content' => $newText
    ]);
    exit;
}

// 4. DELETE: Delete a comment (?id=X)
if ($method === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    $commentId = isset($_GET['id']) ? (int)$_GET['id'] : (int)($input['comment_id'] ?? ($input['id'] ?? 0));

    if ($commentId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid input: comment id is required']);
        exit;
    }

    if (!$wiki->canModifyComment($commentId, $userId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No rights to delete this comment (requires comment author, wiki owner, co_owner, or admin)']);
        exit;
    }

    $wiki->deleteComment($commentId);
    echo json_encode([
        'success' => true,
        'message' => 'Comment deleted',
        'comment_id' => $commentId
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);