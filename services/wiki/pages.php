<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/providerauth.php';
require_once __DIR__ . '/../common/PageModule.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$sessionKey = getRequestSessionKey();
$userId = null;

// Kontrollera sessionsbehörighet mot The Provider
$hasWikiAccess = hasServiceAccess($sessionKey, SERVICE_WIKI, $userId);

// Initiera modulen för wiki och synka användare
$wiki = new PageModule($conn, 'wiki');
if ($userId) {
    try {
        $wiki->ensureLocalUser((int)$userId);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Kunde inte synkronisera lokal användare: ' . $e->getMessage()]);
        exit;
    }
}

// 1. GET: Hämta en eller alla sidor
if ($method === 'GET') {
    $pageId = isset($_GET['id']) ? (int)$_GET['id'] : null;

    if ($pageId) {
        $data = $wiki->getPage($pageId, $hasWikiAccess);
        if (!$data) {
            http_response_code(404);
            echo json_encode(['error' => 'Wiki-sidan hittades inte']);
            exit;
        }
        if (isset($data['error']) && $data['error'] === 'FORBIDDEN') {
            http_response_code(403);
            echo json_encode(['error' => 'Inloggning krävs för att se denna privata wiki']);
            exit;
        }
        echo json_encode($data);
        exit;
    }

    echo json_encode($wiki->listPages($hasWikiAccess));
    exit;
}

// Skrivoperationer kräver inloggning
if ((!$hasWikiAccess) or (!$userId)) {
    http_response_code(403);
    echo json_encode(['error' => 'Ingen behörighet till Wiki-tjänsten']);
    exit;
}

// 2. POST: Skapa sida
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if ((empty($input['titel'])) or (!isset($input['content']))) {
        http_response_code(400);
        echo json_encode(['error' => 'Titel och content krävs']);
        exit;
    }

    $visibility = $input['visibility'] ?? 'public';

    try {
        $newPageId = $wiki->createPage($input['titel'], $input['content'], (int)$userId, $visibility);
        http_response_code(201);
        echo json_encode(['success' => true, 'page_id' => $newPageId]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Kunde inte skapa wiki-sidan: ' . $e->getMessage()]);
    }
    exit;
}

// 3. PUT: Uppdatera sida
if ($method === 'PUT') {
    $pageId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ((!$pageId) or (!$wiki->canModify($pageId, (int)$userId, false))) {
        http_response_code(403);
        echo json_encode(['error' => 'Ej behörig att redigera denna sida']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    try {
        $updated = $wiki->updatePage(
            $pageId,
            $input['titel'] ?? null,
            $input['content'] ?? null,
            (int)$userId,
            $input['removed_pic'] ?? null,
            $input['added_pic'] ?? null
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

// 4. DELETE: Ta bort sida
if ($method === 'DELETE') {
    $pageId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ((!$pageId) or (!$wiki->canModify($pageId, (int)$userId, true))) {
        http_response_code(403);
        echo json_encode(['error' => 'Endast ägaren eller admin kan ta bort sidan']);
        exit;
    }

    $wiki->deletePage($pageId);
    echo json_encode(['success' => true, 'message' => 'Wiki-sidan har tagits bort']);
    exit;
}