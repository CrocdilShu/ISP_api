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

if ((!$hasWikiAccess) or (!$userId)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// 1. GET: Lista alla rättigheter för en sida (?page_id=13)
if ($method === 'GET') {
    $pageId = isset($_GET['page_id']) ? (int)$_GET['page_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);

    if (!$pageId) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input']);
        exit;
    }

    $page = $wiki->getPage($pageId);
    if (!$page) {
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
        exit;
    }

    if (!$wiki->canViewPage($page, (int)$userId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
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
        'success' => true,
        'page_id' => $pageId,
        'owner_id' => (int)$page['owner_id'],
        'owner_username' => $page['owner_username'],
        'acting_user_id' => (int)$userId,
        'editors' => $editors,
        'permissions' => $pagePermissions
    ]);
    exit;
}

// 2. POST: Lägg till editor, co_owner ELLER (endast för Admin) ge owner till en annan person
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
        echo json_encode(['error' => 'Invalid input']);
        exit;
    }

    $page = $wiki->getPage($pageId);
    if (!$page) {
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
        exit;
    }

    if (!$wiki->userExists($targetUserId)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid user']);
        exit;
    }

    // Specialfall: Admin / Root Admin vill ge 'owner' (huvudägarskap) till en annan användare
    if ($permRole === 'owner') {
        if (!$wiki->isAdmin((int)$userId)) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }
        try {
            $wiki->transferPageOwnership($pageId, $targetUserId);
            echo json_encode([
                'success' => true,
                'message' => 'Ownership transferred',
                'page_id' => $pageId,
                'new_owner_id' => $targetUserId
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Request could not be completed']);
        }
        exit;
    }

    $allowedPageRoles = ['editor', 'co_owner'];
    if (!in_array($permRole, $allowedPageRoles, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid role']);
        exit;
    }

    if (!$wiki->canManagePermissions($pageId, (int)$userId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    if ((int)$page['owner_id'] === $targetUserId) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid user']);
        exit;
    }

    $wikiId = (int)$page['service_page_id'];

    $conn->begin_transaction();
    try {
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
            'message' => 'Permission granted',
            'page_id' => $pageId,
            'user_id' => $targetUserId,
            'role' => $permRole
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['error' => 'Request could not be completed']);
    }
    exit;
}

// 3. DELETE: Ta bort rättighet ELLER ta bort ownership (co_owner blir ny owner, annars deleted_user)
if ($method === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    $pageId = isset($_GET['page_id']) ? (int)$_GET['page_id'] : (int)($input['page_id'] ?? ($_GET['id'] ?? 0));
    $targetUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : (int)($input['user_id'] ?? 0);
    $removeOwnership = !empty($_GET['remove_ownership']) || !empty($input['remove_ownership']);

    if (!$pageId) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input']);
        exit;
    }

    $page = $wiki->getPage($pageId);
    if (!$page) {
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
        exit;
    }

    $ownerId = (int)$page['owner_id'];

    if ($removeOwnership || ($targetUserId > 0 && $targetUserId === $ownerId)) {
        if ((int)$userId !== $ownerId && !$wiki->isAdmin((int)$userId)) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }

        try {
            $transfer = $wiki->removePageOwnership($pageId);
            echo json_encode([
                'success' => true,
                'message' => 'Ownership removed',
                'page_id' => $pageId,
                'new_owner_id' => $transfer['new_owner_id'],
                'assigned_via' => $transfer['promoted_from']
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Request could not be completed']);
        }
        exit;
    }

    if (!$targetUserId) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input']);
        exit;
    }

    if (!$wiki->canManagePermissions($pageId, (int)$userId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
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
            'message' => 'Permission removed',
            'page_id' => $pageId,
            'user_id' => $targetUserId
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['error' => 'Request could not be completed']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);