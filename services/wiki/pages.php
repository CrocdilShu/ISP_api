<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/providerauth.php';
require_once __DIR__ . '/../common/PageModule.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$sessionKey = getCompanySessionKey($conn);
$providerAccountId = null;

$hasWikiAccess = hasServiceAccess($sessionKey, SERVICE_WIKI, $providerAccountId);
if (!$hasWikiAccess) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No rights to access the wiki service (invalid company session key)']);
    exit;
}

$wiki = new PageModule($conn, 'wiki');
$denialReason = null;
$actingUser = $wiki->resolveRequestUser($denialReason);
$viewerId = $actingUser ? (int)$actingUser['id'] : null;

// Helper to parse tags from array or comma-separated string
function parseTagsInput(mixed $raw): array {
    if (is_array($raw)) return $raw;
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) return $decoded;
        return array_map('trim', explode(',', $raw));
    }
    return [];
}

// 1. GET: List wikis (?type=wiki), get specific wiki (?type=wiki&id=X), get specific page (?id=X), or list pages (?wiki_id=Y&tag=Z)
if ($method === 'GET') {
    $type = strtolower(trim((string)($_GET['type'] ?? 'page')));
    $search = trim((string)($_GET['search'] ?? ''));
    $sort = strtoupper(trim((string)($_GET['sort'] ?? 'DESC')));
    $tagFilter = isset($_GET['tag']) ? trim((string)$_GET['tag']) : null;

    if ($type === 'wiki') {
        $wikiId = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_GET['wiki_id']) ? (int)$_GET['wiki_id'] : 0);
        if ($wikiId > 0) {
            $space = $wiki->getSpace($wikiId);
            if (!$space) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Wiki not found']);
                exit;
            }
            if (!$wiki->canViewSpace($space, $viewerId)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'No rights to view this private wiki']);
                exit;
            }
            unset($space['_owner_id']);
            echo json_encode([
                'success' => true,
                'message' => 'Wiki fetched',
                'wiki' => $space
            ]);
            exit;
        }

        $wikis = $wiki->listSpaces($viewerId, $search, $sort, $tagFilter);
        echo json_encode([
            'success' => true,
            'message' => 'Wikis listed',
            'count' => count($wikis),
            'wikis' => $wikis
        ]);
        exit;
    }

    $pageId = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_GET['page_id']) ? (int)$_GET['page_id'] : 0);
    $parentWikiId = isset($_GET['wiki_id']) ? (int)$_GET['wiki_id'] : null;

    if ($pageId > 0) {
        $page = $wiki->getPage($pageId);
        if (!$page) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Page not found']);
            exit;
        }
        if (!$wiki->canViewPage($page, $viewerId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'No rights to view pages in this private wiki']);
            exit;
        }
        unset($page['_space_owner_id']);
        echo json_encode([
            'success' => true,
            'message' => 'Page fetched',
            'page' => $page
        ]);
        exit;
    }

    $pages = $wiki->listPages($viewerId, $parentWikiId, $search, $sort, $tagFilter);
    echo json_encode([
        'success' => true,
        'message' => 'Pages listed',
        'count' => count($pages),
        'pages' => $pages
    ]);
    exit;
}

// Write operations require a valid non-banned user
if (!$actingUser) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => $denialReason ?? 'Unauthorized: valid X-User-Token required']);
    exit;
}
$userId = (int)$actingUser['id'];

