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

$method = $_SERVER['REQUEST_METHOD'];
$inputData = json_decode(file_get_contents("php://input"), true) ?? [];
$action = $_GET['action'] ?? '';

$blogModule = new PageModule($conn, 'blog');
$userId = $blogModule->resolveRequestUser();

switch ($method) {

    // =================================================================
    // GET: Hämta bloggar, inlägg via titel, ID eller blog_id
    // =================================================================
    case 'GET':
        // 1. Lista alla blogg-rymder
        if ($action === 'listBlogs') {
            $blogs = $conn->query("
                SELECT b.ID AS blog_id, b.titel, b.description, b.created_at, u.username AS owner 
                FROM blog b 
                JOIN `user` u ON b.FORuser = u.ID
            ")->fetch_all(MYSQLI_ASSOC);
            echo json_encode(["status" => "success", "data" => $blogs]);
            exit;
        }

        // 1.5. Hämta specifik blogg via titel: ?action=getBlog&title=...
        if ($action === 'getBlog' && isset($_GET['title'])) {
            $searchTitle = "%" . trim($_GET['title']) . "%";
            $stmt = $conn->prepare("
                SELECT b.ID AS blog_id, b.titel, b.description, b.created_at, u.username AS owner 
                FROM blog b 
                JOIN `user` u ON b.FORuser = u.ID 
                WHERE b.titel LIKE ? 
                LIMIT 1
            ");
            $stmt->bind_param("s", $searchTitle);
            $stmt->execute();
            $blog = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($blog) {
                echo json_encode(["status" => "success", "data" => $blog]);
            } else {
                http_response_code(404);
                echo json_encode(["status" => "error", "message" => "Bloggen hittades inte"]);
            }
            exit;
        }

        // Hämta valfri limit från URL (standard = ingen begränsning)
        $commentLimit = isset($_GET['limit']) ? (int)$_GET['limit'] : null;

        // 2. Hämta inlägg baserat på blog_id: ?blog_id=X
        if (isset($_GET['blog_id'])) {
            $blogId = (int)$_GET['blog_id'];
            $stmt = $conn->prepare("
                SELECT p.ID AS page_id, p.titel, p.content, p.created_at, p.FORuser AS owner_id,
                       u.username AS owner_username, pb.ID AS blog_id_rel, pb.FORblog AS blog_ref_id, v.state AS visibility
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
                $post['content'] = json_decode($post['content'], true);
                $post['pictures'] = $blogModule->getPagePictures((int)$post['page_id']);
                $post['comments'] = $blogModule->getComments((int)$post['page_id'], 'ASC', $commentLimit);
            }
            unset($post);

            echo json_encode(["status" => "success", "data" => $posts]);
            exit;
        }

        // 3. Hämta inlägg via URL-parameter: ?title=Rubrik&limit=5
        if (isset($_GET['title'])) {
            $searchTitle = "%" . trim($_GET['title']) . "%";
            $stmt = $conn->prepare("
                SELECT p.ID AS page_id, p.titel, p.content, p.created_at, p.FORuser AS owner_id,
                       u.username AS owner_username, pb.ID AS blog_id, pb.FORblog AS blog_ref_id, v.state AS visibility
                FROM page p
                JOIN `user` u ON p.FORuser = u.ID
                JOIN page_blog pb ON pb.FORpage = p.ID
                JOIN visability v ON pb.FORvisability = v.ID
                WHERE p.titel LIKE ?
                LIMIT 1
            ");
            $stmt->bind_param("s", $searchTitle);
            $stmt->execute();
            $post = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($post) {
                $post['content'] = json_decode($post['content'], true);
                $post['pictures'] = $blogModule->getPagePictures((int)$post['page_id']);
                $post['comments'] = $blogModule->getComments((int)$post['page_id'], 'ASC', $commentLimit);
                echo json_encode(["status" => "success", "data" => $post]);
            } else {
                http_response_code(404);
                echo json_encode(["status" => "error", "message" => "Inlägget med den titeln hittades inte"]);
            }
            exit;
        }

        // 4. Hämta inlägg via URL-parameter: ?id=X&limit=5
        if (isset($_GET['id'])) {
            $id = (int)$_GET['id'];
            $post = $blogModule->getPage($id);
            if ($post) {
                $post['comments'] = $blogModule->getComments($id, 'ASC', $commentLimit);
                echo json_encode(["status" => "success", "data" => $post]);
            } else {
                http_response_code(404);
                echo json_encode(["status" => "error", "message" => "Inlägget hittades inte"]);
            }
            exit;
        }

        // 5. Standard: Lista alla inlägg
        $posts = $blogModule->listPages($userId);
        echo json_encode(["status" => "success", "data" => $posts]);
        break;

    // =================================================================
    // POST: Skapa Blogg, Skapa Inlägg eller Skapa Kommentar
    // =================================================================
    case 'POST':
        if (!$userId) {
            http_response_code(401);
            echo json_encode(["status" => "error", "message" => "Obehörig. X-User-Token saknas eller är ogiltig."]);
            exit;
        }

        if ($action === 'createBlog') {
            $title = trim($inputData['titel'] ?? '');
            $description = trim($inputData['description'] ?? null);
            $visibility = $inputData['visibility'] ?? 'public';

            if ($title === '') {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Bloggens titel krävs!"]);
                exit;
            }

            try {
                $normalized = $blogModule->normalizeVisibility($visibility);
                $visId = ($normalized === 'private') ? 2 : 1;

                $stmt = $conn->prepare("INSERT INTO blog (titel, description, FORuser, FORvisability) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssii", $title, $description, $userId, $visId);
                $stmt->execute();
                $blogId = (int)$conn->insert_id;
                $stmt->close();

                echo json_encode(["status" => "success", "message" => "Blogg skapad!", "blog_id" => $blogId]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => $e->getMessage()]);
            }
            exit;
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

            $title = trim($_POST['titel'] ?? '');
            $content = $_POST['content'] ?? '';
            $visInput = $_POST['FORvisability'] ?? $_POST['visibility'] ?? 'public';
            if (is_numeric($visInput)) {
                $visibility = ((int)$visInput === 2) ? 'private' : 'public';
            } else {
                $visibility = $visInput;
            }

            // 1. Hantera uppladdad bild via $_FILES
            $savedImagePaths = [];
            $uploadedFile = null;
            if (!empty($_FILES['picture']) && $_FILES['picture']['error'] === UPLOAD_ERR_OK) {
                try {
                    $savedImagePaths[] = saveUploadedImage($_FILES['picture']);
                    $uploadedFile = $_FILES['picture'];
                } catch (Exception $e) {
                    http_response_code(400);
                    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
                    exit;
                }
            }

            if ($blogId <= 0 || $title === '' || empty($content)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Kunde inte hitta bloggen! Ange 'blog_title', samt titel och content."]);
                exit;
            }

            try {
                // 2. Skapa inlägget först
                $pageId = $blogModule->createPage($title, $content, $userId, $visibility, null, $savedImagePaths);

                // 3. Koppla till bloggen
                $stmtSub = $conn->prepare("UPDATE page_blog SET FORblog = ? WHERE FORpage = ?");
                $stmtSub->bind_param("ii", $blogId, $pageId);
                $stmtSub->execute();
                $stmtSub->close();
                
                // 4. Spara filnamnet i databasen med tvingad felhantering
                if (!empty($savedImagePaths) && $uploadedFile) {
                    $imgPath = $savedImagePaths[0];
                    $origName = $uploadedFile['name'];
                    $mimeType = $uploadedFile['type'];

                    $stmtPic = $conn->prepare("INSERT INTO picture (filename, mime_type, image_data, FORpage) VALUES (?, ?, ?, ?)");
                    if (!$stmtPic) {
                        http_response_code(500);
                        echo json_encode(["status" => "error", "message" => "SQL Prepare Error: " . $conn->error]);
                        exit;
                    }

                    $stmtPic->bind_param("sssi", $origName, $mimeType, $imgPath, $pageId);
                    
                    if (!$stmtPic->execute()) {
                        http_response_code(500);
                        echo json_encode(["status" => "error", "message" => "SQL Execute Error: " . $stmtPic->error]);
                        exit;
                    }
                    $stmtPic->close();
                }

                echo json_encode(["status" => "success", "message" => "Inlägg skapat med bild via Multipart!", "page_id" => $pageId, "blog_id" => $blogId]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => $e->getMessage()]);
            }
            exit;
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

            $commentText = trim($inputData['comment'] ?? '');

            if ($pageId <= 0 || $commentText === '') {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Kunde inte hitta inlägget! Ange 'titel' (eller 'page_id'), samt 'comment'."]);
                exit;
            }

            try {
                $commentId = $blogModule->addComment($pageId, $userId, $commentText);
                echo json_encode(["status" => "success", "message" => "Kommentar tillagd!", "comment_id" => $commentId, "page_id" => $pageId]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => $e->getMessage()]);
            }
            exit;
        }

        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Okänd action för POST."]);
        break;

    // =================================================================
    // PUT: Redigera inlägg, kommentar eller blogg
    // =================================================================
    case 'PUT':
        if (!$userId) {
            http_response_code(401);
            echo json_encode(["status" => "error", "message" => "Obehörig. X-User-Token saknas eller är ogiltig."]);
            exit;
        }

        if ($action === 'updateBlog') {
            $blogId = (int)($inputData['blog_id'] ?? 0);
            $title = trim($inputData['titel'] ?? '');
            $description = trim($inputData['description'] ?? null);
            $visibility = $inputData['visibility'] ?? 'public';

            if ($blogId <= 0 || $title === '') {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "blog_id och titel krävs för att uppdatera bloggen!"]);
                exit;
            }

            $stmtCheck = $conn->prepare("SELECT FORuser FROM blog WHERE ID = ? LIMIT 1");
            $stmtCheck->bind_param("i", $blogId);
            $stmtCheck->execute();
            $blogOwner = $stmtCheck->get_result()->fetch_assoc();
            $stmtCheck->close();

            if (!$blogOwner) {
                http_response_code(404);
                echo json_encode(["status" => "error", "message" => "Bloggen hittades inte"]);
                exit;
            }

            if ((int)$blogOwner['FORuser'] !== $userId) {
                http_response_code(403);
                echo json_encode(["status" => "error", "message" => "Endast ägaren kan uppdatera denna blogg"]);
                exit;
            }

            try {
                $normalized = $blogModule->normalizeVisibility($visibility);
                $visId = ($normalized === 'private') ? 2 : 1;

                $stmtUpd = $conn->prepare("UPDATE blog SET titel = ?, description = ?, FORvisability = ? WHERE ID = ?");
                $stmtUpd->bind_param("ssii", $title, $description, $visId, $blogId);
                $stmtUpd->execute();
                $stmtUpd->close();

                echo json_encode(["status" => "success", "message" => "Bloggen har uppdaterats!"]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => $e->getMessage()]);
            }
            exit;
        }

        if ($action === 'updateComment') {
            $commentId = (int)($inputData['comment_id'] ?? 0);
            $newContent = $inputData['comment'] ?? '';

            if ($commentId <= 0 || $newContent === '') {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "comment_id och ny kommentarstext krävs!"]);
                exit;
            }

            if (!$blogModule->canModifyComment($commentId, $userId)) {
                http_response_code(403);
                echo json_encode(["status" => "error", "message" => "Endast den som skrev kommentaren (eller inläggets ägare/admin) får ändra den."]);
                exit;
            }

            try {
                $blogModule->updateComment($commentId, $newContent);
                echo json_encode(["status" => "success", "message" => "Kommentaren har uppdaterats!"]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => $e->getMessage()]);
            }
            exit;
        }

        $pageId = (int)($inputData['page_id'] ?? 0);
        if ($pageId <= 0) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "page_id krävs för att uppdatera inlägg!"]);
            exit;
        }

        if (!$blogModule->canModify($pageId, $userId, true)) {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "Endast ägaren eller en administratör kan uppdatera detta inlägg"]);
            exit;
        }

        try {
            $title = $inputData['titel'] ?? null;
            $content = $inputData['content'] ?? null;
            $removedPic = $inputData['removed_picture'] ?? null;
            $addedPics = $inputData['pictures'] ?? [];

            $blogModule->updatePage($pageId, $title, $content, $userId, $removedPic, $addedPics);
            echo json_encode(["status" => "success", "message" => "Inlägget har uppdaterats!"]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
        break;

    // =================================================================
    // DELETE: Ta bort inlägg eller kommentar
    // =================================================================
    case 'DELETE':
        if (!$userId) {
            http_response_code(401);
            echo json_encode(["status" => "error", "message" => "Obehörig."]);
            exit;
        }

        if ($action === 'deleteComment') {
            $commentId = (int)($inputData['comment_id'] ?? 0);

            if ($commentId <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "comment_id krävs för att ta bort kommentaren!"]);
                exit;
            }

            if (!$blogModule->canModifyComment($commentId, $userId)) {
                http_response_code(403);
                echo json_encode(["status" => "error", "message" => "Endast den som skrev kommentaren (eller inläggets ägare/admin) får ta bort den."]);
                exit;
            }

            try {
                $stmtDel = $conn->prepare("DELETE FROM comment WHERE ID = ?");
                $stmtDel->bind_param("i", $commentId);
                $stmtDel->execute();

                if ($stmtDel->affected_rows > 0) {
                    echo json_encode(["status" => "success", "message" => "Kommentaren har tagits bort!"]);
                } else {
                    http_response_code(404);
                    echo json_encode(["status" => "error", "message" => "Hittade ingen kommentar med det ID:t."]);
                }
                $stmtDel->close();
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => $e->getMessage()]);
            }
            exit;
        }

        if ($action === 'deleteBlog') {
            $blogId = (int)($inputData['blog_id'] ?? $_GET['blog_id'] ?? 0);

            if ($blogId <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "blog_id krävs för att ta bort bloggen!"]);
                exit;
            }

            $stmtCheck = $conn->prepare("SELECT FORuser FROM blog WHERE ID = ? LIMIT 1");
            $stmtCheck->bind_param("i", $blogId);
            $stmtCheck->execute();
            $blogOwner = $stmtCheck->get_result()->fetch_assoc();
            $stmtCheck->close();

            if (!$blogOwner) {
                http_response_code(404);
                echo json_encode(["status" => "error", "message" => "Bloggen hittades inte"]);
                exit;
            }

            if ((int)$blogOwner['FORuser'] !== $userId) {
                http_response_code(403);
                echo json_encode(["status" => "error", "message" => "Endast ägaren kan ta bort denna blogg"]);
                exit;
            }

            try {
                $stmtDel = $conn->prepare("DELETE FROM blog WHERE ID = ?");
                $stmtDel->bind_param("i", $blogId);
                $stmtDel->execute();
                $stmtDel->close();

                echo json_encode(["status" => "success", "message" => "Bloggen har tagits bort!"]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => $e->getMessage()]);
            }
            exit;
        }
        
        $pageId = (int)($inputData['page_id'] ?? $_GET['page_id'] ?? 0);
        if ($pageId <= 0) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "page_id krävs!"]);
            exit;
        }

        if (!$blogModule->canModify($pageId, $userId, true)) {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "Ej behörig att radera inlägget."]);
            exit;
        }

        $stmtDelSub = $conn->prepare("DELETE FROM page_blog WHERE FORpage = ?");
        $stmtDelSub->bind_param("i", $pageId);
        $stmtDelSub->execute();
        $stmtDelSub->close();

        $blogModule->deletePage($pageId);
        echo json_encode(["status" => "success", "message" => "Inlägg raderat permanent!"]);
        break;

    default:
        http_response_code(405);
        echo json_encode(["status" => "error", "message" => "Metoden stöds inte"]);
        break;
}
?>