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

$viewerId = (int)$actingUser['id'];
$isAdmin = $wiki->isAdmin($viewerId);

if ($method === 'GET') {
    $historyId = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_GET['history_id']) ? (int)$_GET['history_id'] : 0);
    $filterEmail = isset($_GET['email']) ? trim((string)$_GET['email']) : '';
    $filterPageId = isset($_GET['page_id']) ? (int)$_GET['page_id'] : 0;
    $order = strtoupper(trim((string)($_GET['sort'] ?? 'DESC'))) === 'ASC' ? 'ASC' : 'DESC';

    $formatHistoryRow = function (array $row) use ($wiki): array {
        $decoded = json_decode($row['old_text'], true);
        $pageId = is_array($decoded) ? (int)($decoded['_page_id'] ?? 0) : 0;
        $oldTitle = is_array($decoded) && isset($decoded['titel']) ? (string)$decoded['titel'] : null;
        $oldContent = is_array($decoded) && isset($decoded['content'])
            ? $wiki->normalizePlainText($decoded['content'])
            : $wiki->normalizePlainText($decoded);

        return [
            'history_id' => (int)$row['history_id'],
            'page_id' => $pageId,
            'edited_by_email' => $row['edited_by_email'],
            'update_at' => $row['update_at'],
            'old_titel' => $oldTitle,
            'old_content' => $oldContent,
            'removed_pic' => $row['removed_pic']
        ];
    };

    if ($historyId > 0) {
        $stmt = $conn->prepare("
            SELECT h.ID AS history_id, h.update_at, h.old_text, h.removed_pic,
                   u.email AS edited_by_email, h.FORuser AS _edited_by_id
            FROM update_history h
            JOIN `user` u ON h.FORuser = u.ID
            WHERE h.ID = ?
        ");
        $stmt->bind_param("i", $historyId);
        $stmt->execute();
        $entry = $stmt->get_result()->fetch_assoc();

        if (!$entry) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'History entry not found']);
            exit;
        }

        $formatted = $formatHistoryRow($entry);
        $canSee = $isAdmin
            || ((int)$entry['_edited_by_id'] === $viewerId)
            || ($formatted['page_id'] > 0 && $wiki->canViewPageId($formatted['page_id'], $viewerId));

        if (!$canSee) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'No rights to view this history entry']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'message' => 'History entry fetched',
            'entry' => $formatted
        ]);
        exit;
    }

    if ($filterEmail !== '') {
        $stmt = $conn->prepare("
            SELECT h.ID AS history_id, h.update_at, h.old_text, h.removed_pic,
                   u.email AS edited_by_email, h.FORuser AS _edited_by_id
            FROM update_history h
            JOIN `user` u ON h.FORuser = u.ID
            WHERE u.email = ?
            ORDER BY h.update_at $order
        ");
        $stmt->bind_param("s", $filterEmail);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $result = $conn->query("
            SELECT h.ID AS history_id, h.update_at, h.old_text, h.removed_pic,
                   u.email AS edited_by_email, h.FORuser AS _edited_by_id
            FROM update_history h
            JOIN `user` u ON h.FORuser = u.ID
            ORDER BY h.update_at $order
        ");
        $res = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    $visible = [];
    foreach ($res as $row) {
        $formatted = $formatHistoryRow($row);
        if ($filterPageId > 0 && $formatted['page_id'] !== $filterPageId) {
            continue;
        }
        $canSee = $isAdmin
            || ((int)$row['_edited_by_id'] === $viewerId)
            || ($formatted['page_id'] > 0 && $wiki->canViewPageId($formatted['page_id'], $viewerId));

        if ($canSee) {
            $visible[] = $formatted;
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'History listed',
        'count' => count($visible),
        'history' => $visible
    ]);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    $historyId = isset($_GET['history_id']) ? (int)$_GET['history_id'] : (int)($input['history_id'] ?? ($_GET['id'] ?? ($input['id'] ?? 0)));

    if ($historyId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid input: history_id is required']);
        exit;
    }

    $stmt = $conn->prepare("SELECT old_text FROM update_history WHERE ID = ? LIMIT 1");
    $stmt->bind_param("i", $historyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'History entry not found']);
        exit;
    }

    $decoded = json_decode($row['old_text'], true);
    $pageId = is_array($decoded) ? (int)($decoded['_page_id'] ?? 0) : 0;

    if ($pageId <= 0 || !$wiki->getPage($pageId)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Target page no longer exists']);
        exit;
    }

    if (!$wiki->canModifyPage($pageId, $viewerId, false)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No rights to rollback this page']);
        exit;
    }

    try {
        $restoredPage = $wiki->rollbackPageToHistory($historyId, $viewerId);
        echo json_encode([
            'success' => true,
            'message' => 'Page rolled back',
            'history_id' => $historyId,
            'page' => $restoredPage
        ]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);