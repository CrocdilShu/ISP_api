<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/providerauth.php';
require_once __DIR__ . '/../common/PageModule.php';

header('Content-Type: application/json; charset=utf-8');

$method =$_SERVER['REQUEST_METHOD'];
$sessionKey = getRequestSessionKey();$userId = null;

// Kontrollera behörighet till Wiki-tjänsten via The Provider
$hasWikiAccess = hasServiceAccess($sessionKey, SERVICE_WIKI,$userId);

if ((!$hasWikiAccess) or (!$userId)) {
    http_response_code(403);
    echo json_encode(['error' => 'Inloggning krävs för att hantera sidrättigheter']);
    exit;
}

$wiki = new PageModule($conn, 'wiki');
try {
    $wiki->ensureLocalUser((int)$userId);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Kunde inte synkronisera lokal användare: ' . $e->getMessage()]);
    exit;
}

// 1. GET: Lista alla rättigheter (ägare, editors och user_permission_page) för en sida (?page_id=13)
if ($method === 'GET') {$pageId = isset($_GET['page_id']) ? (int)$_GET['page_id'] : 0;

    if (!$pageId) {
        http_response_code(400);
        echo json_encode(['error' => 'Ange ?page_id=X i URL:en']);
        exit;
    }

    $page = $wiki->getPage($pageId, true);
    if (!$page) {
        http_response_code(404);
        echo json_encode(['error' => 'Wiki-sidan hittades inte']);
        exit;
    }

    $wikiId = (int)$page['service_page_id'];

    // Hämta editors från tabellen `editor`
    $stmtEd =$conn->prepare("
        SELECT e.ID AS editor_entry_id, e.FORuser AS user_id, u.username, 'editor' AS role_name
        FROM editor e
        JOIN `user` u ON e.FORuser = u.ID
        WHERE e.FORpage_wiki = ?
    ");
    $stmtEd->bind_param("i", $wikiId);$stmtEd->execute();
    $editors =$stmtEd->get_result()->fetch_all(MYSQLI_ASSOC);

    // Hämta detaljerade sidbehörigheter från `user_permission_page`
    $stmtPerm =$conn->prepare("
        SELECT upp.ID AS permission_entry_id, upp.FORuser AS user_id, u.username, perm.role_name
        FROM user_permission_page upp
        JOIN `user` u ON upp.FORuser = u.ID
        JOIN permission perm ON upp.FORpermission = perm.ID
        WHERE upp.FORpage = ?
    ");
    $stmtPerm->bind_param("i", $pageId);$stmtPerm->execute();
    $pagePermissions =$stmtPerm->get_result()->fetch_all(MYSQLI_ASSOC);

    echo json_encode([
        'page_id' => $pageId,
        'owner_id' => (int)$page['owner_id'],
        'editors' => $editors,
        'permissions' => $pagePermissions
    ]);
    exit;
}

// 2. POST: Delegerar rättigheter på en wiki-sida (endast huvudägare eller admin/root_admin)
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];$pageId = isset($_GET['page_id']) ? (int)$_GET['page_id'] : (int)($input['page_id'] ?? 0);$targetUserId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
    $permRole =$input['role'] ?? 'editor'; // t.ex. 'editor' eller 'co_owner'

    if ((!$pageId) or (!$targetUserId)) {
        http_response_code(400);
        echo json_encode(['error' => 'page_id och user_id krävs']);
        exit;
    }

    $page = $wiki->getPage($pageId, true);
    if (!$page) {
        http_response_code(404);
        echo json_encode(['error' => 'Wiki-sidan hittades inte']);
        exit;
    }

    // Kontrollera att inloggad användare är sidans huvudägare eller admin (true = requireOwnerOrAdmin)
    if (!$wiki->canModify($pageId, (int)$userId, true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Endast sidans huvudägare eller admin kan delegera sidrättigheter']);
        exit;
    }

    if ((int)$page['owner_id'] ===$targetUserId) {
        http_response_code(400);
        echo json_encode(['error' => 'Användaren är redan huvudägare för sidan']);
        exit;
    }

    // Säkerställ att målanvändaren finns i lokala `user`-tabellen
    $wiki->ensureLocalUser($targetUserId);
    $wikiId = (int)$page['service_page_id'];

    $conn->begin_transaction();
    try {
        // 1. Lägg till i `editor` om användaren inte redan finns där
        $stmtCheckEd =$conn->prepare("SELECT ID FROM editor WHERE FORpage_wiki = ? AND FORuser = ?");
        $stmtCheckEd->bind_param("ii", $wikiId, $targetUserId);$stmtCheckEd->execute();
        if (!$stmtCheckEd->get_result()->fetch_assoc()) {
            $stmtAddEd =$conn->prepare("INSERT INTO editor (FORpage_wiki, FORuser) VALUES (?, ?)");
            $stmtAddEd->bind_param("ii", $wikiId, $targetUserId);$stmtAddEd->execute();
        }

        // 2. Säkerställ att behörighetsrollen finns i `permission`-tabellen
        $stmtPermRole =$conn->prepare("INSERT IGNORE INTO permission (role_name) VALUES (?)");
        $stmtPermRole->bind_param("s", $permRole);$stmtPermRole->execute();

        $stmtGetPerm =$conn->prepare("SELECT ID FROM permission WHERE role_name = ?");
        $stmtGetPerm->bind_param("s", $permRole);$stmtGetPerm->execute();
        $permId = (int)$stmtGetPerm->get_result()->fetch_assoc()['ID'];

        // 3. Lägg till eller uppdatera i `user_permission_page`
        $stmtCheckUpp =$conn->prepare("SELECT ID FROM user_permission_page WHERE FORpage = ? AND FORuser = ?");
        $stmtCheckUpp->bind_param("ii", $pageId, $targetUserId);$stmtCheckUpp->execute();
        $existingUpp =$stmtCheckUpp->get_result()->fetch_assoc();

        if ($existingUpp) {
            $uppId = (int)$existingUpp['ID'];
            $stmtUpdUpp =$conn->prepare("UPDATE user_permission_page SET FORpermission = ? WHERE ID = ?");
            $stmtUpdUpp->bind_param("ii", $permId, $uppId);$stmtUpdUpp->execute();
        } else {
            $stmtAddUpp =$conn->prepare("
                INSERT INTO user_permission_page (FORpage, FORuser, FORpermission)
                VALUES (?, ?, ?)
            ");
            $stmtAddUpp->bind_param("iii", $pageId,$targetUserId, $permId);$stmtAddUpp->execute();
        }

        $conn->commit();
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => "Användare $targetUserId har fått behörigheten '$permRole' på sida $pageId"
        ]);
    } catch (Exception $e) {$conn->rollback();
        http_response_code(500);
        echo json_encode(['error' => 'Kunde inte spara rättigheter: ' . $e->getMessage()]);
    }
    exit;
}

