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
    echo json_encode(['error' => 'Inloggning krävs för att hantera administratörer']);
    exit;
}

$currentUserRole = $wiki->getUserRole((int)$userId);

// 1. GET: Lista alla admins och root_admins (e-post visas bara för admins)
if ($method === 'GET') {
    $result = $conn->query("
        SELECT u.ID AS user_id, u.username, u.email, r.role_name
        FROM `user` u
        JOIN role r ON u.FORrole = r.ID
        WHERE r.role_name IN ('admin', 'root_admin')
        ORDER BY r.ID DESC, u.ID ASC
    ");
    $admins = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

    if ($currentUserRole !== 'admin' && $currentUserRole !== 'root_admin') {
        foreach ($admins as &$a) {
            unset($a['email']);
        }
        unset($a);
    }

    echo json_encode([
        'current_user_id' => (int)$userId,
        'current_user_role' => $currentUserRole,
        'admins' => $admins
    ]);
    exit;
}

// Endast root_admin får utse eller ta bort andra admins
if ($currentUserRole !== 'root_admin') {
    http_response_code(403);
    echo json_encode([
        'error' => 'Endast root_admin har behörighet att lägga till eller ta bort administratörer',
        'your_role' => $currentUserRole
    ]);
    exit;
}

// 2. POST: Gör en användare till admin (eller root_admin)
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    $targetUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : (int)($input['user_id'] ?? 0);
    $newRoleName = $input['role'] ?? 'admin';

    if (!$targetUserId) {
        http_response_code(400);
        echo json_encode(['error' => 'Ange user_id i JSON-body eller som ?user_id=X']);
        exit;
    }

    if (($newRoleName !== 'admin') and ($newRoleName !== 'root_admin')) {
        http_response_code(400);
        echo json_encode(['error' => 'Ogiltig roll. Använd "admin" eller "root_admin"']);
        exit;
    }

    try {
        $wiki->ensureLocalUser($targetUserId);

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
        echo json_encode(['error' => 'Kunde inte ändra roll: ' . $e->getMessage()]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => "Användare $targetUserId har nu rollen '$newRoleName'"
    ]);
    exit;
}

// 3. DELETE: Ta bort admin-behörighet (återställ till 'user')
if ($method === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    $targetUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : (int)($input['user_id'] ?? 0);

    if (!$targetUserId) {
        http_response_code(400);
        echo json_encode(['error' => 'Ange ?user_id=X i URL:en eller i JSON-body']);
        exit;
    }

    if ($targetUserId === (int)$userId) {
        http_response_code(400);
        echo json_encode(['error' => 'Du kan inte ta bort din egen root_admin-roll']);
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
        echo json_encode(['error' => 'Kunde inte ändra roll: ' . $e->getMessage()]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => "Admin-rollen har tagits bort från användare $targetUserId (återställd till 'user')"
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Metoden stöds inte']);
