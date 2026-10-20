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
    echo json_encode(['error' => 'Inloggning krävs för att se versionshistorik']);
    exit;
}

// Synka inloggad användare
$wiki = new PageModule($conn, 'wiki');
try {
    $wiki->ensureLocalUser((int)$userId);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Kunde inte synkronisera användare: ' . $e->getMessage()]);
    exit;
}

// GET: Hämta versionshistorik
if ($method === 'GET') {$historyId = isset($_GET['id']) ? (int)$_GET['id'] : null;
    $filterUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;

    // 1. Hämta en specifik historikpost via ?id=X
    if ($historyId) {
        $stmt =$conn->prepare("
            SELECT h.ID AS history_id, h.update_at, h.old_text, h.removed_pic,
                   h.FORuser AS edited_by_id, u.username AS edited_by_username
            FROM update_history h
            JOIN `user` u ON h.FORuser = u.ID
            WHERE h.ID = ?
        ");
        $stmt->bind_param("i", $historyId);$stmt->execute();
        $entry =$stmt->get_result()->fetch_assoc();

        if (!$entry) {
            http_response_code(404);
            echo json_encode(['error' => 'Historikposten hittades inte']);
            exit;
        }

        $entry['old_text'] = json_decode($entry['old_text'], true);
        echo json_encode($entry);
        exit;
    }

    // 2. Filtrera historik på en specifik användare via ?user_id=X
    if ($filterUserId) {
        $stmt =$conn->prepare("
            SELECT h.ID AS history_id, h.update_at, h.old_text, h.removed_pic,
                   h.FORuser AS edited_by_id, u.username AS edited_by_username
            FROM update_history h
            JOIN `user` u ON h.FORuser = u.ID
            WHERE h.FORuser = ?
            ORDER BY h.update_at DESC
        ");
        $stmt->bind_param("i", $filterUserId);$stmt->execute();
        $res =$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        // 3. Hämta all versionshistorik sorterad efter senaste ändring
        $result =$conn->query("
            SELECT h.ID AS history_id, h.update_at, h.old_text, h.removed_pic,
                   h.FORuser AS edited_by_id, u.username AS edited_by_username
            FROM update_history h
            JOIN `user` u ON h.FORuser = u.ID
            ORDER BY h.update_at DESC
        ");
        $res = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    // Avkoda JSON-innehållet i old_text så det returneras som ren JSON
    foreach ($res as &$row) {
        $row['old_text'] = json_decode($row['old_text'], true);
    }

    echo json_encode($res);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Metoden stöds inte. Använd GET.']);