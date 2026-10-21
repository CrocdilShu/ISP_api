<?php

require_once __DIR__ . '/../helpers.php';

/**
 * ============================================================================
 * PageModule - Gemensam hanterare för Wiki- och Bloggsidor
 * ============================================================================
 */
class PageModule {
    private mysqli $conn;
    private string $serviceType;
    private string $subTable;

    public function __construct(mysqli $conn, string $serviceType = 'wiki') {
        $this->conn = $conn;
        $this->serviceType = strtolower($serviceType);
        $this->subTable = ($this->serviceType === 'blog') ? 'page_blog' : 'page_wiki';
    }

    /**
     * Identifierar vem som gör anropet:
     * 1. Om X-User-Token skickas med -> den anställdes user.ID. Är token ogiltig/utgången
     *    returneras null (INGEN fallback till root/provider, annars kan en trasig token ge högre behörighet).
     * 2. Om session_key tillhör en inloggad root_user (från rootLogin) -> root_admin (FORrole = 3).
     * 3. Annars om Provider returnerade ett accountId -> synka i user-tabellen.
     * Returnerar null om ingen kan identifieras (eller om ID-kollision med en root_user upptäcks).
     */
    public function resolveRequestUser(?string $sessionKey, ?int $providerAccountId): ?int {
        // 1. Anställd via X-User-Token
        $token = function_exists('getUserToken') ? getUserToken() : null;
        if ($token !== null && $token !== '') {
            $id = 0;
            if (function_exists('actingUserId')) {
                $id = (int)actingUserId($this->conn);
            } else {
                $tokenHash = hash('sha256', $token);
                $stmt = $this->conn->prepare("
                    SELECT FORuser FROM user_session
                    WHERE token_hash = ? AND (expires_at IS NULL OR expires_at > NOW())
                    LIMIT 1
                ");
                if ($stmt) {
                    $stmt->bind_param("s", $tokenHash);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    if ($row) {
                        $id = (int)$row['FORuser'];
                    }
                }
            }
            return $id > 0 ? $id : null;
        }

        // 2. session_key tillhör en lokal root_user (inloggad via rootLogin)
        if ($sessionKey) {
            try {
                $stmtRoot = $this->conn->prepare("
                    SELECT ID, username FROM root_user
                    WHERE token = ? AND (expire_date IS NULL OR expire_date > NOW())
                    LIMIT 1
                ");
                if ($stmtRoot) {
                    $stmtRoot->bind_param("s", $sessionKey);
                    $stmtRoot->execute();
                    $rootRow = $stmtRoot->get_result()->fetch_assoc();
                    if ($rootRow) {
                        $rootId = (int)$rootRow['ID'];
                        if (!$this->ensureLocalUser($rootId, true, $rootRow['username'])) {
                            return null; // ID-kollision med en vanlig användare
                        }
                        return $rootId;
                    }
                }
            } catch (mysqli_sql_exception $e) {
                // root_user-tabellen matchar inte, fortsätt till fallback
            }
        }

        // 3. Fallback: Provider returnerade ett konto-ID
        if ($providerAccountId) {
            $isRoot = false;
            $rootUsername = null;
            try {
                $stmtCheckRoot = $this->conn->prepare("SELECT username FROM root_user WHERE ID = ? LIMIT 1");
                if ($stmtCheckRoot) {
                    $stmtCheckRoot->bind_param("i", $providerAccountId);
                    $stmtCheckRoot->execute();
                    $r = $stmtCheckRoot->get_result()->fetch_assoc();
                    if ($r) {
                        $isRoot = true;
                        $rootUsername = $r['username'];
                    }
                }
            } catch (mysqli_sql_exception $e) {
            }

            if (!$this->ensureLocalUser((int)$providerAccountId, $isRoot, $rootUsername)) {
                return null;
            }
            return (int)$providerAccountId;
        }

        return null;
    }

    /**
     * Säkerställer att användar-ID:t finns i `user` (undviker foreign key-fel).
     * Om $isRootUser är true får användaren rollen root_admin (3).
     *
     * root_user.ID och user.ID delar ID-rymd. Finns redan en användare med samma ID men ett
     * ANNAT användarnamn än root-användarens uppgraderas den INTE (det vore en privilege escalation);
     * funktionen returnerar då false.
     */
    public function ensureLocalUser(?int $userId, bool $isRootUser = false, ?string $customUsername = null): bool {
        if (!$userId) {
            return false;
        }

        $this->conn->query("INSERT IGNORE INTO role (ID, role_name) VALUES (1, 'user'), (2, 'admin'), (3, 'root_admin')");

        $stmt = $this->conn->prepare("SELECT ID, username, FORrole FROM `user` WHERE ID = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();

        if (!$existing) {
            $placeholderEmail = "user{$userId}@placeholder.local";
            $placeholderUsername = $customUsername ?: "user{$userId}";
            $unusableHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            $defaultRole = $isRootUser ? 3 : 1;

            try {
                $stmtInsert = $this->conn->prepare(
                    "INSERT INTO `user` (ID, username, email, password_hash, FORrole) VALUES (?, ?, ?, ?, ?)"
                );
                $stmtInsert->bind_param("isssi", $userId, $placeholderUsername, $placeholderEmail, $unusableHash, $defaultRole);
                $stmtInsert->execute();
            } catch (mysqli_sql_exception $e) {
                if ($e->getCode() !== 1062) {
                    throw $e;
                }
            }
            return true;
        }

        if ($isRootUser && (int)$existing['FORrole'] !== 3) {
            if ($customUsername === null || $existing['username'] !== $customUsername) {
                return false; // kollision: annan person med samma ID
            }
            $stmtUpd = $this->conn->prepare("UPDATE `user` SET FORrole = 3 WHERE ID = ?");
            $stmtUpd->bind_param("i", $userId);
            $stmtUpd->execute();
        }
        return true;
    }

    /**
     * Hämtar användarens rollnamn ('user', 'admin', 'root_admin').
     */
    public function getUserRole(int $userId): string {
        $stmtRole = $this->conn->prepare("
            SELECT r.role_name
            FROM `user` u
            JOIN role r ON u.FORrole = r.ID
            WHERE u.ID = ?
        ");
        $stmtRole->bind_param("i", $userId);
        $stmtRole->execute();
        return $stmtRole->get_result()->fetch_assoc()['role_name'] ?? 'user';
    }

    public function isAdmin(int $userId): bool {
        $role = $this->getUserRole($userId);
        return $role === 'admin' || $role === 'root_admin';
    }

    /**
     * Validerar synlighet. Returnerar 'public' | 'private', eller null om värdet är ogiltigt.
     */
    public function normalizeVisibility(mixed $visibility): ?string {
        if ($visibility === null) {
            return 'public';
        }
        if (!is_string($visibility)) {
            return null;
        }
        $v = strtolower(trim($visibility));
        return ($v === 'public' || $v === 'private') ? $v : null;
    }

    /**
     * Kontrollerar behörighet för redigering och radering av sida.
     * - $requireOwnerOrAdmin = true: endast HUVUDÄGARE eller admin/root_admin (t.ex. DELETE sida).
     * - $requireOwnerOrAdmin = false: även delegerade editors/co_owners får redigera (PUT).
     */
    public function canModify(int $pageId, int $userId, bool $requireOwnerOrAdmin = false): bool {
        if ($this->isAdmin($userId)) {
            return true;
        }

        $sql = "
            SELECT p.FORuser, sub.ID AS sub_id
            FROM page p
            JOIN " . $this->subTable . " sub ON sub.FORpage = p.ID
            WHERE p.ID = ?
        ";
        $stmtPage = $this->conn->prepare($sql);
        $stmtPage->bind_param("i", $pageId);
        $stmtPage->execute();
        $page = $stmtPage->get_result()->fetch_assoc();

        if (!$page) {
            return false;
        }

        if ((int)$page['FORuser'] === $userId) {
            return true;
        }

        if ($requireOwnerOrAdmin) {
            return false;
        }

        if ($this->subTable === 'page_wiki') {
            $wikiId = (int)$page['sub_id'];
            $stmtEditor = $this->conn->prepare("SELECT ID FROM editor WHERE FORpage_wiki = ? AND FORuser = ?");
            $stmtEditor->bind_param("ii", $wikiId, $userId);
            $stmtEditor->execute();
            if ($stmtEditor->get_result()->fetch_assoc()) {
                return true;
            }
        }

        $stmtPerm = $this->conn->prepare("SELECT ID FROM user_permission_page WHERE FORpage = ? AND FORuser = ?");
        $stmtPerm->bind_param("ii", $pageId, $userId);
        $stmtPerm->execute();
        return (bool)$stmtPerm->get_result()->fetch_assoc();
    }

    /**
     * Vem som får lägga till/ta bort rättigheter: huvudägaren, admin/root_admin eller 'co_owner'.
     * En delägare kan dock ALDRIG ändra eller ta bort huvudägaren.
     */
    public function canManagePermissions(int $pageId, int $userId): bool {
        if ($this->canModify($pageId, $userId, true)) {
            return true;
        }

        $stmt = $this->conn->prepare("
            SELECT upp.ID
            FROM user_permission_page upp
            JOIN permission perm ON upp.FORpermission = perm.ID
            WHERE upp.FORpage = ? AND upp.FORuser = ? AND perm.role_name = 'co_owner'
        ");
        $stmt->bind_param("ii", $pageId, $userId);
        $stmt->execute();
        return (bool)$stmt->get_result()->fetch_assoc();
    }

    /**
     * Hämtar en sida utan åtkomstkontroll (för existenskontroller och behörighetslogik).
     */
    public function getPage(int $pageId): ?array {
        $sql = "
            SELECT p.ID AS page_id, p.titel, p.content, p.created_at, p.FORuser AS owner_id,
                   sub.ID AS service_page_id, v.state AS visibility
            FROM page p
            JOIN " . $this->subTable . " sub ON sub.FORpage = p.ID
            JOIN visability v ON sub.FORvisability = v.ID
            WHERE p.ID = ?
        ";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $pageId);
        $stmt->execute();
        $page = $stmt->get_result()->fetch_assoc();

        if (!$page) {
            return null;
        }

        $page['content'] = json_decode($page['content'], true);
        return $page;
    }

    /**
     * Får $viewerId (null = ej identifierad) se sidan?
     * Publika sidor: alla. Privata: ägare, admin/root_admin, editors och användare med sidrättighet.
     */
    public function canViewPage(array $page, ?int $viewerId): bool {
        if ($page['visibility'] !== 'private') {
            return true;
        }
        if ($viewerId === null || $viewerId <= 0) {
            return false;
        }
        if ((int)$page['owner_id'] === $viewerId || $this->isAdmin($viewerId)) {
            return true;
        }

        if ($this->subTable === 'page_wiki') {
            $wikiId = (int)$page['service_page_id'];
            $stmtEd = $this->conn->prepare("SELECT ID FROM editor WHERE FORpage_wiki = ? AND FORuser = ?");
            $stmtEd->bind_param("ii", $wikiId, $viewerId);
            $stmtEd->execute();
            if ($stmtEd->get_result()->fetch_assoc()) {
                return true;
            }
        }

        $pageId = (int)$page['page_id'];
        $stmtPerm = $this->conn->prepare("SELECT ID FROM user_permission_page WHERE FORpage = ? AND FORuser = ?");
        $stmtPerm->bind_param("ii", $pageId, $viewerId);
        $stmtPerm->execute();
        return (bool)$stmtPerm->get_result()->fetch_assoc();
    }

    /**
     * Som canViewPage men via sid-ID. Sidan finns inte -> false.
     */
    public function canViewPageId(int $pageId, ?int $viewerId): bool {
        $page = $this->getPage($pageId);
        return $page ? $this->canViewPage($page, $viewerId) : false;
    }

    /**
     * Hämtar en sida med åtkomstkontroll: null = finns ej, ['error' => 'FORBIDDEN'] = ej behörig.
     */
    public function getPageForViewer(int $pageId, ?int $viewerId): ?array {
        $page = $this->getPage($pageId);
        if (!$page) {
            return null;
        }
        if (!$this->canViewPage($page, $viewerId)) {
            return ['error' => 'FORBIDDEN'];
        }
        return $page;
    }

    /**
     * Listar sidor som $viewerId får se: alla publika + privata där användaren är ägare/editor/har rättighet.
     * Admin/root_admin ser alla.
     */
    public function listPages(?int $viewerId): array {
        $select = "
            SELECT p.ID AS page_id, p.titel, p.created_at, p.FORuser AS owner_id, v.state AS visibility
            FROM page p
            JOIN " . $this->subTable . " sub ON sub.FORpage = p.ID
            JOIN visability v ON sub.FORvisability = v.ID
        ";

        if ($viewerId !== null && $viewerId > 0 && $this->isAdmin($viewerId)) {
            $result = $this->conn->query($select . " ORDER BY p.created_at DESC");
            return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        }

        if ($viewerId === null || $viewerId <= 0) {
            $result = $this->conn->query($select . " WHERE v.state = 'public' ORDER BY p.created_at DESC");
            return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        }

        $editorClause = ($this->subTable === 'page_wiki')
            ? "OR EXISTS (SELECT 1 FROM editor e WHERE e.FORpage_wiki = sub.ID AND e.FORuser = ?)"
            : "";
        $sql = $select . "
            WHERE v.state = 'public'
               OR p.FORuser = ?
               $editorClause
               OR EXISTS (SELECT 1 FROM user_permission_page upp WHERE upp.FORpage = p.ID AND upp.FORuser = ?)
            ORDER BY p.created_at DESC
        ";
        $params = ($this->subTable === 'page_wiki')
            ? [$viewerId, $viewerId, $viewerId]
            : [$viewerId, $viewerId];
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param(str_repeat('i', count($params)), ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Skapar en sida i databasen. Kastar InvalidArgumentException vid ogiltig synlighet.
     */
    public function createPage(string $title, mixed $content, int $userId, string $visibility = 'public'): int {
        $normalized = $this->normalizeVisibility($visibility);
        if ($normalized === null) {
            throw new InvalidArgumentException('Ogiltig visibility. Använd "public" eller "private"');
        }
        $visibilityId = ($normalized === 'private') ? 2 : 1;
        $contentJson = json_encode($content, JSON_UNESCAPED_UNICODE);

        $this->conn->begin_transaction();
        try {
            $stmtPage = $this->conn->prepare("INSERT INTO page (titel, content, FORuser) VALUES (?, ?, ?)");
            $stmtPage->bind_param("ssi", $title, $contentJson, $userId);
            $stmtPage->execute();
            $pageId = (int)$this->conn->insert_id;

            $sqlSub = "INSERT INTO " . $this->subTable . " (FORvisability, FORpage) VALUES (?, ?)";
            $stmtSub = $this->conn->prepare($sqlSub);
            $stmtSub->bind_param("ii", $visibilityId, $pageId);
            $stmtSub->execute();

            $this->conn->commit();
            return $pageId;
        } catch (Exception $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    /**
     * Uppdaterar titel/innehåll och sparar historik i `update_history` (old_text, removed_pic, FORuser).
     */
    public function updatePage(int $pageId, ?string $title, mixed $content, int $userId, ?string $removedPic = null): bool {
        $this->conn->begin_transaction();
        try {
            $sqlOld = "
                SELECT p.titel, p.content, sub.ID AS sub_id
                FROM page p
                JOIN " . $this->subTable . " sub ON sub.FORpage = p.ID
                WHERE p.ID = ?
            ";
            $stmtOld = $this->conn->prepare($sqlOld);
            $stmtOld->bind_param("i", $pageId);
            $stmtOld->execute();
            $oldPage = $stmtOld->get_result()->fetch_assoc();

            if (!$oldPage) {
                $this->conn->rollback();
                return false;
            }

            if ($this->subTable === 'page_wiki') {
                $oldContentArr = json_decode($oldPage['content'], true);
                if (is_array($oldContentArr)) {
                    $oldContentArr['_page_id'] = $pageId;
                    $oldText = json_encode($oldContentArr, JSON_UNESCAPED_UNICODE);
                } else {
                    $oldText = $oldPage['content'];
                }

                $stmtHist = $this->conn->prepare("
                    INSERT INTO update_history (old_text, removed_pic, FORuser)
                    VALUES (?, ?, ?)
                ");
                $stmtHist->bind_param("ssi", $oldText, $removedPic, $userId);
                $stmtHist->execute();
            }

            $newTitle = $title ?? $oldPage['titel'];
            $newContent = ($content !== null)
                ? json_encode($content, JSON_UNESCAPED_UNICODE)
                : $oldPage['content'];

            $stmtUpd = $this->conn->prepare("UPDATE page SET titel = ?, content = ? WHERE ID = ?");
            $stmtUpd->bind_param("ssi", $newTitle, $newContent, $pageId);
            $stmtUpd->execute();

            $this->conn->commit();
            return true;
        } catch (Exception $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    public function deletePage(int $pageId): bool {
        $stmtDel = $this->conn->prepare("DELETE FROM page WHERE ID = ?");
        $stmtDel->bind_param("i", $pageId);
        return $stmtDel->execute();
    }

    /**
     * ========================================================================
     * KOMMENTARSHANTERING (delas mellan Wiki och Blogg via tabellen `comment`)
     * ========================================================================
     */

    public function getComments(int $pageId): array {
        $stmt = $this->conn->prepare("
            SELECT c.ID AS comment_id, c.content, c.created_at, c.last_update,
                   c.FORuser AS user_id, u.username, c.FORpage AS page_id
            FROM comment c
            JOIN `user` u ON c.FORuser = u.ID
            WHERE c.FORpage = ?
            ORDER BY c.created_at ASC
        ");
        $stmt->bind_param("i", $pageId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($rows as &$row) {
            $row['content'] = json_decode($row['content'], true);
        }
        unset($row);

        return $rows;
    }

    public function addComment(int $pageId, int $userId, mixed $content): int {
        if (!is_array($content)) {
            $content = ['text' => (string)$content];
        }
        $contentJson = json_encode($content, JSON_UNESCAPED_UNICODE);

        $stmt = $this->conn->prepare("
            INSERT INTO comment (content, FORuser, FORpage)
            VALUES (?, ?, ?)
        ");
        $stmt->bind_param("sii", $contentJson, $userId, $pageId);
        $stmt->execute();

        return (int)$this->conn->insert_id;
    }

    public function canModifyComment(int $commentId, int $userId): bool {
        if ($this->isAdmin($userId)) {
            return true;
        }

        $stmt = $this->conn->prepare("
            SELECT c.FORuser AS comment_author, p.FORuser AS page_owner
            FROM comment c
            JOIN page p ON c.FORpage = p.ID
            WHERE c.ID = ?
        ");
        $stmt->bind_param("i", $commentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row) {
            return false;
        }

        return ((int)$row['comment_author'] === $userId) || ((int)$row['page_owner'] === $userId);
    }

    public function updateComment(int $commentId, mixed $content): bool {
        if (!is_array($content)) {
            $content = ['text' => (string)$content];
        }
        $contentJson = json_encode($content, JSON_UNESCAPED_UNICODE);

        $stmt = $this->conn->prepare("UPDATE comment SET content = ? WHERE ID = ?");
        $stmt->bind_param("si", $contentJson, $commentId);
        return $stmt->execute();
    }

    public function deleteComment(int $commentId): bool {
        $stmt = $this->conn->prepare("DELETE FROM comment WHERE ID = ?");
        $stmt->bind_param("i", $commentId);
        return $stmt->execute();
    }
}
