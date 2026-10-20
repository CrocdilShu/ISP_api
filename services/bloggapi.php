<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$inputData = json_decode(file_get_contents("php://input"), true);

switch ($method) {

// --- GET: Hämta inlägg ---
    case 'GET':
        if (isset($_GET['id'])) {
            $id = $_GET['id'];
            $stmt = $conn->prepare("
                SELECT pb.ID AS blog_id, pb.last_update, pb.FORvisability, pb.FORpage, p.titel, p.content, p.created_at, p.FORuser 
                FROM page_blog pb 
                JOIN page p ON pb.FORpage = p.ID 
                WHERE pb.ID = ? OR pb.FORpage = ?
            ");
            $stmt->bind_param("ii", $id, $id);
            $stmt->execute();
            $post = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($post) {
                echo json_encode(["status" => "success", "data" => $post]);
            } else {
                http_response_code(404);
                echo json_encode(["status" => "error", "message" => "Inlägget hittades inte"]);
            }
        } else {
            $result = $conn->query("
                SELECT pb.ID AS blog_id, pb.last_update, pb.FORvisability, pb.FORpage, p.titel, p.content, p.created_at, p.FORuser 
                FROM page_blog pb 
                JOIN page p ON pb.FORpage = p.ID
            ");
            $posts = [];
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $posts[] = $row;
                }
            }
            echo json_encode(["status" => "success", "data" => $posts]);
        }
        break;

    // --- POST: Skapa nytt inlägg i både page och page_blog ---
    case 'POST':
        if (empty($inputData['titel']) || empty($inputData['content'])) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Titel och innehåll krävs!"]);
            break;
        }

        $titel = $inputData['titel'];
        $content = $inputData['content'];
        $FORuser = $inputData['FORuser'] ?? 1; 
        $FORvisability = $inputData['FORvisability'] ?? 1;

        // Använd transaktion så att båda tabellerna sparas säkert ihop
        $conn->begin_transaction();

        try {
            // 1. Skapa posten i 'page'
            $stmtPage = $conn->prepare("INSERT INTO page (titel, content, FORuser) VALUES (?, ?, ?)");
            $stmtPage->bind_param("ssi", $titel, $content, $FORuser);
            $stmtPage->execute();
            $pageId = $stmtPage->insert_id;
            $stmtPage->close();

            // 2. Skapa kopplingen i 'page_blog'
            $stmtBlog = $conn->prepare("INSERT INTO page_blog (FORpage, FORvisability) VALUES (?, ?)");
            $stmtBlog->bind_param("ii", $pageId, $FORvisability);
            $stmtBlog->execute();
            $blogId = $stmtBlog->insert_id;
            $stmtBlog->close();

            $conn->commit();

            echo json_encode([
                "status" => "success",
                "message" => "Inlägg skapat!",
                "data" => [
                    "blog_id" => $blogId,
                    "page_id" => $pageId,
                    "titel" => $titel,
                    "content" => $content,
                    "FORvisability" => $FORvisability,
                    "FORuser" => $FORuser
                ]
            ]);
        } catch (Exception $e) {
            $conn->rollback();
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Kunde inte spara inlägg: " . $e->getMessage()]);
        }
        break;

    // --- PUT: Uppdatera inlägg ---
    case 'PUT':
        if (empty($inputData['id']) || empty($inputData['titel']) || empty($inputData['content'])) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "ID, titel och innehåll krävs för uppdatering!"]);
            break;
        }

        $blogId = $inputData['id'];
        $titel = $inputData['titel'];
        $content = $inputData['content'];
        $FORvisability = $inputData['FORvisability'] ?? 1;

        // Hämta vilket page-ID som hör till blogg-ID:t
        $stmtFind = $conn->prepare("SELECT FORpage FROM page_blog WHERE ID = ?");
        $stmtFind->bind_param("i", $blogId);
        $stmtFind->execute();
        $res = $stmtFind->get_result()->fetch_assoc();
        $stmtFind->close();

        if (!$res) {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "Blogginlägget hittades inte!"]);
            break;
        }

        $pageId = $res['FORpage'];

        $conn->begin_transaction();
        try {
            // Uppdatera 'page'
            $stmtPage = $conn->prepare("UPDATE page SET titel = ?, content = ? WHERE ID = ?");
            $stmtPage->bind_param("ssi", $titel, $content, $pageId);
            $stmtPage->execute();
            $stmtPage->close();

            // Uppdatera 'page_blog'
            $stmtBlog = $conn->prepare("UPDATE page_blog SET FORvisability = ? WHERE ID = ?");
            $stmtBlog->bind_param("ii", $FORvisability, $blogId);
            $stmtBlog->execute();
            $stmtBlog->close();

            $conn->commit();

            echo json_encode([
                "status" => "success",
                "message" => "Inlägget har uppdaterats!"
            ]);
        } catch (Exception $e) {
            $conn->rollback();
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Kunde inte uppdatera: " . $e->getMessage()]);
        }
        break;

    // --- DELETE: Ta bort inlägg ---
    case 'DELETE':
        $blogId = $inputData['id'] ?? $_GET['id'] ?? null;
        if (empty($blogId)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Inläggs-ID krävs för radering!"]);
            break;
        }

        // Hämta kopplat page-ID
        $stmtFind = $conn->prepare("SELECT FORpage FROM page_blog WHERE ID = ?");
        $stmtFind->bind_param("i", $blogId);
        $stmtFind->execute();
        $res = $stmtFind->get_result()->fetch_assoc();
        $stmtFind->close();

        if (!$res) {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "Inlägget hittades inte!"]);
            break;
        }

        $pageId = $res['FORpage'];

        $conn->begin_transaction();
        try {
            // Radera från page_blog först
            $stmtBlog = $conn->prepare("DELETE FROM page_blog WHERE ID = ?");
            $stmtBlog->bind_param("i", $blogId);
            $stmtBlog->execute();
            $stmtBlog->close();

            // Radera från page
            $stmtPage = $conn->prepare("DELETE FROM page WHERE ID = ?");
            $stmtPage->bind_param("i", $pageId);
            $stmtPage->execute();
            $stmtPage->close();

            $conn->commit();

            echo json_encode([
                "status" => "success",
                "message" => "Inlägget har raderats permanent!"
            ]);
        } catch (Exception $e) {
            $conn->rollback();
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Kunde inte radera: " . $e->getMessage()]);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(["status" => "error", "message" => "Metoden stöds inte"]);
        break;
}
?>