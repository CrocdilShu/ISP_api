<?php

require_once __DIR__ . '/../helpers.php';

/**
 * ============================================================================
 * PageModule - Unified Handler for Wiki & Blog Spaces, Pages, Tags,
 *              Inherited Permissions, Soft-Deleted Pictures & History Rollbacks
 * ============================================================================
 */
class PageModule {
    // Change this number to control how many days removed pictures are kept on disk before permanent deletion
    public const PICTURE_RETENTION_DAYS = 7;

    private mysqli $conn;
    private string $serviceType;      // 'wiki' or 'blog'
    private string $spaceTable;       // 'wiki' or 'blog'
    private string $subTable;         // 'page_wiki' or 'page_blog'
    private string $spaceCol;         // 'FORwiki' or 'FORblog'
    private string $permTable;        // 'user_permission_wiki' or 'user_permission_blog'
    private string $tagJoinTable;     // 'wiki_tag' or 'blog_tag'

    public function __construct(mysqli $conn, string $serviceType = 'wiki') {
        $this->conn = $conn;
        $this->serviceType = strtolower($serviceType) === 'blog' ? 'blog' : 'wiki';
        $this->spaceTable = $this->serviceType;
        $this->subTable = ($this->serviceType === 'blog') ? 'page_blog' : 'page_wiki';
        $this->spaceCol = ($this->serviceType === 'blog') ? 'FORblog' : 'FORwiki';
        $this->permTable = ($this->serviceType === 'blog') ? 'user_permission_blog' : 'user_permission_wiki';
        $this->tagJoinTable = ($this->serviceType === 'blog') ? 'blog_tag' : 'wiki_tag';

        // Purge expired soft-deleted pictures older than PICTURE_RETENTION_DAYS
        $this->purgeExpiredPictures();
    }

    /**
     * ========================================================================
     * USER & EMAIL RESOLUTION + SERVICE BANS
     * ========================================================================
     */
    public function resolveRequestUser( ?string &$denialReason = null ): ?array {
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
            $denialReason = 'Missing X-User-Token header';
            return null;
        }

        $tokenHash = hash('sha256', $token);
        $stmt = $this->conn->prepare("
            SELECT u.ID, u.email, u.username, r.role_name
            FROM user_session s
            JOIN `user` u ON u.ID = s.FORuser
            JOIN role r ON r.ID = u.FORrole
            WHERE s.token_hash = ? AND s.expire_date > NOW()
            LIMIT 1
        ");
        if (!$stmt) {
            $denialReason = 'Database error while verifying session';
            return null;
        }
        $stmt->bind_param("s", $tokenHash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row) {
            $denialReason = 'Invalid or expired user token';
            return null;
        }

        if ($row['username'] === 'deleted_user' || $row['role_name'] === 'banned') {
            $denialReason = 'Your account is globally banned or deleted';
            return null;
        }

        if ($this->isBannedFromService((int)$row['ID'], $this->serviceType)) {
            $denialReason = "You are banned from using the {$this->serviceType} service";
            return null;
        }

        return [
            'id' => (int)$row['ID'],
            'email' => (string)$row['email'],
            'role' => (string)$row['role_name']
        ];
    }

