<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/providerauth.php';
require_once __DIR__ . '/../common/PageModule.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$sessionKey = getRequestSessionKey();
$providerAccountId = null;

$hasWikiAccess = hasServiceAccess($sessionKey, SERVICE_WIKI, $providerAccountId);

$wiki = new PageModule($conn, 'wiki');
$userId = $hasWikiAccess ? $wiki->resolveRequestUser() : null;
$viewerId = $userId ? (int)$userId : null;

// 1. GET: Hämta wikis (?type=wiki), en specifik sida (?id=X) eller sök/lista sidor (?search=X&sort=ASC&wiki_id=Y)
if ($method === 'GET') {
    $type = strtolower(trim((string)($_GET['type'] ?? 'page')));
    $search = trim((string)($_GET['search'] ?? ''));
    $sort = strtoupper(trim((string)($_GET['sort'] ?? 'DESC')));

    if ($type === 'wiki') {
        $wikis = $wiki->listWikiSpaces($viewerId, $search, $sort);
        echo json_encode(['success' => true, 'count' => count($wikis), 'wikis' => $wikis]);
        exit;
    }

    $pageId = isset($_GET['id']) ? (int)$_GET['id'] : null;
    $parentWikiId = isset($_GET['wiki_id']) ? (int)$_GET['wiki_id'] : null;

    if ($pageId) {
        $data = $wiki->getPageForViewer($pageId, $viewerId);
        if (!$data) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            exit;
        }
        if (isset($data['error']) && $data['error'] === 'FORBIDDEN') {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }
        echo json_encode(['success' => true, 'page' => $data]);
        exit;
    }

    $pages = $wiki->listPages($viewerId, $parentWikiId, $search, $sort);
    echo json_encode(['success' => true, 'count' => count($pages), 'pages' => $pages]);
    exit;
}

// Skrivoperationer kräver aktiv Wiki-tjänst + giltig X-User-Token
if ((!$hasWikiAccess) or (!$userId)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// 2. POST: Skapa en ny överordnad Wiki (?type=wiki) eller en Wiki-sida
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }

    $type = strtolower(trim((string)($_GET['type'] ?? ($input['type'] ?? 'page'))));
    $visibility = $wiki->normalizeVisibility($input['visibility'] ?? 'public');

    if ($visibility === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input']);
        exit;
    }

    if ($type === 'wiki') {
        $titel = trim((string)($input['titel'] ?? ''));
        $description = isset($input['description']) ? trim((string)$input['description']) : null;
        if ($titel === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid input']);
            exit;
        }
        try {
            $newWikiId = $wiki->createWikiSpace($titel, $description, (int)$userId, $visibility);
            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Wiki created',
                'wiki_id' => $newWikiId,
                'owner_id' => (int)$userId
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Request could not be completed']);
        }
        exit;
    }

    if (!isset($input['titel']) || !is_string($input['titel']) || trim($input['titel']) === '' || !isset($input['content'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input']);
        exit;
    }

    $parentWikiId = isset($input['wiki_id']) ? (int)$input['wiki_id'] : (isset($_GET['wiki_id']) ? (int)$_GET['wiki_id'] : null);
    $pictures = is_array($input['pictures'] ?? null) ? $input['pictures'] : [];

    try {
        $newPageId = $wiki->createPage(
            trim($input['titel']),
            $input['content'],
            (int)$userId,
            $visibility,
            $parentWikiId,
            $pictures
        );
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Page created',
            'page_id' => $newPageId,
            'wiki_id' => $parentWikiId,
            'owner_id' => (int)$userId
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Request could not be completed']);
    }
    exit;
}

// 3. PUT: Uppdatera sida (och lägg till / ta bort bilder i databasen)
if ($method === 'PUT') {
    $pageId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$pageId) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input']);
        exit;
    }

    if (!$wiki->getPage($pageId)) {
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
        exit;
    }

    if (!$wiki->canModify($pageId, (int)$userId, false)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }

    if ((isset($input['titel']) && !is_string($input['titel'])) || (isset($input['removed_pic']) && !is_string($input['removed_pic']))) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input']);
        exit;
    }

    $addedPictures = is_array($input['pictures'] ?? null) ? $input['pictures'] : [];

    try {
        $updated = $wiki->updatePage(
            $pageId,
            $input['titel'] ?? null,
            $input['content'] ?? null,
            (int)$userId,
            $input['removed_pic'] ?? null,
            $addedPictures
        );

        if (!$updated) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Page updated',
            'page_id' => $pageId
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Request could not be completed']);
    }
    exit;
}

// 4. DELETE: Ta bort sida
if ($method === 'DELETE') {
    $pageId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$pageId) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input']);
        exit;
    }

    if (!$wiki->getPage($pageId)) {
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
        exit;
    }

    if (!$wiki->canModify($pageId, (int)$userId, true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    try {
        $wiki->deletePage($pageId);
        echo json_encode([
            'success' => true,
            'message' => 'Page deleted',
            'page_id' => $pageId
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Request could not be completed']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);