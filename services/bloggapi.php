<?php
// 1. Inställningar för JSON och CORS
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$inputData = json_decode(file_get_contents("php://input"), true);

switch ($method) {

    // --- GET: Hämta alla bloggar från page_blog ---
    case 'GET':
        try {
            $stmt = $pdo->prepare("SELECT * FROM page_blog");
            $stmt->execute();
            $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                "status" => "success",
                "data" => $posts
            ]);
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
        break;

    // --- POST: Spara nytt inlägg i page_blog ---
    case 'POST':
        if (empty($inputData['title']) || empty($inputData['content'])) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Titel och innehåll krävs!"]);
            break;
        }

        try {
            $title = $inputData['title'];
            $content = $inputData['content'];

            $stmt = $pdo->prepare("INSERT INTO page_blog (title, content) VALUES (?, ?)");
            $stmt->execute([$title, $content]);
            
            $newId = $pdo->lastInsertId();

            echo json_encode([
                "status" => "success",
                "message" => "Blogginlägg sparat i page_blog!",
                "data" => [
                    "id" => $newId,
                    "title" => $title,
                    "content" => $content
                ]
            ]);
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
        break;

    // --- PUT: Redigera ett inlägg ---
    case 'PUT':
        if (empty($inputData['id']) || empty($inputData['title']) || empty($inputData['content'])) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "ID, titel och innehåll krävs för att uppdatera!"]);
            break;
        }

        try {
            $stmt = $pdo->prepare("UPDATE page_blog SET title = ?, content = ? WHERE id = ?");
            $stmt->execute([$inputData['title'], $inputData['content'], $inputData['id']]);

            echo json_encode([
                "status" => "success",
                "message" => "Inlägg med ID " . $inputData['id'] . " har uppdaterats."
            ]);
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
        break;

    // --- DELETE: Ta bort ett inlägg ---
    case 'DELETE':
        $id = $inputData['id'] ?? $_GET['id'] ?? null;

        if (empty($id)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Inläggs-ID krävs för radering!"]);
            break;
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM page_blog WHERE id = ?");
            $stmt->execute([$id]);

            echo json_encode([
                "status" => "success",
                "message" => "Inlägget med ID " . $id . " har tagits bort."
            ]);
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(["status" => "error", "message" => "Metoden stöds inte"]);
        break;
}
?>