<?php
// Part of calender.php. Not a page of its own: it only defines functions.
if (!defined('CALENDER_API')) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/providerauth.php';
require_once __DIR__ . '/../helpers.php';

// Suggestions: getSuggestions, addSuggestion, editSuggestion, deleteSuggestion

// ---------------------------------------------------------------
// SUGGESTIONS (comments from participants)
// Stored in the `comment` table: FORpage = event id (the event shares the
// page's id), FORuser = author, content = {"text": "..."} as JSON.
// ---------------------------------------------------------------

// stops unless the acting employee is a participant of the event (the owner
// is always one); returns their id
function requireParticipant(mysqli $conn, int $eventId): int {
    $actingId = requireActingUser($conn);
    if (!eventExists($conn, $eventId)) {
        respond(404, ['error' => 'Event not found']);
    }
    if (!isParticipant($conn, $eventId, $actingId)) {
        respond(403, ['error' => 'Only participants of the event can perform this action']);
    }
    return $actingId;
}

// loads a suggestion that belongs to an event, or stops with 400/404
function loadSuggestion(mysqli $conn, int $suggestionId): array {
    if ($suggestionId <= 0) {
        respond(400, ['error' => 'suggestion_id is required']);
    }
    $row = fetchOne($conn,
        'SELECT c.ID, c.FORuser, c.FORpage
            FROM comment c JOIN calender_event e ON e.ID = c.FORpage
            WHERE c.ID = ?',
        [$suggestionId]
    );
    if (!$row) {
        respond(404, ['error' => 'Suggestion not found']);
    }
    return $row;
}

// ---------------------------------------------------------------
// GET getSuggestions  ?event_id=1&start_id=0&limit=100  (or ?title=...&creator_email=...)
// oldest first
// ---------------------------------------------------------------
function getSuggestions(mysqli $conn): void {
    requireMethod('GET');

    $eventId      = (int)($_GET['event_id'] ?? 0);
    $titlePrefix  = trim((string)($_GET['title'] ?? $_GET['titel'] ?? ''));
    $creatorEmail = trim((string)($_GET['creator_email'] ?? ''));

    // Pagination parameters
    $startId = (int)($_GET['start_id'] ?? 0);
    $limit   = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    if ($limit <= 0) {
        $limit = 100;
    }

    // Om event_id saknas men titel eller creator_email anges, leta upp eventet först
    if ($eventId <= 0 && ($titlePrefix !== '' || $creatorEmail !== '')) {
        $sqlEvent = 'SELECT e.ID 
            FROM calender_event e 
            JOIN page pg ON pg.ID = e.ID 
            LEFT JOIN user o ON o.ID = pg.FORuser 
            WHERE 1 = 1';
        $paramsEvent = [];

        if ($creatorEmail !== '') {
            $sqlEvent .= ' AND o.email = ?';
            $paramsEvent[] = $creatorEmail;
        }

        if ($titlePrefix !== '') {
            $sqlEvent .= ' AND pg.titel LIKE ?';
            $paramsEvent[] = $titlePrefix . '%';
        }

        $sqlEvent .= ' LIMIT 1';

        $eventRow = fetchOne($conn, $sqlEvent, $paramsEvent);
        if ($eventRow) {
            $eventId = (int)$eventRow['ID'];
        } else {
            respond(404, ['error' => 'Event not found matching title and/or creator email']);
        }
    }

    // Fallback till event-resolver om den finns tillgänglig
    if ($eventId <= 0 && function_exists('resolveEventId')) {
        $eventId = resolveEventId($conn, $_GET);
    }

    if ($eventId <= 0) {
        respond(400, ['error' => 'event_id, title, or creator_email is required to identify the event']);
    }

    // Fetch suggestions with start_id clamping and limit
    $rows = fetchAll($conn,
        'SELECT c.ID AS suggestion_id, c.content, c.created_at, c.last_update,
                c.FORuser AS user_id, u.username, u.pfp_file
            FROM comment c
            LEFT JOIN user u ON u.ID = c.FORuser
            WHERE c.FORpage = ? AND c.ID >= ?
            ORDER BY c.created_at ASC, c.ID ASC
            LIMIT ?',
        [$eventId, $startId, $limit]
    );

    foreach ($rows as &$row) {
        $content = $row['content'] !== null ? json_decode($row['content'], true) : null;
        $row['text'] = is_array($content) ? ($content['text'] ?? null) : null;
        unset($row['content']);
    }
    unset($row);

    respond(200, [
        'event_id'    => $eventId,
        'start_id'    => $startId,
        'limit'       => $limit,
        'count'       => count($rows),
        'suggestions' => $rows
    ]);
}

