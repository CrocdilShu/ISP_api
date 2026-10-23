<?php

require_once __DIR__ . '/../helpers.php';

/**
 * ============================================================================
 * PageModule - Gemensam hanterare för Wiki, Undersidor, Bilder & Kommentarer
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
     * Identifierar anropande användare ENDAST via X-User-Token (user_session -> user).
     * Om användaren är 'banned' eller 'deleted_user' returneras null.
     */
    public function resolveRequestUser(): ?int {
        $token = function_exists('getUserToken') ? getUserToken() : null;
        if ($token === null || $token === '') {
            $headers = function_exists('getallheaders') ? getallheaders() : [];
            foreach ($headers as $k => $v) {
                if (strtolower((string)$k) === 'x-user-token') {
                    $token = trim((string)$v);
                    break;
                }
            }
        }

        if ($token === null || $token === '') {
            return null;
        }

        $tokenHash = hash('sha256', $token);
        $stmt = $this->conn->prepare("
            SELECT u.ID, u.username, r.role_name
            FROM user_session s
            JOIN `user` u ON u.ID = s.FORuser
            JOIN role r ON r.ID = u.FORrole
            WHERE s.token_hash = ? AND s.expire_date > NOW()
            LIMIT 1
        ");
        if (!$stmt) { return null; }
        $stmt->bind_param("s", $tokenHash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row) {
            return null;
        }

        if ($row['username'] === 'deleted_user' || $row['role_name'] === 'banned') {
            return null;
        }

        return (int)$row['ID'];
    }

    public function getDeletedUserId(): int {
        $stmt = $this->conn->prepare("SELECT ID FROM `user` WHERE username = 'deleted_user' LIMIT 1");
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) {
            return (int)$row['ID'];
        }

        $dummyHash = '$2y$10$ierol0lKnAtitBH2SzVLFO5QImS0FYaQmsPZvoBhqjkkel5cWPHl6';
        $stmtIns = $this->conn->prepare("
            INSERT INTO `user` (username, email, password_hash, description, FORrole)
            VALUES ('deleted_user', 'deleted@system.local', ?, 'Borttagen användare', 1)
        ");
        $stmtIns->bind_param("s", $dummyHash);
        $stmtIns->execute();
        return (int)$this->conn->insert_id;
    }

    public function userExists(int $userId): bool {
        $stmt = $this->conn->prepare("
            SELECT u.ID
            FROM `user` u
            JOIN role r ON u.FORrole = r.ID
            WHERE u.ID = ? AND u.username <> 'deleted_user' AND r.role_name <> 'banned'
            LIMIT 1
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        return (bool)$stmt->get_result()->fetch_assoc();
    }

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
     * ========================================================================
     * ÖVERORDNADE WIKIS (Sub-wikis som t.ex. "Wikipedia" eller "Fandom")
     * ========================================================================
     */
    public function createWikiSpace(string $title, ?string $description, int $userId, string $visibility = 'public'): int {
        $normalized = $this->normalizeVisibility($visibility);
        if ($normalized === null) {
            throw new InvalidArgumentException('Invalid visibility');
        }
        $visId = ($normalized === 'private') ? 2 : 1;

        $stmt = $this->conn->prepare("
            INSERT INTO wiki (titel, description, FORuser, FORvisability)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("ssii", $title, $description, $userId, $visId);
        $stmt->execute();
        return (int)$this->conn->insert_id;
    }

    public function listWikiSpaces(?int $viewerId, string $search = '', string $sort = 'DESC'): array {
        $order = strtoupper($sort) === 'ASC' ? 'ASC' : 'DESC';
        $params = [];
        $types = '';

        $sql = "
            SELECT w.ID AS wiki_id, w.titel, w.description, w.created_at,
                   w.FORuser AS owner_id, u.username AS owner_username, v.state AS visibility
            FROM wiki w
            JOIN `user` u ON w.FORuser = u.ID
            JOIN visability v ON w.FORvisability = v.ID
            WHERE 1 = 1
        ";

        if (!($viewerId && $this->isAdmin($viewerId))) {
            if ($viewerId && $viewerId > 0) {
                $sql .= " AND (v.state = 'public' OR w.FORuser = ?)";
                $params[] = $viewerId;
                $types .= 'i';
            } else {
                $sql .= " AND v.state = 'public'";
            }
        }

        if ($search !== '') {
            $sql .= " AND (w.titel LIKE ? OR w.description LIKE ? OR u.username LIKE ?)";
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $types .= 'sss';
        }

        $sql .= " ORDER BY w.titel $order, w.created_at DESC";

        $stmt = $this->conn->prepare($sql);
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * ========================================================================
     * BILDHANTERING I DATABASEN (Tabellen `picture`)
     * ========================================================================
     */
    public function savePagePictures(int $pageId, int $userId, array $pictures): void {
        $stmt = $this->conn->prepare("
            INSERT INTO picture (filename, mime_type, image_data, FORpage, FORuser)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($pictures as $pic) {
            if (!is_array($pic) || empty($pic['data'])) { continue; }
            $filename = trim((string)($pic['filename'] ?? 'image.png'));
            $mimeType = trim((string)($pic['mime_type'] ?? 'image/png'));
            $data = (string)$pic['data'];
            $stmt->bind_param("sssii", $filename, $mimeType, $data, $pageId, $userId);
            $stmt->execute();
        }
    }

    public function getPagePictures(int $pageId): array {
        $stmt = $this->conn->prepare("
            SELECT ID AS picture_id, filename, mime_type, image_data, created_at, FORuser AS uploaded_by
            FROM picture
            WHERE FORpage = ?
            ORDER BY ID ASC
        ");
        $stmt->bind_param("i", $pageId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function deletePictureByIdOrName(int $pageId, string $identifier): ?string {
        if (ctype_digit($identifier)) {
            $picId = (int)$identifier;
            $stmt = $this->conn->prepare("SELECT filename FROM picture WHERE ID = ? AND FORpage = ?");
            $stmt->bind_param("ii", $picId, $pageId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) {
                $del = $this->conn->prepare("DELETE FROM picture WHERE ID = ? AND FORpage = ?");
                $del->bind_param("ii", $picId, $pageId);
                $del->execute();
                return $row['filename'];
            }
        } else {
            $del = $this->conn->prepare("DELETE FROM picture WHERE filename = ? AND FORpage = ?");
            $del->bind_param("si", $identifier, $pageId);
            $del->execute();
            if ($del->affected_rows > 0) {
                return $identifier;
            }
        }
        return $identifier;
    }

    /**
     * ========================================================================
     * SIDBEHÖRIGHETER & ÄGARSKAPSÖVERFÖRING (Owner / Co-Owner / Admin / Banned)
     * ========================================================================
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

    public function transferPageOwnership(int $pageId, int $newOwnerId): bool {
        $page = $this->getPage($pageId);
        if (!$page) {
            return false;
        }

        $wikiId = (int)($page['service_page_id'] ?? 0);

        $this->conn->begin_transaction();
        try {
            $stmtUpd = $this->conn->prepare("UPDATE page SET FORuser = ? WHERE ID = ?");
            $stmtUpd->bind_param("ii", $newOwnerId, $pageId);
            $stmtUpd->execute();

            $stmtDelUpp = $this->conn->prepare("DELETE FROM user_permission_page WHERE FORpage = ? AND FORuser = ?");
            $stmtDelUpp->bind_param("ii", $pageId, $newOwnerId);
            $stmtDelUpp->execute();

            if ($this->subTable === 'page_wiki') {
                $stmtDelEd = $this->conn->prepare("DELETE FROM editor WHERE FORpage_wiki = ? AND FORuser = ?");
                $stmtDelEd->bind_param("ii", $wikiId, $newOwnerId);
                $stmtDelEd->execute();
            }

            $this->conn->commit();
            return true;
        } catch (Exception $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    public function removePageOwnership(int $pageId): array {
        $stmtPage = $this->conn->prepare("
            SELECT p.ID AS page_id, sub.ID AS service_page_id
            FROM page p
            JOIN " . $this->subTable . " sub ON sub.FORpage = p.ID
            WHERE p.ID = ?
        ");
        $stmtPage->bind_param("i", $pageId);
        $stmtPage->execute();
        $page = $stmtPage->get_result()->fetch_assoc();

        if (!$page) {
            return ['new_owner_id' => null, 'promoted_from' => 'not_found'];
        }

        $wikiId = (int)($page['service_page_id'] ?? 0);

        $this->conn->begin_transaction();
        try {
            $stmtCo = $this->conn->prepare("
                SELECT upp.ID AS upp_id, upp.FORuser AS co_owner_id
                FROM user_permission_page upp
                JOIN permission perm ON upp.FORpermission = perm.ID
                JOIN `user` u ON upp.FORuser = u.ID
                JOIN role r ON u.FORrole = r.ID
                WHERE upp.FORpage = ?
                  AND perm.role_name = 'co_owner'
                  AND u.username <> 'deleted_user'
                  AND r.role_name <> 'banned'
                ORDER BY upp.ID ASC
                LIMIT 1
            ");
            $stmtCo->bind_param("i", $pageId);
            $stmtCo->execute();
            $coOwner = $stmtCo->get_result()->fetch_assoc();

            if ($coOwner) {
                $newOwnerId = (int)$coOwner['co_owner_id'];
                $stmtUpd = $this->conn->prepare("UPDATE page SET FORuser = ? WHERE ID = ?");
                $stmtUpd->bind_param("ii", $newOwnerId, $pageId);
                $stmtUpd->execute();

                $stmtDelUpp = $this->conn->prepare("DELETE FROM user_permission_page WHERE FORpage = ? AND FORuser = ?");
                $stmtDelUpp->bind_param("ii", $pageId, $newOwnerId);
                $stmtDelUpp->execute();

                if ($this->subTable === 'page_wiki') {
                    $stmtDelEd = $this->conn->prepare("DELETE FROM editor WHERE FORpage_wiki = ? AND FORuser = ?");
                    $stmtDelEd->bind_param("ii", $wikiId, $newOwnerId);
                    $stmtDelEd->execute();
                }

                $this->conn->commit();
                return ['new_owner_id' => $newOwnerId, 'promoted_from' => 'co_owner'];
            }

            $deletedUserId = $this->getDeletedUserId();
            $stmtUpd = $this->conn->prepare("UPDATE page SET FORuser = ? WHERE ID = ?");
            $stmtUpd->bind_param("ii", $deletedUserId, $pageId);
            $stmtUpd->execute();

            $this->conn->commit();
            return ['new_owner_id' => $deletedUserId, 'promoted_from' => 'deleted_user'];
        } catch (Exception $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    public function autoHealPageOwner(int $pageId): void {
        $stmt = $this->conn->prepare("
            SELECT p.FORuser, u.username, r.role_name
            FROM page p
            JOIN `user` u ON p.FORuser = u.ID
            JOIN role r ON u.FORrole = r.ID
            WHERE p.ID = ?
        ");
        $stmt->bind_param("i", $pageId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row) {
            return;
        }

        if ($row['role_name'] === 'banned' || $row['username'] === 'deleted_user') {
            $this->removePageOwnership($pageId);
        }
    }

    public function banUserAndTransferWikis(int $targetUserId): int {
        $this->conn->query("INSERT IGNORE INTO role (ID, role_name) VALUES (4, 'banned')");
        $stmtRole = $this->conn->prepare("SELECT ID FROM role WHERE role_name = 'banned' LIMIT 1");
        $stmtRole->execute();
        $bannedRoleId = (int)($stmtRole->get_result()->fetch_assoc()['ID'] ?? 4);

        $stmtUpd = $this->conn->prepare("UPDATE `user` SET FORrole = ? WHERE ID = ?");
        $stmtUpd->bind_param("ii", $bannedRoleId, $targetUserId);
        $stmtUpd->execute();

        $stmtSess = $this->conn->prepare("DELETE FROM user_session WHERE FORuser = ?");
        $stmtSess->bind_param("i", $targetUserId);
        $stmtSess->execute();

        $stmtPages = $this->conn->prepare("
            SELECT p.ID
            FROM page p
            JOIN page_wiki pw ON pw.FORpage = p.ID
            WHERE p.FORuser = ?
        ");
        $stmtPages->bind_param("i", $targetUserId);
        $stmtPages->execute();
        $pages = $stmtPages->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($pages as $pg) {
            $this->removePageOwnership((int)$pg['ID']);
        }

        return count($pages);
    }

    /**
     * ========================================================================
     * SIDOR (CRUD, Sökning & Sortering)
     * ========================================================================
     */
    public function getPage(int $pageId): ?array {
        $this->autoHealPageOwner($pageId);

        // Dynamisk kolumn beroende på om det är blogg eller wiki
        $relationCol = ($this->serviceType === 'blog') ? 'sub.FORblog AS blog_id' : 'sub.FORwiki AS wiki_id';

        $sql = "
            SELECT p.ID AS page_id, p.titel, p.content, p.created_at,
                   p.FORuser AS owner_id, u.username AS owner_username,
                   sub.ID AS service_page_id, $relationCol, v.state AS visibility
            FROM page p
            JOIN `user` u ON p.FORuser = u.ID
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
        $page['pictures'] = $this->getPagePictures($pageId);
        return $page;
    }

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

    public function canViewPageId(int $pageId, ?int $viewerId): bool {
        $page = $this->getPage($pageId);
        return $page ? $this->canViewPage($page, $viewerId) : false;
    }

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

    public function listPages(?int $viewerId, ?int $parentWikiId = null, string $search = '', string $sort = 'DESC'): array {
        $order = strtoupper($sort) === 'ASC' ? 'ASC' : 'DESC';
        $params = [];
        $types = '';

        // Dynamisk kolumn beroende på om det är blogg eller wiki
        $relationCol = ($this->serviceType === 'blog') ? 'sub.FORblog AS blog_id' : 'sub.FORwiki AS wiki_id';

        $sql = "
            SELECT p.ID AS page_id, p.titel, p.created_at, p.FORuser AS owner_id,
                   u.username AS owner_username, $relationCol, v.state AS visibility
            FROM page p
            JOIN `user` u ON p.FORuser = u.ID
            JOIN " . $this->subTable . " sub ON sub.FORpage = p.ID
            JOIN visability v ON sub.FORvisability = v.ID
            WHERE 1 = 1
        ";

        if (!($viewerId !== null && $viewerId > 0 && $this->isAdmin($viewerId))) {
            if ($viewerId === null || $viewerId <= 0) {
                $sql .= " AND v.state = 'public'";
            } else {
                $editorClause = ($this->subTable === 'page_wiki')
                    ? "OR EXISTS (SELECT 1 FROM editor e WHERE e.FORpage_wiki = sub.ID AND e.FORuser = ?)"
                    : "";
                $sql .= " AND (
                    v.state = 'public'
                    OR p.FORuser = ?
                    $editorClause
                    OR EXISTS (SELECT 1 FROM user_permission_page upp WHERE upp.FORpage = p.ID AND upp.FORuser = ?)
                )";
                if ($this->subTable === 'page_wiki') {
                    $params[] = $viewerId;
                    $params[] = $viewerId;
                    $params[] = $viewerId;
                    $types .= 'iii';
                } else {
                    $params[] = $viewerId;
                    $params[] = $viewerId;
                    $types .= 'ii';
                }
            }
        }

        if ($parentWikiId !== null && $parentWikiId > 0) {
            $filterCol = ($this->serviceType === 'blog') ? 'sub.FORblog' : 'sub.FORwiki';
            $sql .= " AND $filterCol = ?";
            $params[] = $parentWikiId;
            $types .= 'i';
        }

        if ($search !== '') {
            $sql .= " AND (p.titel LIKE ? OR u.username LIKE ?)";
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $types .= 'ss';
        }

        $sql .= " ORDER BY p.titel $order, p.created_at DESC";

        $stmt = $this->conn->prepare($sql);
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function createPage(
        string $title,
        mixed $content,
        int $userId,
        string $visibility = 'public',
        ?int $wikiId = null,
        array $pictures = []
    ): int {
        $normalized = $this->normalizeVisibility($visibility);
        if ($normalized === null) {
            throw new InvalidArgumentException('Invalid visibility');
        }
        $visibilityId = ($normalized === 'private') ? 2 : 1;
        $contentJson = json_encode($content, JSON_UNESCAPED_UNICODE);

        $this->conn->begin_transaction();
        try {
            $stmtPage = $this->conn->prepare("INSERT INTO page (titel, content, FORuser) VALUES (?, ?, ?)");
            $stmtPage->bind_param("ssi", $title, $contentJson, $userId);
            $stmtPage->execute();
            $pageId = (int)$this->conn->insert_id;

            if ($this->subTable === 'page_wiki') {
                $stmtSub = $this->conn->prepare("INSERT INTO page_wiki (FORvisability, FORpage, FORwiki) VALUES (?, ?, ?)");
                $stmtSub->bind_param("iii", $visibilityId, $pageId, $wikiId);
            } else {
                $stmtSub = $this->conn->prepare("INSERT INTO " . $this->subTable . " (FORvisability, FORpage) VALUES (?, ?)");
                $stmtSub->bind_param("ii", $visibilityId, $pageId);
            }
            $stmtSub->execute();

            if (!empty($pictures)) {
                $this->savePagePictures($pageId, $userId, $pictures);
            }

            $this->conn->commit();
            return $pageId;
        } catch (Exception $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    public function updatePage(
        int $pageId,
        ?string $title,
        mixed $content,
        int $userId,
        ?string $removedPic = null,
        array $addedPictures = []
    ): bool {
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

            $removedPicLabel = null;
            if ($removedPic !== null && trim($removedPic) !== '') {
                $removedPicLabel = $this->deletePictureByIdOrName($pageId, trim($removedPic));
            }

            if (!empty($addedPictures)) {
                $this->savePagePictures($pageId, $userId, $addedPictures);
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
                $stmtHist->bind_param("ssi", $oldText, $removedPicLabel, $userId);
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
     * KOMMENTARSHANTERING
     * ========================================================================
     */
public function getComments(int $pageId, string $sort = 'ASC', ?int $limit = null): array {
        $order = strtoupper($sort) === 'DESC' ? 'DESC' : 'ASC';
        $sql = "
            SELECT c.ID AS comment_id, c.content, c.created_at, c.last_update,
                   c.FORuser AS user_id, u.username, c.FORpage AS page_id
            FROM comment c
            JOIN `user` u ON c.FORuser = u.ID
            WHERE c.FORpage = ?
            ORDER BY c.created_at $order
        ";

        if ($limit !== null && $limit > 0) {
            $sql .= " LIMIT " . (int)$limit;
        }

        $stmt = $this->conn->prepare($sql);
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
        $stmt->execute();
        return $stmt->affected_rows >= 0;
    }

    public function deleteComment(int $commentId): bool {
        $stmt = $this->conn->prepare("DELETE FROM comment WHERE ID = ?");
        $stmt->bind_param("i", $commentId);
        return $stmt->execute();
    }
}