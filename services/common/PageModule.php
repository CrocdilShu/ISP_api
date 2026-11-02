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
    public function resolveRequestUser(?string &$denialReason = null): ?array {
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
        if ($email === '') {
            return null;
        }
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
        if (!$row) {
            return null;
        }
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
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("is", $userId, $service);
        $stmt->execute();
        return (bool)$stmt->get_result()->fetch_assoc();
    }

    public function getUserBannedServices(int $userId): array {
        $stmt = $this->conn->prepare("SELECT service FROM service_ban WHERE FORuser = ? ORDER BY service ASC");
        if (!$stmt) {
            return [];
        }
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
     * PICTURE STORAGE & SOFT-DELETE RETENTION (services/wiki/pics)
     * ========================================================================
     */
    private function getPicsBaseDir(): string {
        $dir = dirname(__DIR__) . '/' . $this->serviceType . '/pics';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    public function purgeExpiredPictures(): int {
        $days = self::PICTURE_RETENTION_DAYS;
        $stmt = $this->conn->prepare("
            SELECT ID, file_path, FORpage, filename
            FROM picture
            WHERE deleted_at IS NOT NULL
              AND deleted_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param("i", $days);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $count = 0;
        foreach ($rows as $row) {
            $relPath = !empty($row['file_path'])
                ? preg_replace('#^services/' . preg_quote($this->serviceType, '#') . '/pics/#', '', (string)$row['file_path'])
                : ('pages/' . (int)$row['FORpage'] . '/' . basename((string)$row['filename']));
            $this->picDeletePhysicalFile($relPath);

            $del = $this->conn->prepare("DELETE FROM picture WHERE ID = ?");
            if ($del) {
                $picId = (int)$row['ID'];
                $del->bind_param("i", $picId);
                $del->execute();
                $count++;
            }
        }
        return $count;
    }

    private function picWritePhysicalFile(string $subPath, array $item): string {
        $baseDir = $this->getPicsBaseDir();
        $targetDir = $baseDir . '/' . ltrim($subPath, '/');

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $filename = $item['filename'] ?? ('image_' . uniqid('', true) . '.png');
        $filename = basename($filename);
        $filePath = $targetDir . '/' . $filename;
        $relativePath = ltrim($subPath, '/') . '/' . $filename;

        if (isset($item['tmp_name']) && file_exists($item['tmp_name'])) {
            move_uploaded_file($item['tmp_name'], $filePath);
        } else {
            $data = $item['data'] ?? ($item['image_data'] ?? '');
            if (!empty($data)) {
                if (preg_match('/^data:image\/(\w+);base64,/', $data)) {
                    $data = substr($data, strpos($data, ',') + 1);
                    $data = base64_decode($data);
                } elseif (base64_decode($data, true) !== false) {
                    $data = base64_decode($data);
                }
                file_put_contents($filePath, $data);
            } else {
                if (!file_exists($filePath)) {
                    file_put_contents($filePath, '');
                }
            }
        }

        return $relativePath;
    }

    private function picDeletePhysicalFile(string $relativePath): void {
        $baseDir = $this->getPicsBaseDir();
        $fullPath = $baseDir . '/' . ltrim($relativePath, '/');
        if (file_exists($fullPath) && is_file($fullPath)) {
            @unlink($fullPath);
        }
    }

    public function savePagePictures(int $pageId, int $userId, array $items): void {
        $stmt = $this->conn->prepare("
            INSERT INTO picture (filename, mime_type, image_data, file_path, deleted_at, FORpage, FORuser)
            VALUES (?, ?, ?, ?, NULL, ?, ?)
        ");
        foreach ($items as $item) {
            if (is_string($item)) {
                $filename = basename($item);
                $relativePath = 'pages/' . $pageId . '/' . $filename;
                $fullDir = $this->getPicsBaseDir() . '/pages/' . $pageId;
                if (!is_dir($fullDir)) {
                    mkdir($fullDir, 0755, true);
                }
                $fullPath = $fullDir . '/' . $filename;
                if (!file_exists($fullPath)) {
                    file_put_contents($fullPath, '');
                }
                $mime = 'image/png';
                $data = '';
            } else {
                $relativePath = $this->picWritePhysicalFile('pages/' . $pageId, $item);
                $filename = $item['filename'] ?? basename($relativePath);
                $mime = $item['mime'] ?? ($item['mime_type'] ?? 'image/png');
                $data = $item['data'] ?? ($item['image_data'] ?? '');
            }
            $fullServicePath = 'services/' . $this->serviceType . '/pics/' . $relativePath;
            $stmt->bind_param("ssssii", $filename, $mime, $data, $fullServicePath, $pageId, $userId);
            $stmt->execute();
        }
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
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as &$r) {
            $r['picture_id'] = (int)$r['picture_id'];
            if (empty($r['file_path'])) {
                $r['file_path'] = 'services/' . $this->serviceType . '/pics/pages/' . $pageId . '/' . $r['filename'];
            }
            if (!$includeSoftDeleted) {
                unset($r['deleted_at']);
            }
        }
        unset($r);
        return $rows;
    }

    public function softDeletePictureByIdOrName(int $pageId, string $identifier): ?string {
        if (ctype_digit($identifier)) {
            $stmt = $this->conn->prepare("SELECT ID, filename FROM picture WHERE ID = ? AND FORpage = ? AND deleted_at IS NULL");
            $picId = (int)$identifier;
            $stmt->bind_param("ii", $picId, $pageId);
        } else {
            $stmt = $this->conn->prepare("SELECT ID, filename FROM picture WHERE filename = ? AND FORpage = ? AND deleted_at IS NULL");
            $stmt->bind_param("si", $identifier, $pageId);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $row) {
            $upd = $this->conn->prepare("UPDATE picture SET deleted_at = NOW() WHERE ID = ?");
            $rowId = (int)$row['ID'];
            $upd->bind_param("i", $rowId);
            $upd->execute();
        }
        return $rows ? $rows[0]['filename'] : null;
    }

    public function restorePictureByName(int $pageId, string $filename): bool {
        $stmt = $this->conn->prepare("
            UPDATE picture
            SET deleted_at = NULL
            WHERE FORpage = ? AND filename = ? AND deleted_at IS NOT NULL
        ");
        $stmt->bind_param("is", $pageId, $filename);
        $stmt->execute();
        return $stmt->affected_rows > 0;
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
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param("i", $spaceId);
        $stmt->execute();
        return array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'name');
    }

    public function addSpaceTags(int $spaceId, array $tags): array {
        foreach ($tags as $rawTag) {
            $tagName = strtolower(trim((string)$rawTag));
            if ($tagName === '') {
                continue;
            }

            $stmtIns = $this->conn->prepare("INSERT IGNORE INTO tag (name) VALUES (?)");
            if ($stmtIns) {
                $stmtIns->bind_param("s", $tagName);
                $stmtIns->execute();
            }

            $stmtGet = $this->conn->prepare("SELECT ID FROM tag WHERE name = ? LIMIT 1");
            if ($stmtGet) {
                $stmtGet->bind_param("s", $tagName);
                $stmtGet->execute();
                $tagRow = $stmtGet->get_result()->fetch_assoc();
                if ($tagRow) {
                    $tagId = (int)$tagRow['ID'];
                    $stmtLink = $this->conn->prepare("INSERT IGNORE INTO {$this->tagJoinTable} ({$this->spaceCol}, FORtag) VALUES (?, ?)");
                    if ($stmtLink) {
                        $stmtLink->bind_param("ii", $spaceId, $tagId);
                        $stmtLink->execute();
                    }
                }
            }
        }
        return $this->getSpaceTags($spaceId);
    }

    public function removeSpaceTags(int $spaceId, array $tags): array {
        foreach ($tags as $rawTag) {
            $tagName = strtolower(trim((string)$rawTag));
            if ($tagName === '') {
                continue;
            }

            $stmtDel = $this->conn->prepare("
                DELETE jt FROM {$this->tagJoinTable} jt
                JOIN tag t ON jt.FORtag = t.ID
                WHERE jt.{$this->spaceCol} = ? AND t.name = ?
            ");
            if ($stmtDel) {
                $stmtDel->bind_param("is", $spaceId, $tagName);
                $stmtDel->execute();
            }
        }
        return $this->getSpaceTags($spaceId);
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
        $space = $stmt->get_result()->fetch_assoc();
        if (!$space) {
            return null;
        }

        $space[$idAlias] = (int)$space[$idAlias];
        $space['tags'] = $this->getSpaceTags($spaceId);
        return $space;
    }

    public function canViewSpace(array $space, ?int $viewerId): bool {
        if ($space['visibility'] !== 'private') {
            return true;
        }
        if ($viewerId === null || $viewerId <= 0) {
            return false;
        }
        if ((int)$space['_owner_id'] === $viewerId || $this->isAdmin($viewerId)) {
            return true;
        }

        $spaceId = (int)$space[$this->serviceType . '_id'];
        $stmtPerm = $this->conn->prepare("SELECT ID FROM {$this->permTable} WHERE {$this->spaceCol} = ? AND FORuser = ?");
        if (!$stmtPerm) {
            return false;
        }
        $stmtPerm->bind_param("ii", $spaceId, $viewerId);
        $stmtPerm->execute();
        return (bool)$stmtPerm->get_result()->fetch_assoc();
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
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("ii", $spaceId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            return false;
        }
        if ($requireOwnerOrCoOwner) {
            return $row['role_name'] === 'co_owner';
        }
        return in_array($row['role_name'], ['editor', 'co_owner'], true);
    }

    public function canDeleteSpace(int $spaceId, int $userId): bool {
        if ($this->isAdmin($userId)) {
            return true;
        }
        $space = $this->getSpace($spaceId);
        return $space && ((int)$space['_owner_id'] === $userId);
    }

    public function createSpace(string $title, ?string $description, int $userId, string $visibility = 'public', array $tags = []): array {
        $normalized = $this->normalizeVisibility($visibility);
        if ($normalized === null) {
            throw new InvalidArgumentException('Invalid visibility');
        }
        $visId = ($normalized === 'private') ? 2 : 1;

        $stmt = $this->conn->prepare("
            INSERT INTO {$this->spaceTable} (titel, description, FORuser, FORvisability)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("ssii", $title, $description, $userId, $visId);
        $stmt->execute();
        $spaceId = (int)$this->conn->insert_id;

        if (!empty($tags)) {
            $this->addSpaceTags($spaceId, $tags);
        }

        $space = $this->getSpace($spaceId);
        unset($space['_owner_id']);
        return $space;
    }

    public function updateSpace(
        int $spaceId,
        ?string $title,
        ?string $description,
        ?string $visibility,
        array $addTags = [],
        array $removeTags = []
    ): ?array {
        $space = $this->getSpace($spaceId);
        if (!$space) {
            return null;
        }

        $newTitle = ($title !== null && trim($title) !== '') ? trim($title) : $space['titel'];
        $newDesc = $description !== null ? trim($description) : $space['description'];
        $newVis = $visibility !== null ? $this->normalizeVisibility($visibility) : $space['visibility'];
        if ($newVis === null) {
            throw new InvalidArgumentException('Invalid visibility');
        }
        $visId = ($newVis === 'private') ? 2 : 1;

        $stmt = $this->conn->prepare("
            UPDATE {$this->spaceTable}
            SET titel = ?, description = ?, FORvisability = ?
            WHERE ID = ?
        ");
        $stmt->bind_param("ssii", $newTitle, $newDesc, $visId, $spaceId);
        $stmt->execute();

        $stmtSub = $this->conn->prepare("UPDATE {$this->subTable} SET FORvisability = ? WHERE {$this->spaceCol} = ?");
        $stmtSub->bind_param("ii", $visId, $spaceId);
        $stmtSub->execute();

        if (!empty($addTags)) {
            $this->addSpaceTags($spaceId, $addTags);
        }
        if (!empty($removeTags)) {
            $this->removeSpaceTags($spaceId, $removeTags);
        }

        $updated = $this->getSpace($spaceId);
        unset($updated['_owner_id']);
        return $updated;
    }

    public function deleteSpace(int $spaceId): bool {
        $stmtPages = $this->conn->prepare("SELECT FORpage FROM {$this->subTable} WHERE {$this->spaceCol} = ?");
        $stmtPages->bind_param("i", $spaceId);
        $stmtPages->execute();
        $pages = $stmtPages->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($pages as $pg) {
            $this->deletePage((int)$pg['FORpage']);
        }

        $stmtDel = $this->conn->prepare("DELETE FROM {$this->spaceTable} WHERE ID = ?");
        $stmtDel->bind_param("i", $spaceId);
        return $stmtDel->execute();
    }

    public function listSpaces(?int $viewerId, string $search = '', string $sort = 'DESC', ?string $tagFilter = null): array {
        $order = strtoupper($sort) === 'ASC' ? 'ASC' : 'DESC';
        $params = [];
        $types = '';
        $idAlias = $this->serviceType . '_id';

        $sql = "
            SELECT s.ID AS $idAlias, s.titel, s.description, s.created_at,
                   u.email AS owner_email, v.state AS visibility
            FROM {$this->spaceTable} s
            JOIN `user` u ON s.FORuser = u.ID
            JOIN visability v ON s.FORvisability = v.ID
            WHERE 1 = 1
        ";

        if (!($viewerId && $this->isAdmin($viewerId))) {
            if ($viewerId && $viewerId > 0) {
                $sql .= " AND (
                    v.state = 'public'
                    OR s.FORuser = ?
                    OR EXISTS (SELECT 1 FROM {$this->permTable} up WHERE up.{$this->spaceCol} = s.ID AND up.FORuser = ?)
                )";
                $params[] = $viewerId;
                $params[] = $viewerId;
                $types .= 'ii';
            } else {
                $sql .= " AND v.state = 'public'";
            }
        }

        if ($search !== '') {
            $sql .= " AND (s.titel LIKE ? OR s.description LIKE ? OR u.email LIKE ?)";
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

        $sql .= " ORDER BY s.titel $order, s.created_at DESC";

        $stmt = $this->conn->prepare($sql);
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $spaces = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($spaces as &$sp) {
            $sp[$idAlias] = (int)$sp[$idAlias];
            $sp['tags'] = $this->getSpaceTags($sp[$idAlias]);
        }
        unset($sp);
        return $spaces;
    }

    /**
     * ========================================================================
     * SPACE PERMISSIONS & OWNERSHIP TRANSFER (WIKI / BLOG LEVEL)
     * ========================================================================
     */
    public function getSpacePermissions(int $spaceId): array {
        $stmt = $this->conn->prepare("
            SELECT u.email, perm.role_name AS role
            FROM {$this->permTable} up
            JOIN `user` u ON up.FORuser = u.ID
            JOIN permission perm ON up.FORpermission = perm.ID
            WHERE up.{$this->spaceCol} = ?
            ORDER BY perm.role_name ASC, u.email ASC
        ");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param("i", $spaceId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function setSpacePermission(int $spaceId, int $targetUserId, string $roleName): void {
        $stmtPermRole = $this->conn->prepare("INSERT IGNORE INTO permission (role_name) VALUES (?)");
        $stmtPermRole->bind_param("s", $roleName);
        $stmtPermRole->execute();

        $stmtGetPerm = $this->conn->prepare("SELECT ID FROM permission WHERE role_name = ? LIMIT 1");
        $stmtGetPerm->bind_param("s", $roleName);
        $stmtGetPerm->execute();
        $permId = (int)$stmtGetPerm->get_result()->fetch_assoc()['ID'];

        $stmtCheck = $this->conn->prepare("SELECT ID FROM {$this->permTable} WHERE {$this->spaceCol} = ? AND FORuser = ?");
        $stmtCheck->bind_param("ii", $spaceId, $targetUserId);
        $stmtCheck->execute();
        $existing = $stmtCheck->get_result()->fetch_assoc();

        if ($existing) {
            $upId = (int)$existing['ID'];
            $stmtUpd = $this->conn->prepare("UPDATE {$this->permTable} SET FORpermission = ? WHERE ID = ?");
            $stmtUpd->bind_param("ii", $permId, $upId);
            $stmtUpd->execute();
        } else {
            $stmtAdd = $this->conn->prepare("
                INSERT INTO {$this->permTable} ({$this->spaceCol}, FORuser, FORpermission)
                VALUES (?, ?, ?)
            ");
            $stmtAdd->bind_param("iii", $spaceId, $targetUserId, $permId);
            $stmtAdd->execute();
        }
    }

    public function removeSpacePermission(int $spaceId, int $targetUserId): bool {
        $stmtDel = $this->conn->prepare("DELETE FROM {$this->permTable} WHERE {$this->spaceCol} = ? AND FORuser = ?");
        $stmtDel->bind_param("ii", $spaceId, $targetUserId);
        $stmtDel->execute();
        return $stmtDel->affected_rows > 0;
    }

    public function transferSpaceOwnership(int $spaceId, int $newOwnerId): bool {
        $space = $this->getSpace($spaceId);
        if (!$space) {
            return false;
        }

        $this->conn->begin_transaction();
        try {
            $stmtUpd = $this->conn->prepare("UPDATE {$this->spaceTable} SET FORuser = ? WHERE ID = ?");
            $stmtUpd->bind_param("ii", $newOwnerId, $spaceId);
            $stmtUpd->execute();

            $stmtDelUp = $this->conn->prepare("DELETE FROM {$this->permTable} WHERE {$this->spaceCol} = ? AND FORuser = ?");
            $stmtDelUp->bind_param("ii", $spaceId, $newOwnerId);
            $stmtDelUp->execute();

            $this->conn->commit();
            return true;
        } catch (Exception $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    public function removeSpaceOwnership(int $spaceId): array {
        $space = $this->getSpace($spaceId);
        if (!$space) {
            return ['new_owner_email' => null, 'assigned_via' => 'not_found'];
        }

        $this->conn->begin_transaction();
        try {
            $stmtCo = $this->conn->prepare("
                SELECT up.FORuser AS co_owner_id, u.email AS co_owner_email
                FROM {$this->permTable} up
                JOIN permission perm ON up.FORpermission = perm.ID
                JOIN `user` u ON up.FORuser = u.ID
                JOIN role r ON u.FORrole = r.ID
                WHERE up.{$this->spaceCol} = ?
                  AND perm.role_name = 'co_owner'
                  AND u.username <> 'deleted_user'
                  AND r.role_name <> 'banned'
                  AND NOT EXISTS (
                      SELECT 1 FROM service_ban sb
                      WHERE sb.FORuser = u.ID AND sb.service IN (?, 'all')
                  )
                ORDER BY up.ID ASC
                LIMIT 1
            ");
            $coOwner = null;
            if ($stmtCo) {
                $stmtCo->bind_param("is", $spaceId, $this->serviceType);
                $stmtCo->execute();
                $coOwner = $stmtCo->get_result()->fetch_assoc();
            }

            if ($coOwner) {
                $newOwnerId = (int)$coOwner['co_owner_id'];
                $newOwnerEmail = (string)$coOwner['co_owner_email'];

                $stmtUpd = $this->conn->prepare("UPDATE {$this->spaceTable} SET FORuser = ? WHERE ID = ?");
                $stmtUpd->bind_param("ii", $newOwnerId, $spaceId);
                $stmtUpd->execute();

                $stmtDelUp = $this->conn->prepare("DELETE FROM {$this->permTable} WHERE {$this->spaceCol} = ? AND FORuser = ?");
                $stmtDelUp->bind_param("ii", $spaceId, $newOwnerId);
                $stmtDelUp->execute();

                $this->conn->commit();
                return ['new_owner_email' => $newOwnerEmail, 'assigned_via' => 'co_owner'];
            }

            $deletedUserId = $this->getDeletedUserId();
            $stmtUpd = $this->conn->prepare("UPDATE {$this->spaceTable} SET FORuser = ? WHERE ID = ?");
            $stmtUpd->bind_param("ii", $deletedUserId, $spaceId);
            $stmtUpd->execute();

            $this->conn->commit();
            return ['new_owner_email' => 'deleted@system.local', 'assigned_via' => 'deleted_user'];
        } catch (Exception $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    public function autoHealSpaceOwner(int $spaceId): void {
        $stmt = $this->conn->prepare("
            SELECT s.FORuser, u.username, r.role_name
            FROM {$this->spaceTable} s
            JOIN `user` u ON s.FORuser = u.ID
            JOIN role r ON u.FORrole = r.ID
            WHERE s.ID = ?
        ");
        $stmt->bind_param("i", $spaceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            return;
        }

        $ownerId = (int)$row['FORuser'];
        if ($row['role_name'] === 'banned' || $row['username'] === 'deleted_user' || $this->isBannedFromService($ownerId, $this->serviceType)) {
            $this->removeSpaceOwnership($spaceId);
        }
    }

    public function banUserFromService(int $targetUserId, string $serviceScope = 'wiki'): int {
        $serviceScope = strtolower(trim($serviceScope));
        if (!in_array($serviceScope, ['wiki', 'blog', 'all'], true)) {
            $serviceScope = $this->serviceType;
        }

        if ($serviceScope === 'all') {
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
        }

        $stmtBan = $this->conn->prepare("INSERT IGNORE INTO service_ban (FORuser, service) VALUES (?, ?)");
        if ($stmtBan) {
            $stmtBan->bind_param("is", $targetUserId, $serviceScope);
            $stmtBan->execute();
        }

        $transferred = 0;
        $servicesToTransfer = ($serviceScope === 'all') ? ['wiki', 'blog'] : [$serviceScope];

        foreach ($servicesToTransfer as $srv) {
            $mod = ($srv === $this->serviceType) ? $this : new self($this->conn, $srv);
            $stmtSpaces = $this->conn->prepare("SELECT ID FROM {$srv} WHERE FORuser = ?");
            if ($stmtSpaces) {
                $stmtSpaces->bind_param("i", $targetUserId);
                $stmtSpaces->execute();
                $rows = $stmtSpaces->get_result()->fetch_all(MYSQLI_ASSOC);
                foreach ($rows as $r) {
                    $mod->removeSpaceOwnership((int)$r['ID']);
                    $transferred++;
                }
            }
        }

        return $transferred;
    }

    public function unbanUserFromService(int $targetUserId, string $serviceScope = 'wiki'): void {
        $serviceScope = strtolower(trim($serviceScope));
        if ($serviceScope === 'all') {
            $stmtDel = $this->conn->prepare("DELETE FROM service_ban WHERE FORuser = ?");
            if ($stmtDel) {
                $stmtDel->bind_param("i", $targetUserId);
                $stmtDel->execute();
            }

            $stmtRole = $this->conn->prepare("SELECT ID FROM role WHERE role_name = 'user' LIMIT 1");
            $stmtRole->execute();
            $userRoleId = (int)($stmtRole->get_result()->fetch_assoc()['ID'] ?? 1);

            $stmtUpd = $this->conn->prepare("UPDATE `user` SET FORrole = ? WHERE ID = ?");
            $stmtUpd->bind_param("ii", $userRoleId, $targetUserId);
            $stmtUpd->execute();
        } else {
            $stmtDel = $this->conn->prepare("DELETE FROM service_ban WHERE FORuser = ? AND service = ?");
            if ($stmtDel) {
                $stmtDel->bind_param("is", $targetUserId, $serviceScope);
                $stmtDel->execute();
            }
        }
    }

    /**
     * ========================================================================
     * PAGES (INHERITING VISIBILITY & PERMISSIONS FROM FATHER WIKI / BLOG)
     * ========================================================================
     */
    public function getPage(int $pageId): ?array {
        $spaceIdAlias = $this->serviceType . '_id';
        $sql = "
            SELECT p.ID AS page_id, sub.{$this->spaceCol} AS $spaceIdAlias,
                   p.titel, p.content, p.created_at,
                   u.email AS author_email, owner_u.email AS owner_email,
                   v.state AS visibility, s.FORuser AS _space_owner_id
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

    public function canViewPage(array $page, ?int $viewerId): bool {
        $spaceId = (int)$page[$this->serviceType . '_id'];
        $space = $this->getSpace($spaceId);
        if (!$space) {
            return false;
        }
        return $this->canViewSpace($space, $viewerId);
    }

    public function canViewPageId(int $pageId, ?int $viewerId): bool {
        $page = $this->getPage($pageId);
        return $page ? $this->canViewPage($page, $viewerId) : false;
    }

    public function canModifyPage(int $pageId, int $userId, bool $requireOwnerOrCoOwner = false): bool {
        $page = $this->getPage($pageId);
        if (!$page) {
            return false;
        }
        $spaceId = (int)$page[$this->serviceType . '_id'];
        return $this->canEditSpace($spaceId, $userId, $requireOwnerOrCoOwner);
    }

    public function listPages(?int $viewerId, ?int $parentSpaceId = null, string $search = '', string $sort = 'DESC', ?string $tagFilter = null): array {
        $order = strtoupper($sort) === 'ASC' ? 'ASC' : 'DESC';
        $params = [];
        $types = '';
        $spaceIdAlias = $this->serviceType . '_id';

        $sql = "
            SELECT p.ID AS page_id, sub.{$this->spaceCol} AS $spaceIdAlias,
                   p.titel, p.content, p.created_at,
                   u.email AS author_email, owner_u.email AS owner_email,
                   v.state AS visibility
            FROM page p
            JOIN `user` u ON p.FORuser = u.ID
            JOIN {$this->subTable} sub ON sub.FORpage = p.ID
            JOIN {$this->spaceTable} s ON sub.{$this->spaceCol} = s.ID
            JOIN `user` owner_u ON s.FORuser = owner_u.ID
            JOIN visability v ON s.FORvisability = v.ID
            WHERE 1 = 1
        ";

        if (!($viewerId !== null && $viewerId > 0 && $this->isAdmin($viewerId))) {
            if ($viewerId === null || $viewerId <= 0) {
                $sql .= " AND v.state = 'public'";
            } else {
                $sql .= " AND (
                    v.state = 'public'
                    OR s.FORuser = ?
                    OR EXISTS (SELECT 1 FROM {$this->permTable} up WHERE up.{$this->spaceCol} = s.ID AND up.FORuser = ?)
                )";
                $params[] = $viewerId;
                $params[] = $viewerId;
                $types .= 'ii';
            }
        }

        if ($parentSpaceId !== null && $parentSpaceId > 0) {
            $sql .= " AND sub.{$this->spaceCol} = ?";
            $params[] = $parentSpaceId;
            $types .= 'i';
        }

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

        $visibilityId = ($space['visibility'] === 'private') ? 2 : 1;
        $plainText = $this->normalizePlainText($content);
        $contentJson = json_encode($plainText, JSON_UNESCAPED_UNICODE);

        $this->conn->begin_transaction();
        try {
            $stmtPage = $this->conn->prepare("INSERT INTO page (titel, content, FORuser) VALUES (?, ?, ?)");
            $stmtPage->bind_param("ssi", $title, $contentJson, $userId);
            $stmtPage->execute();
            $pageId = (int)$this->conn->insert_id;

            $stmtSub = $this->conn->prepare("INSERT INTO {$this->subTable} (FORvisability, FORpage, {$this->spaceCol}) VALUES (?, ?, ?)");
            $stmtSub->bind_param("iii", $visibilityId, $pageId, $spaceId);
            $stmtSub->execute();

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

            if (!$oldPage) {
                $this->conn->rollback();
                return null;
            }

            $removedPicLabel = null;
            if ($removedPic !== null && trim($removedPic) !== '') {
                $removedPicLabel = $this->softDeletePictureByIdOrName($pageId, trim($removedPic));
            }

            if (!empty($addedPictures)) {
                $this->savePagePictures($pageId, $userId, $addedPictures);
            }

            $oldPlainText = $this->normalizePlainText($oldPage['content']);
            $historyPayload = json_encode([
                '_page_id' => $pageId,
                'titel' => $oldPage['titel'],
                'content' => $oldPlainText
            ], JSON_UNESCAPED_UNICODE);

            $stmtHist = $this->conn->prepare("
                INSERT INTO update_history (old_text, removed_pic, FORuser)
                VALUES (?, ?, ?)
            ");
            $stmtHist->bind_param("ssi", $historyPayload, $removedPicLabel, $userId);
            $stmtHist->execute();

            $newTitle = ($title !== null && trim($title) !== '') ? trim($title) : $oldPage['titel'];
            $newContentJson = ($content !== null)
                ? json_encode($this->normalizePlainText($content), JSON_UNESCAPED_UNICODE)
                : $oldPage['content'];

            $stmtUpd = $this->conn->prepare("UPDATE page SET titel = ?, content = ? WHERE ID = ?");
            $stmtUpd->bind_param("ssi", $newTitle, $newContentJson, $pageId);
            $stmtUpd->execute();

            $this->conn->commit();
            $updated = $this->getPage($pageId);
            unset($updated['_space_owner_id']);
            return $updated;
        } catch (Exception $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

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

        $updatedPage = $this->updatePage($pageId, $restoredTitle, $restoredText, $userId);
        if (!$updatedPage) {
            return null;
        }

        if (!empty($entry['removed_pic'])) {
            $this->restorePictureByName($pageId, (string)$entry['removed_pic']);
            $updatedPage = $this->getPage($pageId);
            unset($updatedPage['_space_owner_id']);
        }

        return $updatedPage;
    }

    public function deletePage(int $pageId): bool {
        $stmtPics = $this->conn->prepare("UPDATE picture SET deleted_at = NOW(), FORpage = NULL WHERE FORpage = ? AND deleted_at IS NULL");
        $stmtPics->bind_param("i", $pageId);
        $stmtPics->execute();

        $stmtDel = $this->conn->prepare("DELETE FROM page WHERE ID = ?");
        $stmtDel->bind_param("i", $pageId);
        return $stmtDel->execute();
    }

    /**
     * ========================================================================
     * COMMENTS (EMAIL BASED & PLAIN TEXT)
     * ========================================================================
     */
    public function getComments(int $pageId, string $sort = 'ASC'): array {
        $order = strtoupper($sort) === 'DESC' ? 'DESC' : 'ASC';
        $stmt = $this->conn->prepare("
            SELECT c.ID AS comment_id, c.FORpage AS page_id, u.email AS author_email,
                   c.content, c.created_at, c.last_update
            FROM comment c
            JOIN `user` u ON c.FORuser = u.ID
            WHERE c.FORpage = ?
            ORDER BY c.created_at $order
        ");
        $stmt->bind_param("i", $pageId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

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
        $commentId = (int)$this->conn->insert_id;

        return [
            'comment_id' => $commentId,
            'page_id' => $pageId,
            'author_email' => $this->getEmailById($userId),
            'content' => $plainText
        ];
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

        if (!$row) {
            return false;
        }

        if ((int)$row['comment_author'] === $userId) {
            return true;
        }

        return $this->canModifyPage((int)$row['page_id'], $userId, true);
    }

    public function updateComment(int $commentId, mixed $content): string {
        $plainText = $this->normalizePlainText($content);
        $contentJson = json_encode(['text' => $plainText], JSON_UNESCAPED_UNICODE);

        $stmt = $this->conn->prepare("UPDATE comment SET content = ? WHERE ID = ?");
        $stmt->bind_param("si", $contentJson, $commentId);
        $stmt->execute();
        return $plainText;
    }

    public function deleteComment(int $commentId): bool {
        $stmt = $this->conn->prepare("DELETE FROM comment WHERE ID = ?");
        $stmt->bind_param("i", $commentId);
        return $stmt->execute();
    }
}