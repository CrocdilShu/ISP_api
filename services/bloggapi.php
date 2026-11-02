<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-User-Token, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/common/PageModule.php';

// =====================================================================
// Hjälpfunktioner (skyddade mot dubbeldeklaration)
// =====================================================================

if (!function_exists('respond')) {
    function respond(int $code, array $data): void {
        http_response_code($code);
        echo json_encode($data);
        exit;
    }
}


if (!function_exists('saveUploadedImage')) {
    function saveUploadedImage(array $file): string {
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        ];

        if ($file['size'] > 5 * 1024 * 1024) {
            throw new Exception("Bilden är för stor (max 5 MB).");
        }

        $mime = detectMimeType($file['tmp_name']);
        if (!isset($allowed[$mime])) {
            throw new Exception("Ogiltig filtyp. Tillåtna: JPG, PNG, GIF, WEBP.");
        }

        $dir = dirname(__DIR__) . '/uploads/';
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new Exception("Kunde inte skapa uppladdningsmappen.");
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
            throw new Exception("Kunde inte spara bilden.");
        }

        return 'uploads/' . $filename;
    }
}

if (!function_exists('respondError')) {
    function respondError(int $code, string $message): void {
        respond($code, ["status" => "error", "message" => $message]);
    }
}

if (!function_exists('respondSuccess')) {
    function respondSuccess(array $extra = []): void {
        respond(200, array_merge(["status" => "success"], $extra));
    }
}

if (!function_exists('requireUser')) {
    function requireUser(?int $userId, string $message = "Obehörig. X-User-Token saknas eller är ogiltig."): void {
        if (!$userId) {
            respondError(401, $message);
        }
    }
}

