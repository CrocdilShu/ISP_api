<?php

/**
 * ============================================================================
 * PageModule - Gemensam hanterare för Wiki- och Bloggsidor
 * ============================================================================
 *
 * Användningsområde:
 * Denna modul samlar all affärslogik för sidhantering och kommentarer som delas
 * mellan Wiki och Blogg (tabellerna `page`, `page_wiki`, `page_blog`, `comment`, `update_history` m.fl.).
 *
 * Hur du använder modulen i en endpoint:
 * 1. Skapa en instans för önskad tjänst:
 *      $wiki = new PageModule($conn, 'wiki');
 *      $blog = new PageModule($conn, 'blog');
 *
 * 2. Synka inloggad användare mot databasen:
 *      $wiki->ensureLocalUser($userId);
 *
 * 3. Använd modulens metoder för CRUD & Kommentarer:
 *      - Hämta lista:        $wiki->listPages($hasAccess);
 *      - Hämta specifik:     $wiki->getPage($pageId,$hasAccess);
 *      - Skapa sida:         $wiki->createPage($title,$content, $userId,$visibility);
 *      - Uppdatera sida:     $wiki->updatePage($pageId, $title,$content, $userId,$removedPic);
 *      - Ta bort sida:       $wiki->deletePage($pageId);
 *      - Kontrollera rätt:   $wiki->canModify($pageId, $userId,$requireOwnerOrAdmin);
 *      - Hämta kommentarer:  $wiki->getComments($pageId);
 *      - Skapa kommentar:    $wiki->addComment($pageId, $userId,$content);
 *      - Ändra kommentar:    $wiki->updateComment($commentId,$content);
 *      - Ta bort kommentar:  $wiki->deleteComment($commentId);
 */

class PageModule {
    private mysqli $conn;
    private string $serviceType;
    private string $subTable;

    public function __construct(mysqli $conn, string$serviceType = 'wiki') {
        $this->conn =$conn;
        $this->serviceType = strtolower($serviceType);
        $this->subTable = ($this->serviceType === 'blog') ? 'page_blog' : 'page_wiki';
    }

