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

$currentUserRole = $wiki->getUserRole((int)$userId);
$isAdmin = ($currentUserRole === 'admin' || $currentUserRole === 'root_admin');

// 1. GET: Lista admins eller sök användare via bokstäver (?search=abc&sort=ASC|DESC)
if ($method === 'GET') {
    $search = trim((string)($_GET['search'] ?? ''));
    $order = strtoupper(trim((string)($_GET['sort'] ?? 'ASC'))) === 'DESC' ? 'DESC' : 'ASC';

    if ($search !== '') {
        $like = '%' . $search . '%';
        $stmt = $conn->prepare("
            SELECT u.ID AS user_id, u.username, u.email, r.role_name
            FROM `user` u
            JOIN role r ON u.FORrole = r.ID
            WHERE u.username <> 'deleted_user' AND (u.username LIKE ? OR u.email LIKE ?)
            ORDER BY u.username $order
        ");
        $stmt->bind_param("ss", $like, $like);
        $stmt->execute();
        $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $result = $conn->query("
            SELECT u.ID AS user_id, u.username, u.email, r.role_name
            FROM `user` u
            JOIN role r ON u.FORrole = r.ID
            WHERE r.role_name IN ('admin', 'root_admin') AND u.username <> 'deleted_user'
            ORDER BY u.username $order
        ");
        $users = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    if (!$isAdmin) {
        foreach ($users as &$u) {
            unset($u['email']);
        }
        unset($u);
    }

    echo json_encode([
        'success' => true,
        'current_user_id' => (int)$userId,
        'current_user_role' => $currentUserRole,
        'count' => count($users),
        'users' => $users
    ]);
    exit;
}

// 2. POST: Ändra roll (root_admin kan sätta admin/root_admin/banned, vanlig admin kan sätta banned på vanliga users)
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    $targetUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : (int)($input['user_id'] ?? 0);
    $newRoleName = strtolower(trim((string)($input['role'] ?? 'admin')));

    if (!$targetUserId || $targetUserId === (int)$userId) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input']);
        exit;
    }

    // Om någon bannlyses -> överför alla deras wikisidor till co_owner (eller deleted_user)
    if ($newRoleName === 'banned') {
        if (!$isAdmin) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }
        $targetRole = $wiki->getUserRole($targetUserId);
        if ($targetRole === 'root_admin' || ($targetRole === 'admin' && $currentUserRole !== 'root_admin')) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }

        try {
            $transferredCount = $wiki->banUserAndTransferWikis($targetUserId);
            echo json_encode([
                'success' => true,
                'message' => 'User banned and wiki ownership transferred',
                'user_id' => $targetUserId,
                'transferred_pages' => $transferredCount
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Request could not be completed']);
        }
        exit;
    }

    if ($currentUserRole !== 'root_admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    if (($newRoleName !== 'admin') && ($newRoleName !== 'root_admin')) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input']);
        exit;
    }

    try {
        $stmtGetRole = $conn->prepare("SELECT ID FROM role WHERE role_name = ?");
        $stmtGetRole->bind_param("s", $newRoleName);
        $stmtGetRole->execute();
        $roleRow = $stmtGetRole->get_result()->fetch_assoc();
        $roleId = (int)($roleRow['ID'] ?? 2);

        $stmtUpd = $conn->prepare("UPDATE `user` SET FORrole = ? WHERE ID = ?");
        $stmtUpd->bind_param("ii", $roleId, $targetUserId);
        $stmtUpd->execute();
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Request could not be completed']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Role updated',
        'user_id' => $targetUserId,
        'role' => $newRoleName
    ]);
    exit;
}

// 3. DELETE: Ta bort admin-behörighet (återställ till 'user')
if ($method === 'DELETE') {
    if ($currentUserRole !== 'root_admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    $targetUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : (int)($input['user_id'] ?? 0);

    if (!$targetUserId || $targetUserId === (int)$userId) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input']);
        exit;
    }

    try {
        $stmtGetRole = $conn->prepare("SELECT ID FROM role WHERE role_name = 'user'");
        $stmtGetRole->execute();
        $userRoleId = (int)($stmtGetRole->get_result()->fetch_assoc()['ID'] ?? 1);

        $stmtUpd = $conn->prepare("UPDATE `user` SET FORrole = ? WHERE ID = ?");
        $stmtUpd->bind_param("ii", $userRoleId, $targetUserId);
        $stmtUpd->execute();
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Request could not be completed']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Role updated',
        'user_id' => $targetUserId,
        'role' => 'user'
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);