if (!function_exists('fetchBlogOwnerId')) {
    function fetchBlogOwnerId(mysqli $conn, int $blogId): ?int {
        $stmt = $conn->prepare("SELECT FORuser FROM blog WHERE ID = ? LIMIT 1");
        $stmt->bind_param("i", $blogId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['FORuser'] : null;
    }
}

if (!function_exists('assertBlogOwner')) {
    function assertBlogOwner(mysqli $conn, int $blogId, int $userId, string $forbiddenMessage): void {
        $ownerId = fetchBlogOwnerId($conn, $blogId);
        if ($ownerId === null) {
            respondError(404, "Bloggen hittades inte");
        }
        if ($ownerId !== $userId) {
            respondError(403, $forbiddenMessage);
        }
    }
}

if (!function_exists('visibilityToId')) {
    function visibilityToId(PageModule $module, $visibility): int {
        return $module->normalizeVisibility($visibility) === 'private' ? 2 : 1;
    }
}

if (!function_exists('inputString')) {
    function inputString(array $data, string $key): string {
        return trim((string)($data[$key] ?? ''));
    }
}

if (!function_exists('detectMimeType')) {
    function detectMimeType(string $path): string {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        return $mime !== false ? $mime : 'application/octet-stream';
    }
}

// =====================================================================
// Tagg-hantering
// =====================================================================

if (!function_exists('syncBlogTags')) {
    function syncBlogTags(mysqli $conn, int $blogId, array $tags): void {
        $stmtDel = $conn->prepare("DELETE FROM blog_tag WHERE FORblog = ?");
        $stmtDel->bind_param("i", $blogId);
        $stmtDel->execute();
        $stmtDel->close();

        if (empty($tags)) {
            return;
        }

        $stmtFind = $conn->prepare("SELECT ID FROM tag WHERE name = ? LIMIT 1");
        $stmtInsTag = $conn->prepare("INSERT INTO tag (name) VALUES (?)");
        $stmtLink = $conn->prepare("INSERT INTO blog_tag (FORblog, FORtag) VALUES (?, ?)");

        foreach ($tags as $tagName) {
            $cleanName = strtolower(trim((string)$tagName));
            if ($cleanName === '') {
                continue;
            }

            $stmtFind->bind_param("s", $cleanName);
            $stmtFind->execute();
            $res = $stmtFind->get_result()->fetch_assoc();

            if ($res) {
                $tagId = (int)$res['ID'];
            } else {
                $stmtInsTag->bind_param("s", $cleanName);
                $stmtInsTag->execute();
                $tagId = (int)$conn->insert_id;
            }

            $stmtLink->bind_param("ii", $blogId, $tagId);
            $stmtLink->execute();
        }

        $stmtFind->close();
        $stmtInsTag->close();
        $stmtLink->close();
    }
}

if (!function_exists('getBlogTags')) {
    function getBlogTags(mysqli $conn, int $blogId): array {
        $stmt = $conn->prepare("
            SELECT t.name 
            FROM tag t
            JOIN blog_tag bt ON bt.FORtag = t.ID
            WHERE bt.FORblog = ?
        ");
        $stmt->bind_param("i", $blogId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return array_column($rows, 'name');
    }
}

// =====================================================================
// Initiering
// =====================================================================

$method    = $_SERVER['REQUEST_METHOD'];
$inputData = json_decode(file_get_contents("php://input"), true) ?? [];
$action    = $_GET['action'] ?? '';

$blogModule = new PageModule($conn, 'blog');
$userId     = $blogModule->resolveRequestUser();

switch ($method) {

    // =================================================================
    // GET: Hämta bloggar och inlägg
    // =================================================================
    case 'GET':
if ($action === 'listBlogs') {
            $tagFilter = trim($_GET['tag'] ?? '');

            if ($tagFilter !== '') {
                // Filtrera på en specifik tagg
                $stmt = $conn->prepare("
                    SELECT DISTINCT b.ID AS blog_id, b.titel, b.description, b.created_at, u.username AS owner 
                    FROM blog b 
                    JOIN `user` u ON b.FORuser = u.ID
                    JOIN blog_tag bt ON bt.FORblog = b.ID
                    JOIN tag t ON bt.FORtag = t.ID
                    WHERE t.name = ?
                ");
                $stmt->bind_param("s", $tagFilter);
                $stmt->execute();
                $blogs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            } else {
                // Hämta alla bloggar om ingen tagg angivits
                $blogs = $conn->query("
                    SELECT b.ID AS blog_id, b.titel, b.description, b.created_at, u.username AS owner 
                    FROM blog b 
                    JOIN `user` u ON b.FORuser = u.ID
                ")->fetch_all(MYSQLI_ASSOC);
            }

            foreach ($blogs as &$b) {
                $b['tags'] = getBlogTags($conn, (int)$b['blog_id']);
            }
            unset($b);

            respondSuccess(["data" => $blogs]);
        }
        if ($action === 'getBlog' && isset($_GET['title'])) {
            $searchTitle = "%" . trim($_GET['title']) . "%";
            $stmt = $conn->prepare("
                SELECT pb.ID AS blog_id, pb.last_update, pb.FORvisability, pb.FORpage,
                       p.titel, p.content, p.created_at, p.FORuser, u.username AS author
                FROM page_blog pb
                JOIN page p ON pb.FORpage = p.ID
                LEFT JOIN `user` u ON u.ID = p.FORuser
                WHERE p.titel LIKE ?
                LIMIT 1
            ");
            $stmt->bind_param("s", $searchTitle);
            $stmt->execute();
            $blog = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$blog) {
                respondError(404, "Bloggen hittades inte");
            }
            respondSuccess(["data" => $blog]);
        }

        $commentLimit = isset($_GET['limit']) ? (int)$_GET['limit'] : null;

        if (isset($_GET['blog_id'])) {
            $blogId = (int)$_GET['blog_id'];
            $stmt = $conn->prepare("
                SELECT p.ID AS page_id, p.titel, p.content, p.created_at, p.FORuser AS owner_id,
                       u.username AS owner_username, pb.ID AS blog_id_rel, pb.FORblog AS blog_ref_id,
                       v.state AS visibility
                FROM page p
                JOIN `user` u ON p.FORuser = u.ID
                JOIN page_blog pb ON pb.FORpage = p.ID
                JOIN visability v ON pb.FORvisability = v.ID
                WHERE pb.FORblog = ?
            ");
            $stmt->bind_param("i", $blogId);
            $stmt->execute();
            $posts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            foreach ($posts as &$post) {
                $post['content']  = json_decode($post['content'], true);
                $post['pictures'] = $blogModule->getPagePictures((int)$post['page_id']);
                $post['comments'] = $blogModule->getComments((int)$post['page_id'], 'ASC', $commentLimit);
            }
            unset($post);

            respondSuccess(["data" => $posts]);
        }

        if (isset($_GET['id'])) {
            $id   = (int)$_GET['id'];
            $post = $blogModule->getPage($id);

            if (!$post) {
                respondError(404, "Inlägget hittades inte");
            }

            $post['comments'] = $blogModule->getComments($id, 'ASC', $commentLimit);
            respondSuccess(["data" => $post]);
        }

        respondSuccess(["data" => $blogModule->listPages($userId)]);
        break;

    // =================================================================
    // POST: Skapa blogg, inlägg eller kommentar
    // =================================================================
    case 'POST':
        requireUser($userId);

        if ($action === 'createBlog') {
            $title = trim($inputData['titel'] ?? '');
            $description = trim($inputData['description'] ?? null);
            $visibility = $inputData['visibility'] ?? 'public';
            $tags = $inputData['tags'] ?? [];

            if ($title === '') {
                respondError(400, "Bloggens titel krävs!");
            }

            try {
                $normalized = $blogModule->normalizeVisibility($visibility);
                $visId = ($normalized === 'private') ? 2 : 1;

                $stmt = $conn->prepare("INSERT INTO blog (titel, description, FORuser, FORvisability) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssii", $title, $description, $userId, $visId);
                $stmt->execute();
                $blogId = (int)$conn->insert_id;
                $stmt->close();

                if (!empty($tags) && is_array($tags)) {
                    syncBlogTags($conn, $blogId, $tags);
                }

                respondSuccess([
                    "message" => "Blogg skapad med taggar!", 
                    "blog_id" => $blogId,
                    "tags" => getBlogTags($conn, $blogId)
                ]);
            } catch (Exception $e) {
                respondError(500, $e->getMessage());
            }
        }

        if ($action === 'createPost') {
            $blogId = 0;
            if (!empty($_POST['blog_title'])) {
                $stmtBlog = $conn->prepare("SELECT ID FROM blog WHERE titel = ? LIMIT 1");
                $stmtBlog->bind_param("s", $_POST['blog_title']);
                $stmtBlog->execute();
                $resBlog = $stmtBlog->get_result()->fetch_assoc();
                $stmtBlog->close();
                if ($resBlog) {
                    $blogId = (int)$resBlog['ID'];
                }
            } elseif (!empty($_POST['blog_id'])) {
                $blogId = (int)$_POST['blog_id'];
            }

            $title    = inputString($_POST, 'titel');
            $content  = $_POST['content'] ?? '';
            $visInput = $_POST['FORvisability'] ?? $_POST['visibility'] ?? 'public';

            $visibility = is_numeric($visInput) ? (((int)$visInput === 2) ? 'private' : 'public') : $visInput;

            if ($blogId <= 0 || $title === '' || empty($content)) {
                respondError(400, "Kunde inte hitta bloggen! Ange 'blog_title', samt titel och content.");
            }

            $savedImagePaths = [];
            $uploadedName    = null;
            $uploadedMime    = null;

            if (!empty($_FILES['picture']) && $_FILES['picture']['error'] === UPLOAD_ERR_OK) {
                try {
                    $uploadedName = basename($_FILES['picture']['name']);
                    $uploadedMime = detectMimeType($_FILES['picture']['tmp_name']);
                    $savedImagePaths[] = saveUploadedImage($_FILES['picture']);
                } catch (Exception $e) {
                    respondError(400, $e->getMessage());
                }
            }

            try {
                $pageId = $blogModule->createPage($title, $content, $userId, $visibility, null, $savedImagePaths);

                $stmtSub = $conn->prepare("UPDATE page_blog SET FORblog = ? WHERE FORpage = ?");
                $stmtSub->bind_param("ii", $blogId, $pageId);
                $stmtSub->execute();
                $stmtSub->close();

                if (!empty($savedImagePaths)) {
                    $imgPath = $savedImagePaths[0];
                    $stmtPic = $conn->prepare("INSERT INTO picture (filename, mime_type, image_data, FORpage) VALUES (?, ?, ?, ?)");
                    if ($stmtPic) {
                        $stmtPic->bind_param("sssi", $uploadedName, $uploadedMime, $imgPath, $pageId);
                        $stmtPic->execute();
                        $stmtPic->close();
                    }
                }

                respondSuccess([
                    "message" => "Inlägg skapat!",
                    "page_id" => $pageId,
                    "blog_id" => $blogId,
                ]);
            } catch (Exception $e) {
                respondError(500, $e->getMessage());
            }
        }

        if ($action === 'addComment') {
            $pageId = (int)($inputData['page_id'] ?? 0);

            if ($pageId <= 0 && !empty($inputData['titel'])) {
                $stmtFind = $conn->prepare("SELECT ID FROM page WHERE titel = ? LIMIT 1");
                $stmtFind->bind_param("s", $inputData['titel']);
                $stmtFind->execute();
                $resFind = $stmtFind->get_result()->fetch_assoc();
                $stmtFind->close();
                if ($resFind) {
                    $pageId = (int)$resFind['ID'];
                }
            }

            $commentText = inputString($inputData, 'comment');

            if ($pageId <= 0 || $commentText === '') {
                respondError(400, "Kunde inte hitta inlägget! Ange 'titel' (eller 'page_id'), samt 'comment'.");
            }

            try {
                $commentId = $blogModule->addComment($pageId, $userId, $commentText);
                respondSuccess([
                    "message" => "Kommentar tillagd!",
                    "comment_id" => $commentId,
                    "page_id" => $pageId,
                ]);
            } catch (Exception $e) {
                respondError(500, $e->getMessage());
            }
        }

        respondError(400, "Okänd action för POST.");
        break;

    // =================================================================
    // PUT: Uppdatera blogg, inlägg eller kommentar
    // =================================================================
    case 'PUT':
        requireUser($userId);

        if ($action === 'updateBlog') {
            $blogId      = (int)($inputData['blog_id'] ?? 0);
            $title       = inputString($inputData, 'titel');
            $description = inputString($inputData, 'description');
            $visibility  = $inputData['visibility'] ?? 'public';
            $tags        = $inputData['tags'] ?? null;

            if ($blogId <= 0 || $title === '') {
                respondError(400, "blog_id och titel krävs för att uppdatera bloggen!");
            }

            assertBlogOwner($conn, $blogId, $userId, "Endast ägaren kan uppdatera denna blogg");

            try {
                $visId = visibilityToId($blogModule, $visibility);
                $stmtUpd = $conn->prepare("UPDATE blog SET titel = ?, description = ?, FORvisability = ? WHERE ID = ?");
                $stmtUpd->bind_param("ssii", $title, $description, $visId, $blogId);
                $stmtUpd->execute();
                $stmtUpd->close();

                if (is_array($tags)) {
                    syncBlogTags($conn, $blogId, $tags);
                }

                respondSuccess([
                    "message" => "Bloggen har uppdaterats!",
                    "tags" => getBlogTags($conn, $blogId)
                ]);
            } catch (Exception $e) {
                respondError(500, $e->getMessage());
            }
        }

        if ($action === 'updateComment') {
            $commentId  = (int)($inputData['comment_id'] ?? 0);
            $newContent = $inputData['comment'] ?? '';

            if ($commentId <= 0 || $newContent === '') {
                respondError(400, "comment_id och ny kommentarstext krävs!");
            }

            if (!$blogModule->canModifyComment($commentId, $userId)) {
                respondError(403, "Endast den som skrev kommentaren (eller inläggets ägare/admin) får ändra den.");
            }

            try {
                $blogModule->updateComment($commentId, $newContent);
                respondSuccess(["message" => "Kommentaren har uppdaterats!"]);
            } catch (Exception $e) {
                respondError(500, $e->getMessage());
            }
        }

        $pageId = (int)($inputData['page_id'] ?? 0);
        if ($pageId <= 0) {
            respondError(400, "page_id krävs för att uppdatera inlägg!");
        }

        if (!$blogModule->canModify($pageId, $userId, true)) {
            respondError(403, "Endast ägaren eller en administratör kan uppdatera detta inlägg");
        }

        try {
            $title      = $inputData['titel'] ?? null;
            $content    = $inputData['content'] ?? null;
            $removedPic = $inputData['removed_picture'] ?? null;
            $addedPics  = $inputData['pictures'] ?? [];

            $blogModule->updatePage($pageId, $title, $content, $userId, $removedPic, $addedPics);
            respondSuccess(["message" => "Inlägget har uppdaterats!"]);
        } catch (Exception $e) {
            respondError(500, $e->getMessage());
        }
        break;

    // =================================================================
    // DELETE: Ta bort blogg, inlägg eller kommentar
    // =================================================================
    case 'DELETE':
        requireUser($userId, "Obehörig.");

        if ($action === 'deleteComment') {
            $commentId = (int)($inputData['comment_id'] ?? 0);

            if ($commentId <= 0) {
                respondError(400, "comment_id krävs för att ta bort kommentaren!");
            }

            if (!$blogModule->canModifyComment($commentId, $userId)) {
                respondError(403, "Endast den som skrev kommentaren (eller inläggets ägare/admin) får ta bort den.");
            }

            try {
                $stmtDel = $conn->prepare("DELETE FROM comment WHERE ID = ?");
                $stmtDel->bind_param("i", $commentId);
                $stmtDel->execute();
                $affected = $stmtDel->affected_rows;
                $stmtDel->close();

                if ($affected > 0) {
                    respondSuccess(["message" => "Kommentaren har tagits bort!"]);
                }
                respondError(404, "Hittade ingen kommentar med det ID:t.");
            } catch (Exception $e) {
                respondError(500, $e->getMessage());
            }
        }

        if ($action === 'deleteBlog') {
            $blogId = (int)($inputData['blog_id'] ?? $_GET['blog_id'] ?? 0);

            if ($blogId <= 0) {
                respondError(400, "blog_id krävs för att ta bort bloggen!");
            }

            assertBlogOwner($conn, $blogId, $userId, "Endast ägaren kan ta bort denna blogg");

            try {
                $stmtDel = $conn->prepare("DELETE FROM blog WHERE ID = ?");
                $stmtDel->bind_param("i", $blogId);
                $stmtDel->execute();
                $stmtDel->close();

                respondSuccess(["message" => "Bloggen har tagits bort!"]);
            } catch (Exception $e) {
                respondError(500, $e->getMessage());
            }
        }

        $pageId = (int)($inputData['page_id'] ?? $_GET['page_id'] ?? 0);
        if ($pageId <= 0) {
            respondError(400, "page_id krävs!");
        }

        if (!$blogModule->canModify($pageId, $userId, true)) {
            respondError(403, "Ej behörig att radera inlägget.");
        }

        try {
            $stmtDelSub = $conn->prepare("DELETE FROM page_blog WHERE FORpage = ?");
            $stmtDelSub->bind_param("i", $pageId);
            $stmtDelSub->execute();
            $stmtDelSub->close();

            $blogModule->deletePage($pageId);
            respondSuccess(["message" => "Inlägg raderat permanent!"]);
        } catch (Exception $e) {
            respondError(500, $e->getMessage());
        }
        break;

    default:
        respondError(405, "Metoden stöds inte");
        break;
}