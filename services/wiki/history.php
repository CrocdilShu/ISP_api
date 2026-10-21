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
    echo json_encode(['error' => 'Inloggning krävs för att se versionshistorik']);
    exit;
}

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Metoden stöds inte. Använd GET.']);
    exit;
}

$viewerId = (int)$userId;
$isAdmin = $wiki->isAdmin($viewerId);
$pageAccessCache = [];

// Får användaren se denna historikpost? Admin: alla. Annars: egna ändringar, eller poster
// för sidor användaren har läsrätt till (privata sidors gamla innehåll läcker inte).
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

// 1. En specifik historikpost via ?id=X
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
        echo json_encode(['error' => 'Historikposten hittades inte']);
        exit;
    }

    $entry['old_text'] = json_decode($entry['old_text'], true);

    if (!$canSeeEntry($entry)) {
        http_response_code(403);
        echo json_encode(['error' => 'Du har inte behörighet att se denna historikpost']);
        exit;
    }

    echo json_encode($entry);
    exit;
}

// 2. Filtrera på användare via ?user_id=X, annars 3. all historik (senaste först)
if ($filterUserId) {
    $stmt = $conn->prepare("
        SELECT h.ID AS history_id, h.update_at, h.old_text, h.removed_pic,
               h.FORuser AS edited_by_id, u.username AS edited_by_username
        FROM update_history h
        JOIN `user` u ON h.FORuser = u.ID
        WHERE h.FORuser = ?
        ORDER BY h.update_at DESC
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
        ORDER BY h.update_at DESC
    ");
    $res = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

$visible = [];
foreach ($res as $row) {
    $row['old_text'] = json_decode($row['old_text'], true);
    if ($canSeeEntry($row)) {
        $visible[] = $row;
    }
}

echo json_encode($visible);