    /**
     * Säkerställer att användar-ID:t existerar i tabellen `user` för att undvika foreign key-fel.
     */
    public function ensureLocalUser(?int $userId): void {
        if (!$userId) {
            return;
        }

        $stmt =$this->conn->prepare("SELECT ID FROM `user` WHERE ID = ?");
        $stmt->bind_param("i", $userId);$stmt->execute();

        if (!$stmt->get_result()->fetch_assoc()) {
            $placeholderEmail = "user{$userId}@placeholder.local";
            $placeholderUsername = "user{$userId}";
            $unusableHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);$defaultRole = 1;

            try {
                $stmtInsert =$this->conn->prepare(
                    "INSERT INTO `user` (ID, username, email, password_hash, FORrole) VALUES (?, ?, ?, ?, ?)"
                );
                $stmtInsert->bind_param("isssi", $userId,$placeholderUsername, $placeholderEmail,$unusableHash, $defaultRole);$stmtInsert->execute();
            } catch (mysqli_sql_exception $e) {
                if ($e->getCode() !== 1062) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Kontrollerar behörighet för redigering och radering av sida.
     */
    public function canModify(int $pageId, int $userId, bool$requireOwnerOrAdmin = false): bool {
        $stmtRole =$this->conn->prepare("
            SELECT r.role_name
            FROM `user` u
            JOIN role r ON u.FORrole = r.ID
            WHERE u.ID = ?
        ");
        $stmtRole->bind_param("i", $userId);$stmtRole->execute();
        $role =$stmtRole->get_result()->fetch_assoc()['role_name'] ?? null;

        if (($role === 'admin') or ($role === 'root_admin')) {
            return true;
        }

        $sql = "
            SELECT p.FORuser, sub.ID AS sub_id
            FROM page p
            JOIN " . $this->subTable . " sub ON sub.FORpage = p.ID
            WHERE p.ID = ?
        ";
        $stmtPage =$this->conn->prepare($sql);$stmtPage->bind_param("i", $pageId);$stmtPage->execute();
        $page =$stmtPage->get_result()->fetch_assoc();

        if (!$page) {
            return false;
        }

        if ((int)$page['FORuser'] ===$userId) {
            return true;
        }

        if ($requireOwnerOrAdmin) {
            return false;
        }

        if ($this->subTable === 'page_wiki') {
            $wikiId = (int)$page['sub_id'];
            $stmtEditor =$this->conn->prepare("SELECT ID FROM editor WHERE FORpage_wiki = ? AND FORuser = ?");
            $stmtEditor->bind_param("ii", $wikiId, $userId);$stmtEditor->execute();
            if ($stmtEditor->get_result()->fetch_assoc()) {
                return true;
            }
        }

        $stmtPerm =$this->conn->prepare("SELECT ID FROM user_permission_page WHERE FORpage = ? AND FORuser = ?");
        $stmtPerm->bind_param("ii", $pageId, $userId);$stmtPerm->execute();
        return (bool)$stmtPerm->get_result()->fetch_assoc();
    }

    /**
     * Hämtar en sida baserat på dess ID.
     */
    public function getPage(int $pageId, bool $hasAccess): ?array {$sql = "
            SELECT p.ID AS page_id, p.titel, p.content, p.created_at, p.FORuser AS owner_id,
                   sub.ID AS service_page_id, v.state AS visibility
            FROM page p
            JOIN " . $this->subTable . " sub ON sub.FORpage = p.ID
            JOIN visability v ON sub.FORvisability = v.ID
            WHERE p.ID = ?
        ";
        $stmt =$this->conn->prepare($sql);$stmt->bind_param("i", $pageId);$stmt->execute();
        $page =$stmt->get_result()->fetch_assoc();

        if (!$page) {
            return null;
        }

        if (($page['visibility'] === 'private') and (!$hasAccess)) {
            return ['error' => 'FORBIDDEN'];
        }

        $page['content'] = json_decode($page['content'], true);
        return $page;
    }

    /**
     * Listar alla publika sidor (eller alla sidor om användaren har åtkomst).
     */
    public function listPages(bool $hasAccess): array {
        if ($hasAccess) {$sql = "
                SELECT p.ID AS page_id, p.titel, p.created_at, p.FORuser AS owner_id, v.state AS visibility
                FROM page p
                JOIN " . $this->subTable . " sub ON sub.FORpage = p.ID
                JOIN visability v ON sub.FORvisability = v.ID
                ORDER BY p.created_at DESC
            ";
        } else {
            $sql = "
                SELECT p.ID AS page_id, p.titel, p.created_at, p.FORuser AS owner_id, v.state AS visibility
                FROM page p
                JOIN " . $this->subTable . " sub ON sub.FORpage = p.ID
                JOIN visability v ON sub.FORvisability = v.ID
                WHERE v.state = 'public'
                ORDER BY p.created_at DESC
            ";
        }

        $result = $this->conn->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    /**
     * Skapar en sida i databasen.
     */
    public function createPage(string $title, mixed$content, int $userId, string$visibility = 'public'): int {
        $visibilityId = ($visibility === 'private') ? 2 : 1;
        $contentJson = json_encode($content, JSON_UNESCAPED_UNICODE);

        $this->conn->begin_transaction();
        try {
            $stmtPage =$this->conn->prepare("INSERT INTO page (titel, content, FORuser) VALUES (?, ?, ?)");
            $stmtPage->bind_param("ssi", $title,$contentJson, $userId);$stmtPage->execute();
            $pageId = (int)$this->conn->insert_id;

            $sqlSub = "INSERT INTO " . $this->subTable . " (FORvisability, FORpage) VALUES (?, ?)";
            $stmtSub = $this->conn->prepare($sqlSub);
            $stmtSub->bind_param("ii", $visibilityId, $pageId);$stmtSub->execute();

            $this->conn->commit();
            return $pageId;
        } catch (Exception $e) {$this->conn->rollback();
            throw $e;
        }
    }

    /**
     * Uppdaterar sidtitel och innehåll samt sparar historik i `update_history` (old_text, removed_pic, FORuser).
     */
    public function updatePage(int $pageId, ?string $title, mixed $content, int$userId, ?string $removedPic = null): bool {$this->conn->begin_transaction();
        try {
            $sqlOld = "
                SELECT p.titel, p.content, sub.ID AS sub_id
                FROM page p
                JOIN " . $this->subTable . " sub ON sub.FORpage = p.ID
                WHERE p.ID = ?
            ";
            $stmtOld =$this->conn->prepare($sqlOld);$stmtOld->bind_param("i", $pageId);$stmtOld->execute();
            $oldPage =$stmtOld->get_result()->fetch_assoc();

            if (!$oldPage) {$this->conn->rollback();
                return false;
            }

            if ($this->subTable === 'page_wiki') {
                $oldContentArr = json_decode($oldPage['content'], true);
                if (is_array($oldContentArr)) {
                    $oldContentArr['_page_id'] =$pageId;
                    $oldText = json_encode($oldContentArr, JSON_UNESCAPED_UNICODE);
                } else {
                    $oldText =$oldPage['content'];
                }

                $stmtHist =$this->conn->prepare("
                    INSERT INTO update_history (old_text, removed_pic, FORuser)
                    VALUES (?, ?, ?)
                ");
                $stmtHist->bind_param("ssi", $oldText,$removedPic, $userId);$stmtHist->execute();
            }

            $newTitle = $title ?? $oldPage['titel'];
            $newContent = ($content !== null)
                ? json_encode($content, JSON_UNESCAPED_UNICODE)
                : $oldPage['content'];

            $stmtUpd =$this->conn->prepare("UPDATE page SET titel = ?, content = ? WHERE ID = ?");
            $stmtUpd->bind_param("ssi", $newTitle,$newContent, $pageId);$stmtUpd->execute();

            $this->conn->commit();
            return true;
        } catch (Exception $e) {$this->conn->rollback();
            throw $e;
        }
    }

    /**
     * Tar bort en sida via ID.
     */
    public function deletePage(int $pageId): bool {
        $stmtDel =$this->conn->prepare("DELETE FROM page WHERE ID = ?");
        $stmtDel->bind_param("i", $pageId);
        return $stmtDel->execute();
    }

    /**
     * ========================================================================
     * KOMMENTARSHANTERING (Delas mellan Wiki och Blogg via tabellen `comment`)
     * ========================================================================
     */

    /**
     * Hämtar alla kommentarer för en specifik sida (sorterade äldst först).
     */
    public function getComments(int $pageId): array {
        $stmt =$this->conn->prepare("
            SELECT c.ID AS comment_id, c.content, c.created_at, c.last_update,
                   c.FORuser AS user_id, u.username, c.FORpage AS page_id
            FROM comment c
            JOIN `user` u ON c.FORuser = u.ID
            WHERE c.FORpage = ?
            ORDER BY c.created_at ASC
        ");
        $stmt->bind_param("i", $pageId);$stmt->execute();
        $rows =$stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($rows as &$row) {
            $row['content'] = json_decode($row['content'], true);
        }

        return $rows;
    }

    /**
     * Skapar en ny kommentar på en sida.
     */
    public function addComment(int $pageId, int $userId, mixed$content): int {
        if (!is_array($content)) {
            $content = ['text' => (string)$content];
        }
        $contentJson = json_encode($content, JSON_UNESCAPED_UNICODE);

        $stmt =$this->conn->prepare("
            INSERT INTO comment (content, FORuser, FORpage)
            VALUES (?, ?, ?)
        ");
        $stmt->bind_param("sii", $contentJson,$userId, $pageId);$stmt->execute();

        return (int)$this->conn->insert_id;
    }

    /**
     * Kontrollerar om en användare får redigera/radera en kommentar.
     */
    public function canModifyComment(int $commentId, int$userId): bool {
        $stmtRole =$this->conn->prepare("
            SELECT r.role_name
            FROM `user` u
            JOIN role r ON u.FORrole = r.ID
            WHERE u.ID = ?
        ");
        $stmtRole->bind_param("i", $userId);$stmtRole->execute();
        $role =$stmtRole->get_result()->fetch_assoc()['role_name'] ?? null;

        if (($role === 'admin') or ($role === 'root_admin')) {
            return true;
        }

        $stmt =$this->conn->prepare("
            SELECT c.FORuser AS comment_author, p.FORuser AS page_owner
            FROM comment c
            JOIN page p ON c.FORpage = p.ID
            WHERE c.ID = ?
        ");
        $stmt->bind_param("i", $commentId);$stmt->execute();
        $row =$stmt->get_result()->fetch_assoc();

        if (!$row) {
            return false;
        }

        return ((int)$row['comment_author'] ===$userId) or ((int)$row['page_owner'] ===$userId);
    }

    /**
     * Uppdaterar innehållet i en befintlig kommentar.
     */
    public function updateComment(int $commentId, mixed$content): bool {
        if (!is_array($content)) {
            $content = ['text' => (string)$content];
        }
        $contentJson = json_encode($content, JSON_UNESCAPED_UNICODE);

        $stmt =$this->conn->prepare("UPDATE comment SET content = ? WHERE ID = ?");
        $stmt->bind_param("si", $contentJson,$commentId);
        return $stmt->execute();
    }

    /**
     * Tar bort en kommentar.
     */
    public function deleteComment(int $commentId): bool {
        $stmt =$this->conn->prepare("DELETE FROM comment WHERE ID = ?");
        $stmt->bind_param("i", $commentId);
        return $stmt->execute();
    }
}