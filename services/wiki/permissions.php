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
    echo json_encode(['error' => 'Inloggning krävs för att hantera sidrättigheter']);
    exit;
}

// 1. GET: Lista alla rättigheter för en sida (?page_id=13 eller ?id=13)
//    Endast den som får se sidan får se vem som har rättigheter på den.
if ($method === 'GET') {
    $pageId = isset($_GET['page_id']) ? (int)$_GET['page_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);

    if (!$pageId) {
        http_response_code(400);
        echo json_encode(['error' => 'Ange ?page_id=X i URL:en']);
        exit;
    }

    $page = $wiki->getPage($pageId);
    if (!$page) {
        http_response_code(404);
        echo json_encode(['error' => 'Wiki-sidan hittades inte']);
        exit;
    }

    if (!$wiki->canViewPage($page, (int)$userId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Du har inte behörighet till denna privata wiki']);
        exit;
    }

    $wikiId = (int)$page['service_page_id'];

    $stmtEd = $conn->prepare("
        SELECT e.ID AS editor_entry_id, e.FORuser AS user_id, u.username, 'editor' AS role_name
        FROM editor e
        JOIN `user` u ON e.FORuser = u.ID
        WHERE e.FORpage_wiki = ?
    ");
    $stmtEd->bind_param("i", $wikiId);
    $stmtEd->execute();
    $editors = $stmtEd->get_result()->fetch_all(MYSQLI_ASSOC);

    $stmtPerm = $conn->prepare("
        SELECT upp.ID AS permission_entry_id, upp.FORuser AS user_id, u.username, perm.role_name
        FROM user_permission_page upp
        JOIN `user` u ON upp.FORuser = u.ID
        JOIN permission perm ON upp.FORpermission = perm.ID
        WHERE upp.FORpage = ?
    ");
    $stmtPerm->bind_param("i", $pageId);
    $stmtPerm->execute();
    $pagePermissions = $stmtPerm->get_result()->fetch_all(MYSQLI_ASSOC);

    echo json_encode([
        'page_id' => $pageId,
        'owner_id' => (int)$page['owner_id'],
        'acting_user_id' => (int)$userId,
        'editors' => $editors,
        'permissions' => $pagePermissions
    ]);
    exit;
}

// 2. POST: Lägg till editor eller delägare (co_owner) på en wiki-sida
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    $pageId = isset($_GET['page_id']) ? (int)$_GET['page_id'] : (int)($input['page_id'] ?? ($_GET['id'] ?? 0));
    $targetUserId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
    $permRole = strtolower(trim((string)($input['role'] ?? 'editor')));

    if ((!$pageId) or (!$targetUserId)) {
        http_response_code(400);
        echo json_encode(['error' => 'page_id och user_id krävs']);
        exit;
    }

    // Endast tillåtna sidroller (huvudägarskapet kan aldrig ges bort)
    $allowedPageRoles = ['editor', 'co_owner'];
    if (!in_array($permRole, $allowedPageRoles, true)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Ogiltig sidroll. Tillåtna värden är "editor" eller "co_owner" (huvudägarskap kan inte tas över).'
        ]);
        exit;
    }

    $page = $wiki->getPage($pageId);
    if (!$page) {
        http_response_code(404);
        echo json_encode(['error' => 'Wiki-sidan hittades inte']);
        exit;
    }

    // Huvudägare, co_owner eller admin/root_admin
    if (!$wiki->canManagePermissions($pageId, (int)$userId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Endast sidans ägare eller admin kan lägga till rättigheter']);
        exit;
    }

    // Skydda huvudägaren
    if ((int)$page['owner_id'] === $targetUserId) {
        http_response_code(400);
        echo json_encode(['error' => 'Användaren är redan sidans huvudägare. Huvudägarskapet kan inte ändras eller tas över.']);
        exit;
    }

    $wikiId = (int)$page['service_page_id'];

    $conn->begin_transaction();
    try {
        $wiki->ensureLocalUser($targetUserId);

        $stmtCheckEd = $conn->prepare("SELECT ID FROM editor WHERE FORpage_wiki = ? AND FORuser = ?");
        $stmtCheckEd->bind_param("ii", $wikiId, $targetUserId);
        $stmtCheckEd->execute();
        if (!$stmtCheckEd->get_result()->fetch_assoc()) {
            $stmtAddEd = $conn->prepare("INSERT INTO editor (FORpage_wiki, FORuser) VALUES (?, ?)");
            $stmtAddEd->bind_param("ii", $wikiId, $targetUserId);
            $stmtAddEd->execute();
        }

        $stmtPermRole = $conn->prepare("INSERT IGNORE INTO permission (role_name) VALUES (?)");
        $stmtPermRole->bind_param("s", $permRole);
        $stmtPermRole->execute();

        $stmtGetPerm = $conn->prepare("SELECT ID FROM permission WHERE role_name = ?");
        $stmtGetPerm->bind_param("s", $permRole);
        $stmtGetPerm->execute();
        $permId = (int)$stmtGetPerm->get_result()->fetch_assoc()['ID'];

        $stmtCheckUpp = $conn->prepare("SELECT ID FROM user_permission_page WHERE FORpage = ? AND FORuser = ?");
        $stmtCheckUpp->bind_param("ii", $pageId, $targetUserId);
        $stmtCheckUpp->execute();
        $existingUpp = $stmtCheckUpp->get_result()->fetch_assoc();

        if ($existingUpp) {
            $uppId = (int)$existingUpp['ID'];
            $stmtUpdUpp = $conn->prepare("UPDATE user_permission_page SET FORpermission = ? WHERE ID = ?");
            $stmtUpdUpp->bind_param("ii", $permId, $uppId);
            $stmtUpdUpp->execute();
        } else {
            $stmtAddUpp = $conn->prepare("
                INSERT INTO user_permission_page (FORpage, FORuser, FORpermission)
                VALUES (?, ?, ?)
            ");
            $stmtAddUpp->bind_param("iii", $pageId, $targetUserId, $permId);
            $stmtAddUpp->execute();
        }

        $conn->commit();
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => "Användare $targetUserId har fått sidbehörigheten '$permRole' på sida $pageId"
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['error' => 'Kunde inte spara rättigheter: ' . $e->getMessage()]);
    }
    exit;
}

// 3. DELETE: Ta bort en användares rättigheter från en wiki-sida
if ($method === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    $pageId = isset($_GET['page_id']) ? (int)$_GET['page_id'] : (int)($input['page_id'] ?? ($_GET['id'] ?? 0));
    $targetUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : (int)($input['user_id'] ?? 0);

    if ((!$pageId) or (!$targetUserId)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ange page_id och user_id (?page_id=X&user_id=Y)']);
        exit;
    }

    $page = $wiki->getPage($pageId);
    if (!$page) {
        http_response_code(404);
        echo json_encode(['error' => 'Wiki-sidan hittades inte']);
        exit;
    }

    if (!$wiki->canManagePermissions($pageId, (int)$userId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Endast sidans ägare eller admin kan ta bort sidrättigheter']);
        exit;
    }

    // Huvudägaren kan aldrig tas bort från sin egen sida
    if ((int)$page['owner_id'] === $targetUserId) {
        http_response_code(403);
        echo json_encode(['error' => 'Sidans huvudägare kan inte tas bort']);
        exit;
    }

    $wikiId = (int)$page['service_page_id'];

    $conn->begin_transaction();
    try {
        $stmtDelEd = $conn->prepare("DELETE FROM editor WHERE FORpage_wiki = ? AND FORuser = ?");
        $stmtDelEd->bind_param("ii", $wikiId, $targetUserId);
        $stmtDelEd->execute();

        $stmtDelUpp = $conn->prepare("DELETE FROM user_permission_page WHERE FORpage = ? AND FORuser = ?");
        $stmtDelUpp->bind_param("ii", $pageId, $targetUserId);
        $stmtDelUpp->execute();

        $conn->commit();
        echo json_encode([
            'success' => true,
            'message' => "Rättigheter för användare $targetUserId har tagits bort från sida $pageId"
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['error' => 'Kunde inte ta bort rättigheter: ' . $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Metoden stöds inte']);
