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
            if (is_string($pic)) {
                $filename = basename($pic);
                $mimeType = 'image/jpeg';
                $data = $pic;
            } else {
                if (!is_array($pic) || (empty($pic['data']) && empty($pic['image_data']))) { 
                    continue; 
                }
                $filename = trim((string)($pic['filename'] ?? 'image.png'));
                $mimeType = trim((string)($pic['mime_type'] ?? 'image/jpeg'));
                $data = (string)($pic['data'] ?? $pic['image_data']);
            }
            $stmt->bind_param("sssii", $filename, $mimeType, $data, $pageId, $userId);
            $stmt->execute();
        }
        $stmt->close();
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
        $res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $res;
    }

    public function deletePictureByIdOrName(int $pageId, string $identifier): ?string {
        if (ctype_digit($identifier)) {
            $picId = (int)$identifier;
            $stmt = $this->conn->prepare("SELECT filename FROM picture WHERE ID = ? AND FORpage = ?");
            $stmt->bind_param("ii", $picId, $pageId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $del = $this->conn->prepare("DELETE FROM picture WHERE ID = ? AND FORpage = ?");
                $del->bind_param("ii", $picId, $pageId);
                $del->execute();
                $del->close();
                return $row['filename'];
            }
        } else {
            $del = $this->conn->prepare("DELETE FROM picture WHERE filename = ? AND FORpage = ?");
            $del->bind_param("si", $identifier, $pageId);
            $del->execute();
            $affected = $del->affected_rows;
            $del->close();
            if ($affected > 0) {
                return $identifier;
            }
        }
        return $identifier;
    }

    /**
     * ========================================================================
     * USER RESOLUTION & AUTH
     * ========================================================================
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
        if (!$stmt) { 
            return null; 
        }
        $stmt->bind_param("s", $tokenHash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

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
        $stmt->close();
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
        $insertId = (int)$this->conn->insert_id;
        $stmtIns->close();
        return $insertId;
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
        $res = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $res;
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
        $role = $stmtRole->get_result()->fetch_assoc()['role_name'] ?? 'user';
        $stmtRole->close();
        return $role;
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
     * SIDBEHÖRIGHETER & ÄGARSKAPSÖVERFÖRING
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
        $stmtPage->close();

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
            $res = (bool)$stmtEditor->get_result()->fetch_assoc();
            $stmtEditor->close();
            if ($res) {
                return true;
            }
        }

        $stmtPerm = $this->conn->prepare("SELECT ID FROM user_permission_page WHERE FORpage = ? AND FORuser = ?");
        $stmtPerm->bind_param("ii", $pageId, $userId);
        $stmtPerm->execute();
        $res = (bool)$stmtPerm->get_result()->fetch_assoc();
        $stmtPerm->close();
        return $res;
    }

    public function getPage(int $pageId): ?array {
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
        $stmt->close();

        if (!$page) {
            return null;
        }

        $page['content'] = json_decode($page['content'], true);
        $page['pictures'] = $this->getPagePictures($pageId);
        return $page;
    }

    public function listPages(?int $viewerId, ?int $parentWikiId = null, string $search = '', string $sort = 'DESC'): array {
        $order = strtoupper($sort) === 'ASC' ? 'ASC' : 'DESC';
        $params = [];
        $types = '';

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
        $pages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($pages as &$page) {
            $page['pictures'] = $this->getPagePictures((int)$page['page_id']);
        }
        unset($page);

        return $pages;
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
            $stmtPage->close();

            if ($this->subTable === 'page_wiki') {
                $stmtSub = $this->conn->prepare("INSERT INTO page_wiki (FORvisability, FORpage, FORwiki) VALUES (?, ?, ?)");
                $stmtSub->bind_param("iii", $visibilityId, $pageId, $wikiId);
            } else {
                $stmtSub = $this->conn->prepare("INSERT INTO " . $this->subTable . " (FORvisability, FORpage) VALUES (?, ?)");
                $stmtSub->bind_param("ii", $visibilityId, $pageId);
            }
            $stmtSub->execute();
            $stmtSub->close();

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
            $stmtOld->close();

            if (!$oldPage) {
                $this->conn->rollback();
                return false;
            }

            if (!empty($addedPictures)) {
                $this->savePagePictures($pageId, $userId, $addedPictures);
            }

            $newTitle = $title ?? $oldPage['titel'];
            $newContent = ($content !== null)
                ? json_encode($content, JSON_UNESCAPED_UNICODE)
                : $oldPage['content'];

            $stmtUpd = $this->conn->prepare("UPDATE page SET titel = ?, content = ? WHERE ID = ?");
            $stmtUpd->bind_param("ssi", $newTitle, $newContent, $pageId);
            $stmtUpd->execute();
            $stmtUpd->close();

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
        $ok = $stmtDel->execute();
        $stmtDel->close();
        return $ok;
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
        $stmt->close();

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
        $insertId = (int)$this->conn->insert_id;
        $stmt->close();

        return $insertId;
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
        $stmt->close();

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
        $affected = $stmt->affected_rows >= 0;
        $stmt->close();
        return $affected;
    }

    public function deleteComment(int $commentId): bool {
        $stmt = $this->conn->prepare("DELETE FROM comment WHERE ID = ?");
        $stmt->bind_param("i", $commentId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}