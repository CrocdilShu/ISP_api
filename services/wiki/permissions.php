<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/providerauth.php';
require_once __DIR__ . '/../common/PageModule.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$sessionKey = getCompanySessionKey($conn);
$providerAccountId = null;

$hasWikiAccess = hasServiceAccess($sessionKey, SERVICE_WIKI, $providerAccountId);
if (!$hasWikiAccess) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No rights to access the wiki service (invalid company session key)']);
    exit;
}

$wiki = new PageModule($conn, 'wiki');
$denialReason = null;
$actingUser = $wiki->resolveRequestUser($denialReason);

if (!$actingUser) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => $denialReason ?? 'Unauthorized: valid X-User-Token required']);
    exit;
}
$userId = (int)$actingUser['id'];
$actingEmail = $actingUser['email'];

// 1. GET: List all permissions for a Wiki (?wiki_id=1)
if ($method === 'GET') {
    $wikiId = isset($_GET['wiki_id']) ? (int)$_GET['wiki_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);

    if ($wikiId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid input: wiki_id is required']);
        exit;
    }

    $space = $wiki->getSpace($wikiId);
    if (!$space) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Wiki not found']);
        exit;
    }

    if (!$wiki->canViewSpace($space, $userId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No rights to view permissions for this private wiki']);
        exit;
    }

    $permissions = $wiki->getSpacePermissions($wikiId);

    echo json_encode([
        'success' => true,
        'message' => 'Wiki permissions fetched',
        'wiki_id' => $wikiId,
        'visibility' => $space['visibility'],
        'owner_email' => $space['owner_email'],
        'acting_user_email' => $actingEmail,
        'permissions' => $permissions
    ]);
    exit;
}

// 2. POST: Grant editor, co_owner, or transfer owner on a Wiki
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    $wikiId = isset($_GET['wiki_id']) ? (int)$_GET['wiki_id'] : (int)($input['wiki_id'] ?? ($_GET['id'] ?? 0));
    $targetEmail = trim((string)($input['email'] ?? ''));
    $permRole = strtolower(trim((string)($input['role'] ?? 'editor')));

    if ($wikiId <= 0 || $targetEmail === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid input: wiki_id and email are required']);
        exit;
    }

    $space = $wiki->getSpace($wikiId);
    if (!$space) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Wiki not found']);
        exit;
    }

    $targetUser = $wiki->getUserByEmail($targetEmail);
    if (!$targetUser || $wiki->isBannedFromService((int)$targetUser['id'], 'wiki')) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Target user email not found or user is banned from the wiki service']);
        exit;
    }
    $targetUserId = (int)$targetUser['id'];

    if ($permRole === 'owner') {
        if (!$wiki->isAdmin($userId) && (int)$space['_owner_id'] !== $userId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'No rights to transfer ownership of this wiki (requires wiki owner or admin)']);
            exit;
        }
        $wiki->transferSpaceOwnership($wikiId, $targetUserId);
        echo json_encode([
            'success' => true,
            'message' => 'Wiki ownership transferred',
            'wiki_id' => $wikiId,
            'email' => $targetUser['email'],
            'role' => 'owner'
        ]);
        exit;
    }

    if (!in_array($permRole, ['editor', 'co_owner'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid role: must be editor, co_owner, or owner']);
        exit;
    }

    if (!$wiki->canEditSpace($wikiId, $userId, true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No rights to manage permissions for this wiki (requires wiki owner, co_owner, or admin)']);
        exit;
    }

    if ((int)$space['_owner_id'] === $targetUserId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'User is already the owner of this wiki']);
        exit;
    }

    $wiki->setSpacePermission($wikiId, $targetUserId, $permRole);
    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Wiki permission granted (applies to all pages in this wiki)',
        'wiki_id' => $wikiId,
        'email' => $targetUser['email'],
        'role' => $permRole
    ]);
    exit;
}

// 3. DELETE: Remove permission or remove ownership on a Wiki
if ($method === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    $wikiId = isset($_GET['wiki_id']) ? (int)$_GET['wiki_id'] : (int)($input['wiki_id'] ?? ($_GET['id'] ?? 0));
    $targetEmail = trim((string)($_GET['email'] ?? ($input['email'] ?? '')));
    $removeOwnership = !empty($_GET['remove_ownership']) || !empty($input['remove_ownership']);

    if ($wikiId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid input: wiki_id is required']);
        exit;
    }

    $space = $wiki->getSpace($wikiId);
    if (!$space) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Wiki not found']);
        exit;
    }

    if ($removeOwnership || ($targetEmail !== '' && strcasecmp($targetEmail, $space['owner_email']) === 0)) {
        if ((int)$space['_owner_id'] !== $userId && !$wiki->isAdmin($userId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'No rights to remove ownership of this wiki (requires wiki owner or admin)']);
            exit;
        }

        $transfer = $wiki->removeSpaceOwnership($wikiId);
        echo json_encode([
            'success' => true,
            'message' => 'Wiki ownership removed and reassigned',
            'wiki_id' => $wikiId,
            'new_owner_email' => $transfer['new_owner_email'],
            'assigned_via' => $transfer['assigned_via']
        ]);
        exit;
    }

    if ($targetEmail === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid input: email is required']);
        exit;
    }

    if (!$wiki->canEditSpace($wikiId, $userId, true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No rights to revoke permissions for this wiki (requires wiki owner, co_owner, or admin)']);
        exit;
    }

    $targetUser = $wiki->getUserByEmail($targetEmail);
    if (!$targetUser) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Target user email not found']);
        exit;
    }

    $wiki->removeSpacePermission($wikiId, (int)$targetUser['id']);
    echo json_encode([
        'success' => true,
        'message' => 'Wiki permission removed',
        'wiki_id' => $wikiId,
        'email' => $targetUser['email']
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);