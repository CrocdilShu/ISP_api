<?php
// Part of calender.php. Not a page of its own: it only defines functions.
if (!defined('CALENDER_API')) {
    http_response_code(403);
    exit;
}

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
    if (!eventExists($conn, $eventId)) respond(404, ['error' => 'event not found']);
    if (!isParticipant($conn, $eventId, $actingId)) {
        respond(403, ['error' => 'only participants of the event can do this']);
    }
    return $actingId;
}

function cleanSuggestionText($raw): string {
    if (!is_string($raw)) respond(400, ['error' => 'text is required']);
    $text = trim($raw);
    if ($text === '' || mb_strlen($text) > 5000) {
        respond(400, ['error' => 'text is required (max 5000 characters)']);
    }
    return $text;
}

// loads a suggestion that belongs to an event, or stops with 400/404
function loadSuggestion(mysqli $conn, int $suggestionId): array {
    if ($suggestionId <= 0) respond(400, ['error' => 'suggestion_id is required']);
    $row = fetchOne(
        $conn,
        'SELECT c.ID, c.FORuser, c.FORpage
         FROM comment c JOIN calender_event e ON e.ID = c.FORpage
         WHERE c.ID = ?',
        [$suggestionId]
    );
    if (!$row) respond(404, ['error' => 'suggestion not found']);
    return $row;
}

// ---------------------------------------------------------------
// GET getSuggestions  ?event_id=1
// oldest first
// ---------------------------------------------------------------
function getSuggestions(mysqli $conn): void {
    requireMethod('GET');

    $eventId = (int)($_GET['event_id'] ?? 0);
    if ($eventId <= 0) respond(400, ['error' => 'event_id is required']);
    if (!eventExists($conn, $eventId)) respond(404, ['error' => 'event not found']);

    $rows = fetchAll(
        $conn,
        'SELECT c.ID AS suggestion_id, c.content, c.created_at, c.last_update,
                c.FORuser AS user_id, u.username, u.pfp_file
         FROM comment c
         LEFT JOIN user u ON u.ID = c.FORuser
         WHERE c.FORpage = ?
         ORDER BY c.created_at ASC, c.ID ASC',
        [$eventId]
    );

    foreach ($rows as &$row) {
        $content = $row['content'] !== null ? json_decode($row['content'], true) : null;
        $row['text'] = is_array($content) ? ($content['text'] ?? null) : null;
        unset($row['content']);
    }
    unset($row);

    respond(200, ['suggestions' => $rows]);
}

// ---------------------------------------------------------------
// POST createSuggestion (the old name addSuggestion also works)   (participants only)
// body: {"event_id":1, "text":"Can we move it to 11:00?"}
// ---------------------------------------------------------------
function addSuggestion(mysqli $conn): void {
    requireMethod('POST');
    $body = getBody();

    $eventId = (int)($body['event_id'] ?? 0);
    if ($eventId <= 0) respond(400, ['error' => 'event_id is required']);
    $actingId = requireParticipant($conn, $eventId);
    $text = cleanSuggestionText($body['text'] ?? null);

    run(
        $conn,
        'INSERT INTO comment (content, FORuser, FORpage) VALUES (?, ?, ?)',
        [json_encode(['text' => $text], JSON_UNESCAPED_UNICODE), $actingId, $eventId]
    );
    respond(201, ['message' => 'suggestion added', 'suggestion_id' => $conn->insert_id]);
}

// ---------------------------------------------------------------
// PUT editSuggestion (POST also accepted)   (the author only, and they must still be a participant)
// body: {"suggestion_id":9, "text":"New text"}
// ---------------------------------------------------------------
function editSuggestion(mysqli $conn): void {
    requireMethod('PUT', 'POST');
    $body = getBody();

    $suggestion = loadSuggestion($conn, (int)($body['suggestion_id'] ?? 0));
    $actingId = requireParticipant($conn, (int)$suggestion['FORpage']);
    if ((int)$suggestion['FORuser'] !== $actingId) {
        respond(403, ['error' => 'you can only edit your own suggestions']);
    }
    $text = cleanSuggestionText($body['text'] ?? null);

    run(
        $conn,
        'UPDATE comment SET content = ? WHERE ID = ?',
        [json_encode(['text' => $text], JSON_UNESCAPED_UNICODE), (int)$suggestion['ID']]
    );
    respond(200, ['message' => 'suggestion updated']);
}

// ---------------------------------------------------------------
// DELETE deleteSuggestion (POST also accepted)
// suggestion_id can be sent in the URL or in the body   (the author, or the owner of the event)
// body: {"suggestion_id":9}
// ---------------------------------------------------------------
function deleteSuggestion(mysqli $conn): void {
    requireMethod('DELETE', 'POST');
    $body = getBody();

    $suggestion = loadSuggestion($conn, (int)($body['suggestion_id'] ?? $_GET['suggestion_id'] ?? 0));
    $actingId = requireActingUser($conn);

    $page = fetchOne($conn, 'SELECT FORuser FROM page WHERE ID = ?', [(int)$suggestion['FORpage']]);
    $isOwner = $page && (int)$page['FORuser'] === $actingId;
    $isAuthor = (int)$suggestion['FORuser'] === $actingId;
    if (!$isOwner && !$isAuthor) {
        respond(403, ['error' => 'only the author or the owner of the event can delete a suggestion']);
    }

    run($conn, 'DELETE FROM comment WHERE ID = ?', [(int)$suggestion['ID']]);
    respond(200, ['message' => 'suggestion deleted']);
}