// ---------------------------------------------------------------
// POST createSuggestion (the old name addSuggestion also works)   (participants only)
// body: {"title": "Morning Sync", "creator_email": "alice@test.com", "text": "Can we move it to 11:00?"}
// ---------------------------------------------------------------
function addSuggestion(mysqli $conn): void {
    requireMethod('POST');
    $body = getBody();

    $eventId = resolveEventId($conn, $body);
    $actingId = requireParticipant($conn, $eventId);
    $text = cleanSuggestionText($body['text'] ?? null);

    run(
        $conn,
        'INSERT INTO comment (content, FORuser, FORpage) VALUES (?, ?, ?)',
        [jsonContent($text), $actingId, $eventId]
    );
    respond(201, ['message' => 'Suggestion added successfully', 'suggestion_id' => $conn->insert_id]);
}

// ---------------------------------------------------------------
// POST editSuggestion
// Updates a suggestion for a specific calendar event.
// Body: { "suggestion_id": 1, "title": "Morning Sync", "creator_email": "alice@test.com", "content": "..." }
// ---------------------------------------------------------------
function editSuggestion(mysqli $conn): void {
    requireMethod('POST', 'PUT');

    // 1. Ensure the user is authenticated
    $actingId = requireActingUser($conn);

    // 2. Parse request body
    $body = getBody();
    $suggestionId = (int)($body['suggestion_id'] ?? 0);
    if ($suggestionId <= 0) {
        respond(400, ['error' => 'suggestion_id is required']);
    }

    $eventId = resolveEventId($conn, $body);
    $newContent = cleanSuggestionText($body['content'] ?? $body['text'] ?? null);

    // 3. Load suggestion and check event match
    $suggestion = loadSuggestion($conn, $suggestionId);
    if ((int)$suggestion['FORuser'] !== $actingId) {
        respond(403, ['error' => 'Only the author can edit a suggestion']);
    }

    run(
        $conn,
        'UPDATE comment SET content = ?, last_update = NOW() WHERE ID = ? AND FORpage = ?',
        [jsonContent($newContent), $suggestionId, $eventId]
    );

    // 4. Return success response
    respond(200, [
        'message' => 'Suggestion updated successfully',
        'suggestion_id' => $suggestionId,
        'event_id' => $eventId,
        'updated_at' => date('Y-m-d H:i:s')
    ]);
}

// ---------------------------------------------------------------
// DELETE deleteSuggestion (POST also accepted)
// suggestion_id and event identifier can be sent in the body or URL parameters
// body: {"suggestion_id": 9, "title": "Morning Sync", "creator_email": "alice@test.com"}
// ---------------------------------------------------------------
function deleteSuggestion(mysqli $conn): void {
    requireMethod('DELETE', 'POST');
    $body = getBody();
    $params = !empty($body) ? $body : $_GET;

    $suggestionId = (int)($params['suggestion_id'] ?? 0);
    if ($suggestionId <= 0) {
        respond(400, ['error' => 'suggestion_id is required']);
    }

    $eventId = resolveEventId($conn, $params);

    // Load the suggestion and ensure it actually belongs to this event (FORpage)
    $suggestion = loadSuggestion($conn, $suggestionId);
    if (!$suggestion || (int)$suggestion['FORpage'] !== $eventId) {
        respond(404, ['error' => 'Suggestion not found for the specified event']);
    }

    // Authenticate the acting user
    $actingId = requireActingUser($conn);

    // Check permissions (Owner of the event or Author of the suggestion)
    $page = fetchOne($conn, 'SELECT FORuser FROM page WHERE ID = ?', [$eventId]);
    $isOwner = $page && (int)$page['FORuser'] === $actingId;
    $isAuthor = (int)$suggestion['FORuser'] === $actingId;
    
    if (!$isOwner && !$isAuthor) {
        respond(403, ['error' => 'Only the author or the owner of the event can delete a suggestion']);
    }

    // Delete the comment/suggestion securely matching both ID and event (FORpage)
    run($conn, 'DELETE FROM comment WHERE ID = ? AND FORpage = ?', [$suggestionId, $eventId]);
    
    respond(200, [
        'message' => 'Suggestion deleted successfully',
        'suggestion_id' => $suggestionId,
        'event_id' => $eventId
    ]);
}