// 3. DELETE: Ta bort en användares rättigheter från en wiki-sida
if ($method === 'DELETE') {$input = json_decode(file_get_contents('php://input'), true) ?? [];
    $pageId = isset($_GET['page_id']) ? (int)$_GET['page_id'] : (int)($input['page_id'] ?? 0);
    $targetUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : (int)($input['user_id'] ?? 0);

    if ((!$pageId) or (!$targetUserId)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ange page_id och user_id (?page_id=X&user_id=Y)']);
        exit;
    }

    $page = $wiki->getPage($pageId, true);
    if (!$page) {
        http_response_code(404);
        echo json_encode(['error' => 'Wiki-sidan hittades inte']);
        exit;
    }

    // Endast huvudägare eller admin får ta bort sidrättigheter
    if (!$wiki->canModify($pageId, (int)$userId, true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Endast sidans huvudägare eller admin kan ta bort sidrättigheter']);
        exit;
    }

    $wikiId = (int)$page['service_page_id'];

    $conn->begin_transaction();
    try {
        $stmtDelEd =$conn->prepare("DELETE FROM editor WHERE FORpage_wiki = ? AND FORuser = ?");
        $stmtDelEd->bind_param("ii", $wikiId, $targetUserId);$stmtDelEd->execute();

        $stmtDelUpp =$conn->prepare("DELETE FROM user_permission_page WHERE FORpage = ? AND FORuser = ?");
        $stmtDelUpp->bind_param("ii", $pageId, $targetUserId);$stmtDelUpp->execute();

        $conn->commit();
        echo json_encode([
            'success' => true,
            'message' => "Rättigheter för användare $targetUserId har tagits bort från sida $pageId"
        ]);
    } catch (Exception $e) {$conn->rollback();
        http_response_code(500);
        echo json_encode(['error' => 'Kunde inte ta bort rättigheter: ' . $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Metoden stöds inte']);