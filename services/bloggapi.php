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
$inputData = json_decode(file_get_contents("php://input"), true);

$blogModule = new PageModule($conn, 'blog');

switch ($method) {

    // --- GET: Hämta inlägg (inklusive kommentarer om ett ID anges) ---
    case 'GET':
        if (isset($_GET['id'])) {
            $id = (int)$_GET['id'];
            
            // Hämta inlägget via vår vanliga logik eller PageModule
            $stmt = $conn->prepare("
                SELECT pb.ID AS blog_id, pb.last_update, pb.FORvisability, pb.FORpage, 
                    p.titel, p.content, p.created_at, p.FORuser, u.username AS author
                FROM page_blog pb 
                JOIN page p ON pb.FORpage = p.ID 
                LEFT JOIN user u ON u.ID = p.FORuser
                WHERE pb.ID = ? OR pb.FORpage = ?
            ");
            $stmt->bind_param("ii", $id, $id);
            $stmt->execute();
            $post = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($post) {
                $post['content'] = json_decode($post['content'], true);
                
                // Hämta tillhörande kommentarer via PageModule (använder page_id)[cite: 3]
                $pageId = (int)$post['FORpage'];
                $post['comments'] = $blogModule->getComments($pageId);

                echo json_encode(["status" => "success", "data" => $post]);
            } else {
                http_response_code(404);
                echo json_encode(["status" => "error", "message" => "Inlägget hittades inte"]);
            }
        } else {
            $result = $conn->query("
                SELECT pb.ID AS blog_id, pb.last_update, pb.FORvisability, pb.FORpage, 
                    p.titel, p.content, p.created_at, p.FORuser, u.username AS author
                FROM page_blog pb 
                JOIN page p ON pb.FORpage = p.ID
                LEFT JOIN user u ON u.ID = p.FORuser
            ");
            $posts = [];
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $row['content'] = json_decode($row['content'], true);
                    // Valfritt: Vill man ha med kommentarer i listvyn eller bara på enskilt inlägg? 
                    // Här kan man lägga till $row['comments'] = $blogModule->getComments((int)$row['FORpage']); om man vill.
                    $posts[] = $row;
                }
            }
            echo json_encode(["status" => "success", "data" => $posts]);
        }
        break;

// --- POST: Skapa inlägg ELLER Skapa kommentar ---
    case 'POST':
        $actingUserId = requireActingUser($conn);

        // Om anropet gäller att kommentera (har skickat med 'comment' och antingen 'titel', 'blog_id' eller 'page_id')
        if (isset($inputData['comment']) && (isset($inputData['titel']) || isset($inputData['blog_id']) || isset($inputData['page_id']))) {
            
            $res = null;

            // 1. Sök på titel om det skickats med
            if (!empty($inputData['titel'])) {
                $stmtFind = $conn->prepare("
                    SELECT pb.FORpage 
                    FROM page_blog pb 
                    JOIN page p ON pb.FORpage = p.ID 
                    WHERE p.titel = ?
                ");
                $stmtFind->bind_param("s", $inputData['titel']);
                $stmtFind->execute();
                $res = $stmtFind->get_result()->fetch_assoc();
                $stmtFind->close();
            } 
            // 2. Annars sök på ID (blog_id eller page_id)
            else {
                $blogOrPageId = $inputData['blog_id'] ?? $inputData['page_id'];
                $stmtFind = $conn->prepare("SELECT FORpage FROM page_blog WHERE ID = ? OR FORpage = ?");
                $stmtFind->bind_param("ii", $blogOrPageId, $blogOrPageId);
                $stmtFind->execute();
                $res = $stmtFind->get_result()->fetch_assoc();
                $stmtFind->close();
            }

            if (!$res) {
                http_response_code(404);
                echo json_encode(["status" => "error", "message" => "Inlägget hittades inte för kommentering"]);
                break;
            }

            $pageId = (int)$res['FORpage'];
            $commentText = $inputData['comment'];

            try {
                // Skapa kommentaren via PageModule
                $commentId = $blogModule->addComment($pageId, $actingUserId, $commentText);

                echo json_encode([
                    "status" => "success",
                    "message" => "Kommentar tillagd!",
                    "data" => [
                        "comment_id" => $commentId,
                        "page_id" => $pageId,
                        "user_id" => $actingUserId,
                        "content" => $commentText
                    ]
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => "Kunde inte spara kommentar: " . $e->getMessage()]);
            }
            break;
        }

    // --- PUT: Uppdatera inlägg ---
    case 'PUT':
        $actingUserId = requireActingUser($conn);

        if (empty($inputData['id']) || empty($inputData['titel']) || empty($inputData['content'])) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "ID, titel och innehåll krävs för uppdatering!"]);
            break;
        }

        $blogOrPageId = $inputData['id'];

        $stmtFind = $conn->prepare("SELECT FORpage FROM page_blog WHERE ID = ? OR FORpage = ?");
        $stmtFind->bind_param("ii", $blogOrPageId, $blogOrPageId);
        $stmtFind->execute();
        $res = $stmtFind->get_result()->fetch_assoc();
        $stmtFind->close();

        if (!$res) {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "Blogginlägget hittades inte!"]);
            break;
        }

        $pageId = (int)$res['FORpage'];

        if (!$blogModule->canModify($pageId, $actingUserId, true)) {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "Endast ägaren eller en administratör kan uppdatera detta inlägg"]);
            break;
        }

        try {
            $blogModule->updatePage($pageId, $inputData['titel'], $inputData['content'], $actingUserId);

            if (isset($inputData['FORvisability'])) {
                $visId = (int)$inputData['FORvisability'];
                $stmtVis = $conn->prepare("UPDATE page_blog SET FORvisability = ? WHERE FORpage = ?");
                $stmtVis->bind_param("ii", $visId, $pageId);
                $stmtVis->execute();
                $stmtVis->close();
            }

            echo json_encode(["status" => "success", "message" => "Inlägget har uppdaterats!"]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Kunde inte uppdatera: " . $e->getMessage()]);
        }
        break;

    // --- DELETE: Ta bort inlägg ---
    case 'DELETE':
        $actingUserId = requireActingUser($conn);
        $blogOrPageId = $inputData['id'] ?? $_GET['id'] ?? null;

        if (empty($blogOrPageId)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Inläggs-ID krävs för radering!"]);
            break;
        }

        $stmtFind = $conn->prepare("SELECT FORpage FROM page_blog WHERE ID = ? OR FORpage = ?");
        $stmtFind->bind_param("ii", $blogOrPageId, $blogOrPageId);
        $stmtFind->execute();
        $res = $stmtFind->get_result()->fetch_assoc();
        $stmtFind->close();

        if (!$res) {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "Inlägget hittades inte!"]);
            break;
        }

        $pageId = (int)$res['FORpage'];

        if (!$blogModule->canModify($pageId, $actingUserId, true)) {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "Endast ägaren eller en administratör kan radera detta inlägg"]);
            break;
        }

        try {
            $stmtBlogDel = $conn->prepare("DELETE FROM page_blog WHERE FORpage = ?");
            $stmtBlogDel->bind_param("i", $pageId);
            $stmtBlogDel->execute();
            $stmtBlogDel->close();

            $blogModule->deletePage($pageId);

            echo json_encode(["status" => "success", "message" => "Inlägget har raderats permanent!"]);
        } catch (Exception $e) {
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