<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/providerauth.php';
require_once __DIR__ . '/../common/PageModule.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];

$wiki = new PageModule($conn, 'wiki');
$denialReason = null;
$actingUser = $wiki->resolveRequestUser($denialReason);

// Automatically grant access if logged in via X-User-Token
$hasWikiAccess = ($actingUser !== null);
if (!$hasWikiAccess && (!empty($_SERVER['HTTP_X_SESSION_KEY']) || !empty($_SERVER['HTTP_AUTHORIZATION']))) {
    $sessionKey = getCompanySessionKey($conn);
    $providerAccountId = null;
    $hasWikiAccess = hasServiceAccess($sessionKey, SERVICE_WIKI, $providerAccountId);
}

if (!$hasWikiAccess) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No rights to access the wiki service']);
    exit;
}

if (!$actingUser) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => $denialReason ?? 'Unauthorized: valid X-User-Token required']);
    exit;
}

$userId = (int)$actingUser['id'];
$actingEmail = $actingUser['email'];
$currentUserRole = $actingUser['role'];
$isAdmin = ($currentUserRole === 'admin' || $currentUserRole === 'root_admin');

if ($method === 'GET') {
    $search = trim((string)($_GET['search'] ?? ''));
    $order = strtoupper(trim((string)($_GET['sort'] ?? 'ASC'))) === 'DESC' ? 'DESC' : 'ASC';

    if ($search !== '') {
        $like = '%' . $search . '%';
        $stmt = $conn->prepare("
            SELECT u.ID AS _id, u.email, r.role_name AS role
            FROM `user` u
            JOIN role r ON u.FORrole = r.ID
            WHERE u.username <> 'deleted_user' AND (u.email LIKE ? OR u.username LIKE ?)
            ORDER BY u.email $order
        ");
        $stmt->bind_param("ss", $like, $like);
        $stmt->execute();
        $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $result = $conn->query("
            SELECT u.ID AS _id, u.email, r.role_name AS role
            FROM `user` u
            JOIN role r ON u.FORrole = r.ID
            WHERE r.role_name IN ('admin', 'root_admin') AND u.username <> 'deleted_user'
            ORDER BY u.email $order
        ");
        $users = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    foreach ($users as &$u) {
        $u['banned_services'] = $wiki->getUserBannedServices((int)$u['_id']);
        unset($u['_id']);
    }
    unset($u);

    echo json_encode([
        'success' => true,
        'message' => 'Users listed',
        'acting_user_email' => $actingEmail,
        'acting_user_role' => $currentUserRole,
        'count' => count($users),
        'users' => $users
    ]);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    $targetEmail = trim((string)($_GET['email'] ?? ($input['email'] ?? '')));
    $newRoleName = strtolower(trim((string)($input['role'] ?? 'admin')));
    $serviceScope = strtolower(trim((string)($input['service'] ?? 'wiki')));

    if ($targetEmail === '' || strcasecmp($targetEmail, $actingEmail) === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid input: target email is required']);
        exit;
    }

    $targetUser = $wiki->getUserByEmail($targetEmail);
    if (!$targetUser) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Target user not found']);
        exit;
    }
    $targetUserId = (int)$targetUser['id'];
    $targetRole = $targetUser['role'];

    if ($newRoleName === 'banned') {
        if (!$isAdmin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'No rights to ban users']);
            exit;
        }
        if ($targetRole === 'root_admin' || ($targetRole === 'admin' && $currentUserRole !== 'root_admin')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'No rights to ban this user']);
            exit;
        }

        $transferredCount = $wiki->banUserFromService($targetUserId, $serviceScope);
        echo json_encode([
            'success' => true,
            'message' => "User banned from {$serviceScope}",
            'email' => $targetUser['email'],
            'banned_service' => $serviceScope,
            'transferred_spaces' => $transferredCount
        ]);
        exit;
    }

    if ($currentUserRole !== 'root_admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No rights to change roles']);
        exit;
    }

    $stmtGetRole = $conn->prepare("SELECT ID FROM role WHERE role_name = ? LIMIT 1");
    $stmtGetRole->bind_param("s", $newRoleName);
    $stmtGetRole->execute();
    $roleRow = $stmtGetRole->get_result()->fetch_assoc();
    $roleId = (int)($roleRow['ID'] ?? 2);

    $stmtUpd = $conn->prepare("UPDATE `user` SET FORrole = ? WHERE ID = ?");
    $stmtUpd->bind_param("ii", $roleId, $targetUserId);
    $stmtUpd->execute();

    echo json_encode([
        'success' => true,
        'message' => 'User role updated',
        'email' => $targetUser['email'],
        'role' => $newRoleName
    ]);
    exit;
}

if ($method === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    $targetEmail = trim((string)($_GET['email'] ?? ($input['email'] ?? '')));
    $unbanService = isset($input['unban_service']) ? strtolower(trim((string)$input['unban_service'])) : null;

    if ($targetEmail === '' || strcasecmp($targetEmail, $actingEmail) === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid input: target email is required']);
        exit;
    }

    $targetUser = $wiki->getUserByEmail($targetEmail);
    if (!$targetUser) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Target user not found']);
        exit;
    }
    $targetUserId = (int)$targetUser['id'];

    if ($unbanService !== null) {
        if (!$isAdmin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'No rights to unban users']);
            exit;
        }
        $wiki->unbanUserFromService($targetUserId, $unbanService);
        echo json_encode([
            'success' => true,
            'message' => "User unbanned from {$unbanService}",
            'email' => $targetUser['email'],
            'unbanned_service' => $unbanService
        ]);
        exit;
    }

    if ($currentUserRole !== 'root_admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No rights to demote admins']);
        exit;
    }

    $stmtGetRole = $conn->prepare("SELECT ID FROM role WHERE role_name = 'user' LIMIT 1");
    $stmtGetRole->execute();
    $userRoleId = (int)($stmtGetRole->get_result()->fetch_assoc()['ID'] ?? 1);

    $stmtUpd = $conn->prepare("UPDATE `user` SET FORrole = ? WHERE ID = ?");
    $stmtUpd->bind_param("ii", $userRoleId, $targetUserId);
    $stmtUpd->execute();

    echo json_encode([
        'success' => true,
        'message' => 'Admin role revoked',
        'email' => $targetUser['email'],
        'role' => 'user'
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);