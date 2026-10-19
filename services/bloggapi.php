<?php
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

// --- GET: Hämta alla eller ett enskilt inlägg ---
    case 'GET':
        // Kolla om ett ID skickades med i URL:en (t.ex. ?id=2)
        if (isset($_GET['id'])) {
            $id = $_GET['id'];
            $stmt = $conn->prepare("SELECT * FROM blog_posts WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $post = $result->fetch_assoc();
            $stmt->close();

            if ($post) {
                echo json_encode([
                    "status" => "success",
                    "data" => $post
                ]);
            } else {
                http_response_code(404);
                echo json_encode(["status" => "error", "message" => "Inlägget hittades inte"]);
            }
        } else {
            // Om inget ID skickades med, hämta alla inlägg
            $result = $conn->query("SELECT * FROM blog_posts ORDER BY created_at DESC");
            $posts = [];
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $posts[] = $row;
                }
            }

            echo json_encode([
                "status" => "success",
                "data" => $posts
            ]);
        }
        break;

    // --- POST: Spara nytt inlägg ---
    case 'POST':
        if (empty($inputData['title']) || empty($inputData['content'])) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Titel och innehåll krävs!"]);
            break;
        }

        $title = $inputData['title'];
        $content = $inputData['content'];
        $author = $inputData['author'] ?? 'Anonym';
        $image_url = $inputData['image_url'] ?? null;
        $visability = $inputData['visability'] ?? 'private';

        $stmt = $conn->prepare("INSERT INTO blog_posts (title, content, author, image_url, visability) VALUES (?, ?, ?, ?)");
        
        if ($stmt) {
            $stmt->bind_param("ssss", $title, $content, $author, $image_url);
            $stmt->execute();
            $newId = $stmt->insert_id;
            $stmt->close();

            echo json_encode([
                "status" => "success",
                "message" => "Blogginlägg sparat!",
                "data" => [
                    "id" => $newId,
                    "title" => $title,
                    "content" => $content,
                    "author" => $author,
                    "image_url" => $image_url,
                    "visability" => $visability
                ]
            ]);
        } else {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => $conn->error]);
        }
        break;

// --- PUT: Uppdatera ett befintligt inlägg (inklusive bild) ---
    case 'PUT':
        if (empty($inputData['id']) || empty($inputData['title']) || empty($inputData['content'])) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "ID, titel och innehåll krävs för uppdatering!"]);
            break;
        }

        $id = $inputData['id'];
        $title = $inputData['title'];
        $content = $inputData['content'];
        $image_url = $inputData['image_url'] ?? null;
        $visability = $inputData['visability'] ?? null;

        // Om en ny image_url skickades med uppdaterar vi den också, annars behåller vi befintlig
        if ($image_url !== null) {
            $stmt = $conn->prepare("UPDATE blog_posts SET title = ?, content = ?, image_url = ? visability = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("sssi", $title, $content, $image_url, $id);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            $stmt = $conn->prepare("UPDATE blog_posts SET title = ?, content = ? visability = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("ssi", $title, $content, $id);
                $stmt->execute();
                $stmt->close();
            }
        }

        echo json_encode([
            "status" => "success",
            "message" => "Inlägg med ID " . $id . " har uppdaterats!"
        ]);
        break;

    // --- DELETE: Ta bort ett inlägg ---
    case 'DELETE':
        $id = $inputData['id'] ?? $_GET['id'] ?? null;

        if (empty($id)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Inläggs-ID krävs för radering!"]);
            break;
        }

        $stmt = $conn->prepare("DELETE FROM blog_posts WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();

            echo json_encode([
                "status" => "success",
                "message" => "Inlägget med ID " . $id . " har raderats permanent!"
            ]);
        } else {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => $conn->error]);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(["status" => "error", "message" => "Metoden stöds inte"]);
        break;
}
?>