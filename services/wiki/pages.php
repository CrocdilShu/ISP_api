<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/providerauth.php';
require_once __DIR__ . '/../common/PageModule.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$sessionKey = getRequestSessionKey();
$providerAccountId = null;

// Kontrollera sessionsbehörighet mot The Provider
$hasWikiAccess = hasServiceAccess($sessionKey, SERVICE_WIKI, $providerAccountId);

// Identifiera inloggad användare (X-User-Token eller root_user)
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
$viewerId = $userId ? (int)$userId : null;

// 1. GET: Hämta en eller alla sidor (privata sidor kräver att man är ägare/editor/admin)
if ($method === 'GET') {
    $pageId = isset($_GET['id']) ? (int)$_GET['id'] : null;

    if ($pageId) {
        $data = $wiki->getPageForViewer($pageId, $viewerId);
        if (!$data) {
            http_response_code(404);
            echo json_encode(['error' => 'Wiki-sidan hittades inte']);
            exit;
        }
        if (isset($data['error']) && $data['error'] === 'FORBIDDEN') {
            http_response_code(403);
            echo json_encode(['error' => 'Du har inte behörighet att se denna privata wiki']);
            exit;
        }
        echo json_encode($data);
        exit;
    }

    echo json_encode($wiki->listPages($viewerId));
    exit;
}

// Skrivoperationer kräver både aktiv Wiki-tjänst och identifierad användare
if ((!$hasWikiAccess) or (!$userId)) {
    http_response_code(403);
    echo json_encode(['error' => 'Ingen behörighet till Wiki-tjänsten eller saknad inloggning']);
    exit;
}

// 2. POST: Skapa sida
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }

    if (!isset($input['titel']) or !is_string($input['titel']) or trim($input['titel']) === '' or !isset($input['content'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Titel och content krävs']);
        exit;
    }

    $visibility = $wiki->normalizeVisibility($input['visibility'] ?? 'public');
    if ($visibility === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Ogiltig visibility. Använd "public" eller "private"']);
        exit;
    }

    try {
        $newPageId = $wiki->createPage($input['titel'], $input['content'], (int)$userId, $visibility);
        http_response_code(201);
        echo json_encode(['success' => true, 'page_id' => $newPageId, 'owner_id' => (int)$userId]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Kunde inte skapa wiki-sidan: ' . $e->getMessage()]);
    }
    exit;
}

// 3. PUT: Uppdatera sida
if ($method === 'PUT') {
    $pageId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$pageId) {
        http_response_code(400);
        echo json_encode(['error' => 'Ange ?id=X i URL:en']);
        exit;
    }

    if (!$wiki->getPage($pageId)) {
        http_response_code(404);
        echo json_encode(['error' => 'Wiki-sidan hittades inte']);
        exit;
    }

    if (!$wiki->canModify($pageId, (int)$userId, false)) {
        http_response_code(403);
        echo json_encode(['error' => 'Ej behörig att redigera denna sida']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }

    if ((isset($input['titel']) and !is_string($input['titel'])) or (isset($input['removed_pic']) and !is_string($input['removed_pic']))) {
        http_response_code(400);
        echo json_encode(['error' => 'titel och removed_pic måste vara text']);
        exit;
    }

    try {
        $updated = $wiki->updatePage(
            $pageId,
            $input['titel'] ?? null,
            $input['content'] ?? null,
            (int)$userId,
            $input['removed_pic'] ?? null
        );

        if (!$updated) {
            http_response_code(404);
            echo json_encode(['error' => 'Wiki-sidan hittades inte']);
            exit;
        }

        echo json_encode(['success' => true, 'message' => 'Wiki-sidan har uppdaterats']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Kunde inte uppdatera wiki-sidan: ' . $e->getMessage()]);
    }
    exit;
}

// 4. DELETE: Ta bort sida (endast huvudägaren eller admin/root_admin)
if ($method === 'DELETE') {
    $pageId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$pageId) {
        http_response_code(400);
        echo json_encode(['error' => 'Ange ?id=X i URL:en']);
        exit;
    }

    if (!$wiki->getPage($pageId)) {
        http_response_code(404);
        echo json_encode(['error' => 'Wiki-sidan hittades inte']);
        exit;
    }

    if (!$wiki->canModify($pageId, (int)$userId, true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Endast sidans huvudägare eller admin kan ta bort sidan']);
        exit;
    }

    $wiki->deletePage($pageId);
    echo json_encode(['success' => true, 'message' => 'Wiki-sidan har tagits bort']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Metoden stöds inte']);
