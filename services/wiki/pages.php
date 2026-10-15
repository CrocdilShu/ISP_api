<?php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

$method =$_SERVER['REQUEST_METHOD'];
$sessionKey = getRequestSessionKey();$userId = null;

// Kontrollera om användaren är inloggad och har behörighet till Wiki (tjanst 4)
$hasWikiAccess = hasServiceAccess($sessionKey, SERVICE_WIKI,$userId);

// Hjälpfunktion: Kontrollera användarens roll och sidbehörigheter
function canModifyWiki(PDO $pdo, int$pageId, int $userId, bool$requireOwnerOrAdmin = false): bool {
    // Kontrollera om användaren är admin eller root_admin
    $stmtRole =$pdo->prepare("
        SELECT r.role_name 
        FROM `user` u 
        JOIN role r ON u.FORrole = r.ID 
        WHERE u.ID = ?
    ");
    $stmtRole->execute([$userId]);
    $role =$stmtRole->fetchColumn();

if ($role === 'admin' \vert\vert $role === 'root_admin') 
    {        return true;
    }

    // Kontrollera om användaren är huvudägare för sidan
    $stmtPage =$pdo->prepare("
        SELECT p.FORuser, pw.ID AS wiki_id 
        FROM page p 
        JOIN page_wiki pw ON pw.FORpage = p.ID 
        WHERE p.ID = ?
    ");
    $stmtPage->execute([$pageId]);
    $wiki =$stmtPage->fetch();

    if (!$wiki) {
        return false;
    }

    if ((int)$wiki['FORuser'] ===$userId) {
        return true;
    }

    if ($requireOwnerOrAdmin) {
        return false;
    }

    // Kontrollera om användaren är tillagd som editor eller co_owner
    $stmtEditor =$pdo->prepare("SELECT ID FROM editor WHERE FORpage_wiki = ? AND FORuser = ?");
    $stmtEditor->execute([$wiki['wiki_id'],$userId]);
    if ($stmtEditor->fetchColumn()) {
        return true;
    }

    $stmtPerm =$pdo->prepare("SELECT ID FROM user_permission_page WHERE FORpage = ? AND FORuser = ?");
    $stmtPerm->execute([$pageId,$userId]);
    return (bool)$stmtPerm->fetchColumn();
}

// 1. GET: Hämta wiki-sidor (publika för gäster, publika + privata för inloggade)
if ($method === 'GET') {$pageId = isset($_GET['id']) ? (int)$_GET['id'] : null;

    if ($pageId) {
        $stmt =$pdo->prepare("
            SELECT p.ID AS page_id, p.titel, p.content, p.created_at, p.FORuser AS owner_id,
                   pw.ID AS wiki_id, v.state AS visibility
            FROM page p
            JOIN page_wiki pw ON pw.FORpage = p.ID
            JOIN visability v ON pw.FORvisability = v.ID
            WHERE p.ID = ?
        ");
        $stmt->execute([$pageId]);
        $wiki =$stmt->fetch();

        if (!$wiki) {
            http_response_code(404);
            echo json_encode(['error' => 'Wiki-sidan hittades inte']);
            exit;
        }

        if ($wiki['visibility'] === 'private' && !$hasWikiAccess) {
            http_response_code(403);
            echo json_encode(['error' => 'Inloggning krävs för att se denna privata wiki']);
            exit;
        }

        $wiki['content'] = json_decode($wiki['content'], true);
        echo json_encode($wiki);
        exit;
    }

    if ($hasWikiAccess) {
        $stmt =$pdo->query("
            SELECT p.ID AS page_id, p.titel, p.created_at, p.FORuser AS owner_id, v.state AS visibility
            FROM page p
            JOIN page_wiki pw ON pw.FORpage = p.ID
            JOIN visability v ON pw.FORvisability = v.ID
            ORDER BY p.created_at DESC
        ");
    } else {
        $stmt =$pdo->query("
            SELECT p.ID AS page_id, p.titel, p.created_at, p.FORuser AS owner_id, v.state AS visibility
            FROM page p
            JOIN page_wiki pw ON pw.FORpage = p.ID
            JOIN visability v ON pw.FORvisability = v.ID
            WHERE v.state = 'public'
            ORDER BY p.created_at DESC
        ");
    }

    echo json_encode($stmt->fetchAll());
    exit;
}

// Kräver giltig wikisession för POST, PUT, DELETE
if (!$hasWikiAccess \vert{}\vert{} !$userId) {
    http_response_code(403);
    echo json_encode(['error' => 'Ingen behörighet till Wiki-tjänsten']);
    exit;
}

// 2. POST: Skapa en ny wiki-sida
if ($method === 'POST') {$input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['titel']) \vert{}\vert{} !isset($input['content'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Titel och content krävs']);
        exit;
    }

    $visibilityId = (isset($input['visibility']) &&$input['visibility'] === 'private') ? 2 : 1;

    $pdo->beginTransaction();
    try {
        $stmtPage =$pdo->prepare("INSERT INTO page (titel, content, FORuser) VALUES (?, ?, ?)");
        $stmtPage->execute([$input['titel'],
            json_encode($input['content'], JSON_UNESCAPED_UNICODE),$userId
        ]);
        $pageId = (int)$pdo->lastInsertId();

        $stmtWiki =$pdo->prepare("INSERT INTO page_wiki (FORvisability, FORpage) VALUES (?, ?)");
        $stmtWiki->execute([$visibilityId,$pageId]);

        $pdo->commit();
        http_response_code(201);
        echo json_encode(['success' => true, 'page_id' => $pageId]);
    } catch (Exception $e) {$pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Kunde inte skapa wiki-sidan']);
    }
    exit;
}

// 3. PUT: Redigera wiki-sida och spara versionshistorik
if ($method === 'PUT') {$pageId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$pageId \vert{}\vert{} !canModifyWiki($pdo, $pageId,$userId, false)) {
        http_response_code(403);
        echo json_encode(['error' => 'Ej behörig att redigera denna sida']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    $pdo->beginTransaction();
    try {
        $stmtOld =$pdo->prepare("
            SELECT p.titel, p.content, pw.ID AS wiki_id 
            FROM page p 
            JOIN page_wiki pw ON pw.FORpage = p.ID 
            WHERE p.ID = ?
        ");
        $stmtOld->execute([$pageId]);
        $oldWiki =$stmtOld->fetch();

        $stmtHist =$pdo->prepare("
            INSERT INTO update_history (old_text, removed_pic, added_pic, FORuser, FORpage_wiki)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmtHist->execute([
            $oldWiki['content'],$input['removed_pic'] ?? null,
            $input['added_pic'] ?? null,
            $userId,$oldWiki['wiki_id']
        ]);

        $newTitle = $input['titel'] ?? $oldWiki['titel'];
        $newContent = isset($input['content']) 
            ? json_encode($input['content'], JSON_UNESCAPED_UNICODE) 
            : $oldWiki['content'];

        $stmtUpd =$pdo->prepare("UPDATE page SET titel = ?, content = ? WHERE ID = ?");
        $stmtUpd->execute([$newTitle, $newContent,$pageId]);

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Wiki-sidan har uppdaterats']);
    } catch (Exception $e) {$pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Kunde inte uppdatera wiki-sidan']);
    }
    exit;
}

// 4. DELETE: Ta bort wiki-sida
if ($method === 'DELETE') {$pageId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$pageId \vert{}\vert{} !canModifyWiki($pdo, $pageId,$userId, true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Endast ägaren eller admin kan ta bort sidan']);
        exit;
    }

    $stmtDel =$pdo->prepare("DELETE FROM page WHERE ID = ?");
    $stmtDel->execute([$pageId]);

    echo json_encode(['success' => true, 'message' => 'Wiki-sidan har tagits bort']);
    exit;
}