// 2. POST: Create Wiki (?type=wiki), Update Wiki (?type=wiki&id=X), Create Page, or Update Page with Multipart files (?id=X)
if ($method === 'POST') {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = [];
    $uploadedPictures = [];
    $targetId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if (stripos($contentType, 'multipart/form-data') !== false) {
        $input = $_POST;

        if (!empty($_FILES['pictures'])) {
            $files = $_FILES['pictures'];
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $fileCount = is_array($files['name']) ? count($files['name']) : 1;

            for ($i = 0; $i < $fileCount; $i++) {
                $error = is_array($files['error']) ? $files['error'][$i] : $files['error'];
                if ($error === UPLOAD_ERR_OK) {
                    $tmpName = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
                    $originalName = basename(is_array($files['name']) ? $files['name'][$i] : $files['name']);
                    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

                    if (in_array($ext, $allowedExtensions, true)) {
                        $uploadedPictures[] = [
                            'filename' => $originalName,
                            'mime_type' => is_array($files['type']) ? $files['type'][$i] : 'image/png',
                            'tmp_name' => $tmpName
                        ];
                    }
                }
            }
        }
    } else {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = [];
        }
    }

    $type = strtolower(trim((string)($_GET['type'] ?? ($input['type'] ?? 'page'))));

    // --- WIKI CREATE OR UPDATE ---
    if ($type === 'wiki') {
        $wikiId = $targetId > 0 ? $targetId : (int)($input['wiki_id'] ?? 0);
        if ($wikiId > 0) {
            if (!$wiki->getSpace($wikiId)) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Wiki not found']);
                exit;
            }
            if (!$wiki->canEditSpace($wikiId, $userId, true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'No rights to edit this wiki (requires wiki owner, co_owner, or admin)']);
                exit;
            }
            try {
                $updatedWiki = $wiki->updateSpace(
                    $wikiId,
                    $input['titel'] ?? null,
                    $input['description'] ?? null,
                    $input['visibility'] ?? null,
                    parseTagsInput($input['add_tags'] ?? ($input['tags'] ?? [])),
                    parseTagsInput($input['remove_tags'] ?? [])
                );
                echo json_encode([
                    'success' => true,
                    'message' => 'Wiki updated',
                    'wiki' => $updatedWiki
                ]);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;
        }

        $titel = trim((string)($input['titel'] ?? ''));
        $description = isset($input['description']) ? trim((string)$input['description']) : null;
        $visibility = $wiki->normalizeVisibility($input['visibility'] ?? 'public');
        $tags = parseTagsInput($input['tags'] ?? []);

        if ($titel === '' || $visibility === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid input: titel and valid visibility (public|private) are required']);
            exit;
        }

        try {
            $createdWiki = $wiki->createSpace($titel, $description, $userId, $visibility, $tags);
            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Wiki created',
                'wiki' => $createdWiki
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Could not create wiki']);
        }
        exit;
    }

    // --- PAGE UPDATE (WHEN ?id=X IS PROVIDED ON POST) ---
    if ($targetId > 0) {
        $existingPage = $wiki->getPage($targetId);
        if (!$existingPage) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Page not found']);
            exit;
        }

        if (!$wiki->canModifyPage($targetId, $userId, false)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'No rights to edit pages in this wiki (requires wiki owner, co_owner, editor, or admin)']);
            exit;
        }

        $jsonPictures = is_array($input['pictures'] ?? null) ? $input['pictures'] : [];
        $addedPictures = array_merge($jsonPictures, $uploadedPictures);
        $removedPic = $input['removed_pic'] ?? null;
        $title = $input['titel'] ?? null;
        $content = $input['content'] ?? null;

        try {
            $updatedPage = $wiki->updatePage(
                $targetId,
                $title,
                $content,
                $userId,
                $removedPic,
                $addedPictures
            );

            echo json_encode([
                'success' => true,
                'message' => 'Page updated',
                'page' => $updatedPage
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Could not update page']);
        }
        exit;
    }

    // --- PAGE CREATE ---
    $parentWikiId = isset($input['wiki_id']) ? (int)$input['wiki_id'] : (isset($_GET['wiki_id']) ? (int)$_GET['wiki_id'] : 0);
    if ($parentWikiId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid input: wiki_id is required so the page can inherit permissions and visibility from its parent wiki']);
        exit;
    }

    if (!$wiki->getSpace($parentWikiId)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Parent wiki not found']);
        exit;
    }

    if (!$wiki->canEditSpace($parentWikiId, $userId, false)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No rights to create pages in this wiki (requires wiki owner, co_owner, editor, or admin)']);
        exit;
    }

    if (!isset($input['titel']) || !is_string($input['titel']) || trim($input['titel']) === '' || !isset($input['content'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid input: titel and plain text content are required']);
        exit;
    }

    $jsonPictures = is_array($input['pictures'] ?? null) ? $input['pictures'] : [];
    $pictures = array_merge($jsonPictures, $uploadedPictures);

    try {
        $createdPage = $wiki->createPage(
            $parentWikiId,
            trim($input['titel']),
            $input['content'],
            $userId,
            $pictures
        );
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Page created',
            'page' => $createdPage
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Could not create page']);
    }
    exit;
}

// 3. PUT: Update Wiki (?type=wiki&id=X) or Update Page (?id=X)
if ($method === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }

    $type = strtolower(trim((string)($_GET['type'] ?? ($input['type'] ?? 'page'))));
    $targetId = isset($_GET['id']) ? (int)$_GET['id'] : (int)($input['id'] ?? ($input['page_id'] ?? ($input['wiki_id'] ?? 0)));

    if ($targetId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid input: id is required']);
        exit;
    }

    if ($type === 'wiki') {
        if (!$wiki->getSpace($targetId)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Wiki not found']);
            exit;
        }
        if (!$wiki->canEditSpace($targetId, $userId, true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'No rights to edit this wiki (requires wiki owner, co_owner, or admin)']);
            exit;
        }

        try {
            $updatedWiki = $wiki->updateSpace(
                $targetId,
                $input['titel'] ?? null,
                $input['description'] ?? null,
                $input['visibility'] ?? null,
                parseTagsInput($input['add_tags'] ?? ($input['tags'] ?? [])),
                parseTagsInput($input['remove_tags'] ?? [])
            );
            echo json_encode([
                'success' => true,
                'message' => 'Wiki updated',
                'wiki' => $updatedWiki
            ]);
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if (!$wiki->getPage($targetId)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Page not found']);
        exit;
    }

    if (!$wiki->canModifyPage($targetId, $userId, false)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No rights to edit pages in this wiki (requires wiki owner, co_owner, editor, or admin)']);
        exit;
    }

    $addedPictures = is_array($input['pictures'] ?? null) ? $input['pictures'] : [];

    try {
        $updatedPage = $wiki->updatePage(
            $targetId,
            $input['titel'] ?? null,
            $input['content'] ?? null,
            $userId,
            $input['removed_pic'] ?? null,
            $addedPictures
        );

        echo json_encode([
            'success' => true,
            'message' => 'Page updated',
            'page' => $updatedPage
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Could not update page']);
    }
    exit;
}

// 4. DELETE: Delete Wiki (?type=wiki&id=X) or Delete Page (?id=X)
if ($method === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    $type = strtolower(trim((string)($_GET['type'] ?? ($input['type'] ?? 'page'))));
    $targetId = isset($_GET['id']) ? (int)$_GET['id'] : (int)($input['id'] ?? ($input['page_id'] ?? ($input['wiki_id'] ?? 0)));

    if ($targetId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid input: id is required']);
        exit;
    }

    if ($type === 'wiki') {
        if (!$wiki->getSpace($targetId)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Wiki not found']);
            exit;
        }
        if (!$wiki->canDeleteSpace($targetId, $userId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'No rights to delete this wiki (requires wiki owner or admin)']);
            exit;
        }
        $wiki->deleteSpace($targetId);
        echo json_encode([
            'success' => true,
            'message' => 'Wiki deleted',
            'wiki_id' => $targetId
        ]);
        exit;
    }

    if (!$wiki->getPage($targetId)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Page not found']);
        exit;
    }

    if (!$wiki->canModifyPage($targetId, $userId, true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No rights to delete pages in this wiki (requires wiki owner, co_owner, or admin)']);
        exit;
    }

    $wiki->deletePage($targetId);
    echo json_encode([
        'success' => true,
        'message' => 'Page deleted (associated pictures soft-deleted for retention period)',
        'page_id' => $targetId
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);