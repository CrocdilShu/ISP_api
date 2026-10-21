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
$userId = null;
if ($hasWikiAccess) {
    try {
        $userId = $wiki->resolveRequestUser($sessionKey, $providerAccountId ? (int)$providerAccountId : null);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Kunde inte synkronisera lokal användare: ' . $e->getMessage()]);
        exit;
    }
}

if ((!$hasWikiAccess) or (!$userId)) {
    http_response_code(403);
    echo json_encode(['error' => 'Inloggning krävs för att se och hantera kommentarer']);
    exit;
}

// Hämtar sidan och kontrollerar att användaren får se den (privata wikis: ägare/editor/admin).
// Avslutar med 404/403 om inte.
function requireViewablePage(PageModule $wiki, int $pageId, int $userId): array {
    $page = $wiki->getPage($pageId);
    if (!$page) {
        http_response_code(404);
        echo json_encode(['error' => 'Wiki-sidan hittades inte']);
        exit;
    }
    if (!$wiki->canViewPage($page, $userId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Du har inte behörighet till denna privata wiki']);
        exit;
    }
    return $page;
}

// 1. GET: Hämta alla kommentarer för en wiki-sida (?page_id=13 eller ?id=13)
if ($method === 'GET') {
    $pageId = isset($_GET['page_id']) ? (int)$_GET['page_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);

    if (!$pageId) {
        http_response_code(400);
        echo json_encode(['error' => 'Ange ?page_id=X i URL:en för att hämta kommentarer']);
        exit;
    }

    requireViewablePage($wiki, $pageId, (int)$userId);

    echo json_encode($wiki->getComments($pageId));
    exit;
}

// 2. POST: Skapa en ny kommentar (?page_id=13 eller i JSON-body)
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    $pageId = isset($_GET['page_id']) ? (int)$_GET['page_id'] : (int)($input['page_id'] ?? 0);

    if ((!$pageId) or (!isset($input['content']))) {
        http_response_code(400);
        echo json_encode(['error' => 'page_id och content krävs för att skapa en kommentar']);
        exit;
    }

    requireViewablePage($wiki, $pageId, (int)$userId);

    try {
        $commentId = $wiki->addComment($pageId, (int)$userId, $input['content']);
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'comment_id' => $commentId,
            'message' => 'Kommentaren har skapats'
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Kunde inte skapa kommentar: ' . $e->getMessage()]);
    }
    exit;
}

// 3. PUT: Redigera en kommentar (?id=X där X är comment_id)
if ($method === 'PUT') {
    $commentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }

    if ((!$commentId) or (!isset($input['content']))) {
        http_response_code(400);
        echo json_encode(['error' => 'Kommentars-ID (?id=X) och content krävs']);
        exit;
    }

    if (!$wiki->canModifyComment($commentId, (int)$userId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Ej behörig att redigera denna kommentar']);
        exit;
    }

    try {
        $wiki->updateComment($commentId, $input['content']);
        echo json_encode(['success' => true, 'message' => 'Kommentaren har uppdaterats']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Kunde inte uppdatera kommentar: ' . $e->getMessage()]);
    }
    exit;
}

// 4. DELETE: Ta bort en kommentar (?id=X där X är comment_id)
if ($method === 'DELETE') {
    $commentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if (!$commentId) {
        http_response_code(400);
        echo json_encode(['error' => 'Ange kommentars-ID (?id=X) i URL:en']);
        exit;
    }

    if (!$wiki->canModifyComment($commentId, (int)$userId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Ej behörig att ta bort denna kommentar']);
        exit;
    }

    $wiki->deleteComment($commentId);
    echo json_encode(['success' => true, 'message' => 'Kommentaren har tagits bort']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Metoden stöds inte']);