    public function getUserByEmail(string $email): ?array {
        $email = trim($email);
        if ($email === '') return null;
        $stmt = $this->conn->prepare("
            SELECT u.ID AS id, u.email, u.username, r.role_name AS role
            FROM `user` u
            JOIN role r ON u.FORrole = r.ID
            WHERE u.email = ? AND u.username <> 'deleted_user'
            LIMIT 1
        ");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) return null;
        $row['id'] = (int)$row['id'];
        return $row;
    }

    public function getEmailById(int $userId): string {
        $stmt = $this->conn->prepare("SELECT email FROM `user` WHERE ID = ? LIMIT 1");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ? (string)$row['email'] : 'deleted@system.local';
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

    public function isBannedFromService(int $userId, ?string $service = null): bool {
        $service = $service ? strtolower($service) : $this->serviceType;
        if ($this->getUserRole($userId) === 'banned') {
            return true;
        }
        $stmt = $this->conn->prepare("
            SELECT 1 FROM service_ban
            WHERE FORuser = ? AND service IN (?, 'all')
            LIMIT 1
        ");
        if (!$stmt) return false;
        $stmt->bind_param("is", $userId, $service);
        $stmt->execute();
        return (bool)$stmt->get_result()->fetch_assoc();
    }

    public function getUserBannedServices(int $userId): array {
        $stmt = $this->conn->prepare("SELECT service FROM service_ban WHERE FORuser = ? ORDER BY service ASC");
        if (!$stmt) return [];
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        return array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'service');
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
     * Converts any incoming content into plain text (no block objects)
     */
    public function normalizePlainText(mixed $content): string {
        if (is_string($content)) {
            $decoded = json_decode($content, true);
            if (is_string($decoded)) {
                return $decoded;
            }
            if (is_array($decoded)) {
                return $this->extractTextFromArray($decoded);
            }
            return $content;
        }
        if (is_array($content)) {
            return $this->extractTextFromArray($content);
        }
        return (string)$content;
    }

    private function extractTextFromArray(array $arr): string {
        if (isset($arr['text']) && is_string($arr['text'])) {
            return $arr['text'];
        }
        if (isset($arr['blocks']) && is_array($arr['blocks'])) {
            $parts = [];
            foreach ($arr['blocks'] as $b) {
                if (is_array($b) && isset($b['text'])) {
                    $parts[] = (string)$b['text'];
                } elseif (is_string($b)) {
                    $parts[] = $b;
                }
            }
            return implode("\n", $parts);
        }
        return json_encode($arr, JSON_UNESCAPED_UNICODE);
    }

    /**
     * ========================================================================
     * BILDHANTERING I DATABASEN (Tabellen `picture`)
     * ========================================================================
     */
    public function savePagePictures(int $pageId, int $userId, array $pictures): void {
        $stmt = $this->conn->prepare("
            INSERT INTO picture (filename, mime_type, image_data, file_path, deleted_at, FORpage, FORuser)
            VALUES (?, ?, ?, ?, NULL, ?, ?)
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

    public function getPagePictures(int $pageId, bool $includeSoftDeleted = false): array {
        $delClause = $includeSoftDeleted ? "" : "AND p.deleted_at IS NULL";
        $stmt = $this->conn->prepare("
            SELECT p.ID AS picture_id, p.filename, p.mime_type, p.file_path,
                   p.created_at, p.deleted_at, u.email AS uploaded_by_email
            FROM picture p
            LEFT JOIN `user` u ON p.FORuser = u.ID
            WHERE p.FORpage = ? $delClause
            ORDER BY p.ID ASC
        ");
        $stmt->bind_param("i", $pageId);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $res;
    }

    /**
     * Soft-deletes a picture (sets deleted_at = NOW() and keeps file on disk for X days for history rollbacks)
     */
    public function softDeletePictureByIdOrName(int $pageId, string $identifier): ?string {
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
     * CATEGORY TAGS (FOR WIKI & BLOG)
     * ========================================================================
     */
    public function getSpaceTags(int $spaceId): array {
        $stmt = $this->conn->prepare("
            SELECT t.name
            FROM {$this->tagJoinTable} jt
            JOIN tag t ON jt.FORtag = t.ID
            WHERE jt.{$this->spaceCol} = ?
            ORDER BY t.name ASC
        ");
        if (!$stmt) return [];
        $stmt->bind_param("i", $spaceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

    public function addSpaceTags(int $spaceId, array $tags): array {
        foreach ($tags as $rawTag) {
            $tagName = strtolower(trim((string)$rawTag));
            if ($tagName === '') continue;

            $stmtIns = $this->conn->prepare("INSERT IGNORE INTO tag (name) VALUES (?)");
            $stmtIns->bind_param("s", $tagName);
            $stmtIns->execute();

            $stmtGet = $this->conn->prepare("SELECT ID FROM tag WHERE name = ? LIMIT 1");
            $stmtGet->bind_param("s", $tagName);
            $stmtGet->execute();
            $tagRow = $stmtGet->get_result()->fetch_assoc();
            if ($tagRow) {
                $tagId = (int)$tagRow['ID'];
                $stmtLink = $this->conn->prepare("INSERT IGNORE INTO {$this->tagJoinTable} ({$this->spaceCol}, FORtag) VALUES (?, ?)");
                $stmtLink->bind_param("ii", $spaceId, $tagId);
                $stmtLink->execute();
            }
        }
        return $this->getSpaceTags($spaceId);
    }

    public function removeSpaceTags(int $spaceId, array $tags): array {
        foreach ($tags as $rawTag) {
            $tagName = strtolower(trim((string)$rawTag));
            if ($tagName === '') continue;

            $stmtDel = $this->conn->prepare("
                DELETE jt FROM {$this->tagJoinTable} jt
                JOIN tag t ON jt.FORtag = t.ID
                WHERE jt.{$this->spaceCol} = ? AND t.name = ?
            ");
            $stmtDel->bind_param("is", $spaceId, $tagName);
            $stmtDel->execute();
        }
        return $this->getSpaceTags($spaceId);
    }

    public function setSpaceTags(int $spaceId, array $tags): array {
        $stmtClear = $this->conn->prepare("DELETE FROM {$this->tagJoinTable} WHERE {$this->spaceCol} = ?");
        $stmtClear->bind_param("i", $spaceId);
        $stmtClear->execute();
        return $this->addSpaceTags($spaceId, $tags);
    }

    /**
     * ========================================================================
     * SPACES (WIKIS / BLOGS) - FATHER VISIBILITY & PERMISSIONS
     * ========================================================================
     */
    public function getSpace(int $spaceId): ?array {
        $this->autoHealSpaceOwner($spaceId);

        $idAlias = $this->serviceType . '_id';
        $sql = "
            SELECT s.ID AS $idAlias, s.titel, s.description, s.created_at,
                   u.email AS owner_email, v.state AS visibility, s.FORuser AS _owner_id
            FROM {$this->spaceTable} s
            JOIN `user` u ON s.FORuser = u.ID
            JOIN visability v ON s.FORvisability = v.ID
            WHERE s.ID = ?
        ";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $spaceId);
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

    public function canEditSpace(int $spaceId, int $userId, bool $requireOwnerOrCoOwner = false): bool {
        if ($this->isAdmin($userId)) {
            return true;
        }
        $space = $this->getSpace($spaceId);
        if (!$space) {
            return false;
        }
        if ((int)$space['_owner_id'] === $userId) {
            return true;
        }

        $stmt = $this->conn->prepare("
            SELECT perm.role_name
            FROM {$this->permTable} up
            JOIN permission perm ON up.FORpermission = perm.ID
            WHERE up.{$this->spaceCol} = ? AND up.FORuser = ?
            LIMIT 1
        ");
        $stmt->bind_param("ii", $spaceId, $userId);
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

    public function canDeleteSpace(int $spaceId, int $userId): bool {
        if ($this->isAdmin($userId)) {
            return true;
        }
        $space = $this->getSpace($spaceId);
        return $space && ((int)$space['_owner_id'] === $userId);
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

    /**
     * ========================================================================
     * PAGES (INHERITING VISIBILITY & PERMISSIONS FROM FATHER WIKI / BLOG)
     * ========================================================================
     */
    public function getPage(int $pageId): ?array {
        $spaceIdAlias = $this->serviceType . '_id';
        $sql = "
            SELECT p.ID AS page_id, p.titel, p.content, p.created_at,
                   p.FORuser AS owner_id, u.username AS owner_username,
                   sub.ID AS service_page_id, $relationCol, v.state AS visibility
            FROM page p
            JOIN `user` u ON p.FORuser = u.ID
            JOIN {$this->subTable} sub ON sub.FORpage = p.ID
            JOIN {$this->spaceTable} s ON sub.{$this->spaceCol} = s.ID
            JOIN `user` owner_u ON s.FORuser = owner_u.ID
            JOIN visability v ON s.FORvisability = v.ID
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

        $spaceId = (int)$page[$spaceIdAlias];
        $this->autoHealSpaceOwner($spaceId);

        $page['page_id'] = (int)$page['page_id'];
        $page[$spaceIdAlias] = $spaceId;
        $page['content'] = $this->normalizePlainText($page['content']);
        $page['tags'] = $this->getSpaceTags($spaceId);
        $page['pictures'] = $this->getPagePictures($pageId);
        return $page;
    }

    public function listPages(?int $viewerId, ?int $parentWikiId = null, string $search = '', string $sort = 'DESC'): array {
        $order = strtoupper($sort) === 'ASC' ? 'ASC' : 'DESC';
        $params = [];
        $types = '';
        $spaceIdAlias = $this->serviceType . '_id';

        $relationCol = ($this->serviceType === 'blog') ? 'sub.FORblog AS blog_id' : 'sub.FORwiki AS wiki_id';

        $sql = "
            SELECT p.ID AS page_id, p.titel, p.created_at, p.FORuser AS owner_id,
                   u.username AS owner_username, $relationCol, v.state AS visibility
            FROM page p
            JOIN `user` u ON p.FORuser = u.ID
            JOIN {$this->subTable} sub ON sub.FORpage = p.ID
            JOIN {$this->spaceTable} s ON sub.{$this->spaceCol} = s.ID
            JOIN `user` owner_u ON s.FORuser = owner_u.ID
            JOIN visability v ON s.FORvisability = v.ID
            WHERE 1 = 1
        ";

        if ($search !== '') {
            $sql .= " AND (p.titel LIKE ? OR u.email LIKE ? OR owner_u.email LIKE ?)";
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $types .= 'sss';
        }

        if ($tagFilter !== null && trim($tagFilter) !== '') {
            $sql .= " AND EXISTS (
                SELECT 1 FROM {$this->tagJoinTable} jt
                JOIN tag t ON jt.FORtag = t.ID
                WHERE jt.{$this->spaceCol} = s.ID AND t.name = ?
            )";
            $params[] = strtolower(trim($tagFilter));
            $types .= 's';
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
            $page['page_id'] = (int)$page['page_id'];
            $page[$spaceIdAlias] = (int)$page[$spaceIdAlias];
            $page['content'] = $this->normalizePlainText($page['content']);
            $page['tags'] = $this->getSpaceTags($page[$spaceIdAlias]);
            $page['pictures'] = $this->getPagePictures($page['page_id']);
        }
        unset($page);

        return $pages;
    }

    public function createPage(
        int $spaceId,
        string $title,
        mixed $content,
        int $userId,
        array $pictures = []
    ): array {
        $space = $this->getSpace($spaceId);
        if (!$space) {
            throw new InvalidArgumentException(ucfirst($this->serviceType) . ' not found');
        }

        // Visibility is inherited from the father space
        $visibilityId = ($space['visibility'] === 'private') ? 2 : 1;
        $plainText = $this->normalizePlainText($content);
        $contentJson = json_encode($plainText, JSON_UNESCAPED_UNICODE);

        $this->conn->begin_transaction();
        try {
            $stmtPage = $this->conn->prepare("INSERT INTO page (titel, content, FORuser) VALUES (?, ?, ?)");
            $stmtPage->bind_param("ssi", $title, $contentJson, $userId);
            $stmtPage->execute();
            $pageId = (int)$this->conn->insert_id;
            $stmtPage->close();

            $stmtSub = $this->conn->prepare("INSERT INTO {$this->subTable} (FORvisability, FORpage, {$this->spaceCol}) VALUES (?, ?, ?)");
            $stmtSub->bind_param("iii", $visibilityId, $pageId, $spaceId);
            $stmtSub->execute();
            $stmtSub->close();

            if (!empty($pictures)) {
                $this->savePagePictures($pageId, $userId, $pictures);
            }

            $this->conn->commit();
            $created = $this->getPage($pageId);
            unset($created['_space_owner_id']);
            return $created;
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
    ): ?array {
        $this->conn->begin_transaction();
        try {
            $sqlOld = "
                SELECT p.titel, p.content, sub.ID AS sub_id
                FROM page p
                JOIN {$this->subTable} sub ON sub.FORpage = p.ID
                WHERE p.ID = ?
            ";
            $stmtOld = $this->conn->prepare($sqlOld);
            $stmtOld->bind_param("i", $pageId);
            $stmtOld->execute();
            $oldPage = $stmtOld->get_result()->fetch_assoc();
            $stmtOld->close();

            if (!$oldPage) {
                $this->conn->rollback();
                return null;
            }

            if (!empty($addedPictures)) {
                $this->savePagePictures($pageId, $userId, $addedPictures);
            }

            $newTitle = $title ?? $oldPage['titel'];
            $newContent = ($content !== null)
                ? json_encode($content, JSON_UNESCAPED_UNICODE)
                : $oldPage['content'];

            $stmtUpd = $this->conn->prepare("UPDATE page SET titel = ?, content = ? WHERE ID = ?");
            $stmtUpd->bind_param("ssi", $newTitle, $newContentJson, $pageId);
            $stmtUpd->execute();
            $stmtUpd->close();

            $this->conn->commit();
            $updated = $this->getPage($pageId);
            unset($updated['_space_owner_id']);
            return $updated;
        } catch (Exception $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    /**
     * Rollbacks a page to a specific history entry ID (restores title, plain text content, and soft-deleted image)
     */
    public function rollbackPageToHistory(int $historyId, int $userId): ?array {
        $stmt = $this->conn->prepare("
            SELECT ID, old_text, removed_pic
            FROM update_history
            WHERE ID = ?
            LIMIT 1
        ");
        $stmt->bind_param("i", $historyId);
        $stmt->execute();
        $entry = $stmt->get_result()->fetch_assoc();
        if (!$entry) {
            return null;
        }

        $decoded = json_decode($entry['old_text'], true);
        $pageId = is_array($decoded) ? (int)($decoded['_page_id'] ?? 0) : 0;
        if ($pageId <= 0) {
            throw new InvalidArgumentException('This history entry does not contain a valid page reference for rollback');
        }

        $restoredTitle = is_array($decoded) && isset($decoded['titel']) ? (string)$decoded['titel'] : null;
        $restoredText = is_array($decoded) && isset($decoded['content'])
            ? $this->normalizePlainText($decoded['content'])
            : $this->normalizePlainText($decoded);

        // Save current state into history and update the page with the restored text
        $updatedPage = $this->updatePage($pageId, $restoredTitle, $restoredText, $userId);
        if (!$updatedPage) {
            return null;
        }

        // If the rolled-back version had a picture that was removed in that edit, restore it from soft-delete
        if (!empty($entry['removed_pic'])) {
            $this->restorePictureByName($pageId, (string)$entry['removed_pic']);
            $updatedPage = $this->getPage($pageId);
            unset($updatedPage['_space_owner_id']);
        }

        return $updatedPage;
    }

    public function deletePage(int $pageId): bool {
        // Soft-delete pictures first so files remain for X days before physical purge
        $stmtPics = $this->conn->prepare("UPDATE picture SET deleted_at = NOW(), FORpage = NULL WHERE FORpage = ? AND deleted_at IS NULL");
        $stmtPics->bind_param("i", $pageId);
        $stmtPics->execute();

        $stmtDel = $this->conn->prepare("DELETE FROM page WHERE ID = ?");
        $stmtDel->bind_param("i", $pageId);
        $ok = $stmtDel->execute();
        $stmtDel->close();
        return $ok;
    }

    /**
     * ========================================================================
     * COMMENTS (EMAIL BASED & PLAIN TEXT)
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
            $row['comment_id'] = (int)$row['comment_id'];
            $row['page_id'] = (int)$row['page_id'];
            $row['content'] = $this->normalizePlainText($row['content']);
        }
        unset($row);

        return $rows;
    }

    public function addComment(int $pageId, int $userId, mixed $content): array {
        $plainText = $this->normalizePlainText($content);
        $contentJson = json_encode(['text' => $plainText], JSON_UNESCAPED_UNICODE);

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
            SELECT c.FORuser AS comment_author, c.FORpage AS page_id
            FROM comment c
            WHERE c.ID = ?
        ");
        $stmt->bind_param("i", $commentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return false;
        }

        if ((int)$row['comment_author'] === $userId) {
            return true;
        }

        return $this->canModifyPage((int)$row['page_id'], $userId, true);
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