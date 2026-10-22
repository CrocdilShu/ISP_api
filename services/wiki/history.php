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

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$viewerId = (int)$userId;
$isAdmin = $wiki->isAdmin($viewerId);
$pageAccessCache = [];

$canSeeEntry = function (array $row) use ($wiki, $viewerId, $isAdmin, &$pageAccessCache): bool {
    if ($isAdmin || (int)$row['edited_by_id'] === $viewerId) {
        return true;
    }
    $pageId = is_array($row['old_text']) ? (int)($row['old_text']['_page_id'] ?? 0) : 0;
    if (!$pageId) {
        return false;
    }
    if (!array_key_exists($pageId, $pageAccessCache)) {
        $pageAccessCache[$pageId] = $wiki->canViewPageId($pageId, $viewerId);
    }
    return $pageAccessCache[$pageId];
};

$historyId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$filterUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
$filterPageId = isset($_GET['page_id']) ? (int)$_GET['page_id'] : null;
$order = strtoupper(trim((string)($_GET['sort'] ?? 'DESC'))) === 'ASC' ? 'ASC' : 'DESC';

// 1. Specifik historikpost (?id=X)
if ($historyId) {
    $stmt = $conn->prepare("
        SELECT h.ID AS history_id, h.update_at, h.old_text, h.removed_pic,
               h.FORuser AS edited_by_id, u.username AS edited_by_username
        FROM update_history h
        JOIN `user` u ON h.FORuser = u.ID
        WHERE h.ID = ?
    ");
    $stmt->bind_param("i", $historyId);
    $stmt->execute();
    $entry = $stmt->get_result()->fetch_assoc();

    if (!$entry) {
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
        exit;
    }

    $entry['old_text'] = json_decode($entry['old_text'], true);

    if (!$canSeeEntry($entry)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    echo json_encode(['success' => true, 'entry' => $entry]);
    exit;
}

// 2. Lista historik
if ($filterUserId) {
    $stmt = $conn->prepare("
        SELECT h.ID AS history_id, h.update_at, h.old_text, h.removed_pic,
               h.FORuser AS edited_by_id, u.username AS edited_by_username
        FROM update_history h
        JOIN `user` u ON h.FORuser = u.ID
        WHERE h.FORuser = ?
        ORDER BY h.update_at $order
    ");
    $stmt->bind_param("i", $filterUserId);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $result = $conn->query("
        SELECT h.ID AS history_id, h.update_at, h.old_text, h.removed_pic,
               h.FORuser AS edited_by_id, u.username AS edited_by_username
        FROM update_history h
        JOIN `user` u ON h.FORuser = u.ID
        ORDER BY h.update_at $order
    ");
    $res = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

$visible = [];
foreach ($res as $row) {
    $row['old_text'] = json_decode($row['old_text'], true);
    if ($filterPageId) {
        $entryPageId = is_array($row['old_text']) ? (int)($row['old_text']['_page_id'] ?? 0) : 0;
        if ($entryPageId !== $filterPageId) {
            continue;
        }
    }
    if ($canSeeEntry($row)) {
        $visible[] = $row;
    }
}

echo json_encode([
    'success' => true,
    'count' => count($visible),
    'history' => $visible
]);