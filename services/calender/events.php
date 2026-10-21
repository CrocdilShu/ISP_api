<?php
// Part of calender.php. Not a page of its own: it only defines functions.
if (!defined('CALENDER_API')) {
    http_response_code(403);
    exit;
}

// Events: postEvents, createNewEvent, editEvent

// ---------------------------------------------------------------
// POST getEvents
// optional: ?from=...&to=...  (Y-m-d H:i:s)
//           ?user_id=5        (only events that employee takes part in)
// returns ALL events of the company unless user_id is given.
// Repeating events are returned ONCE (first occurrence + rule),
// the client expands the repeats.
// ---------------------------------------------------------------
function getEvents(mysqli $conn): void {
    requireMethod('POST');

    // Läs JSON-data från request body
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $userId = $input['user_id'] ?? null;
    $from   = $input['from'] ?? null;
    $to     = $input['to'] ?? null;

    $sql = 'SELECT e.ID, e.start_time, e.end_time, e.created_at, e.FORrecurrence,
                pg.titel AS title, pg.content,
                pg.FORuser AS owner_id, o.username AS owner_username,
                r.frequency, r.interval_step, r.end_date AS recurrence_end_date
            FROM calender_event e
            JOIN page pg ON pg.ID = e.ID
            LEFT JOIN user o ON o.ID = pg.FORuser
            LEFT JOIN recurrence r ON r.ID = e.FORrecurrence
            WHERE 1 = 1';
    $params = [];

    if (!empty($userId)) {
        $sql .= ' AND EXISTS (SELECT 1 FROM participant p
            WHERE p.FORcalender_event = e.ID AND p.FORuser = ?)';
        $params[] = (int)$userId;
    }
    if (!empty($from) && validDate($from)) {
        // repeating events stay relevant until their rule ends
        $sql .= ' AND (e.end_time >= ? OR (r.ID IS NOT NULL AND (r.end_date IS NULL OR r.end_date >= ?)))';
        $params[] = $from;
        $params[] = $from;
    }
    if (!empty($to) && validDate($to)) {
        $sql .= ' AND e.start_time <= ?';
        $params[] = $to;
    }
    $sql .= ' ORDER BY e.start_time ASC';

    $events = fetchAll($conn, $sql, $params);
    foreach ($events as &$ev) {
        // page.content is a JSON column: return it as real JSON, not as a string
        $ev['content'] = $ev['content'] !== null ? json_decode($ev['content'], true) : null;
    }
    unset($ev);

    respond(200, ['events' => $events]);
}

// ---------------------------------------------------------------
// POST createNewEvent
// body: {
//   "title": "Team meeting",            (required, max 255 characters)
//   "content": { ... },                 (optional, any JSON, stored in page.content)
//   "start_time": "2026-10-20 10:00:00",
//   "end_time": "2026-10-20 11:00:00",
//   "recurrence": {"frequency":"weekly","interval_step":1,"end_date":null},   (optional)
//   "participants": [2,3]               (optional, employee ids from the user table)
// }
// An event is a page row (owner = page.FORuser) plus a calender_event row that
// reuses the page's id. The owner is also added as a participant.
// ---------------------------------------------------------------
function createNewEvent(mysqli $conn): void {
    requireMethod('POST');
    // the employee creating the event becomes its owner and is always a participant
    $ownerId = requireActingUser($conn);
    $body = getBody();

    $title = trim((string)($body['title'] ?? ''));
    if ($title === '' || mb_strlen($title) > 255) {
        respond(400, ['error' => 'title is required (max 255 characters)']);
    }
    $content = (array_key_exists('content', $body) && $body['content'] !== null)
        ? json_encode($body['content'])
        : null;

    $start = $body['start_time'] ?? null;
    $end   = $body['end_time'] ?? null;
    if (!validDate($start) || !validDate($end)) {
        respond(400, ['error' => 'start_time and end_time must be "Y-m-d H:i:s"']);
    }
    if ($end <= $start) respond(400, ['error' => 'end_time must be after start_time']);

    $recurrence = null;
    if (!empty($body['recurrence']) && is_array($body['recurrence'])) {
        $recurrence = validateRecurrence($body['recurrence']);
    }

    $participants = is_array($body['participants'] ?? null) ? $body['participants'] : [];
    $userIds = cleanUserIds(array_merge([$ownerId], $participants));
    requireUsersExist($conn, $userIds);

    try {
        $conn->begin_transaction();

        run($conn, 'INSERT INTO page (titel, content, FORuser) VALUES (?, ?, ?)', [$title, $content, $ownerId]);
        $pageId = $conn->insert_id;

        $recurrenceId = $recurrence ? insertRecurrence($conn, $recurrence) : null;

        // the event uses the page's id (calender_event.ID is a PK and an FK to page.ID)
        $eventId = $pageId;
        run(
            $conn,
            'INSERT INTO calender_event (ID, start_time, end_time, FORrecurrence) VALUES (?, ?, ?, ?)',
            [$eventId, $start, $end, $recurrenceId]
        );

        foreach ($userIds as $id) {
            run($conn, 'INSERT INTO participant (FORcalender_event, FORuser) VALUES (?, ?)', [$eventId, $id]);
        }

        $conn->commit();
        respond(201, ['message' => 'event created', 'event_id' => $eventId]);
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        respond(500, ['error' => 'could not create event']);
    }
}

// ---------------------------------------------------------------
// PUT editEvent (POST also accepted)   (owner only)
// body: {"event_id":1,
//        "title":"...", "content":{...}, "start_time":"...", "end_time":"...",
//        "recurrence": {"frequency":"daily","interval_step":2,"end_date":null}}
// only send the fields you want to change
// "recurrence": null  -> makes the event non-repeating
// "content": null     -> clears the description
// ---------------------------------------------------------------
function editEvent(mysqli $conn): void {
    requireMethod('PUT', 'POST');
    $body = getBody();

    $eventId = (int)($body['event_id'] ?? 0);
    if ($eventId <= 0) respond(400, ['error' => 'event_id is required']);
    requireOwner($conn, $eventId);

    $current = fetchOne(
        $conn,
        'SELECT start_time, end_time, FORrecurrence FROM calender_event WHERE ID = ?',
        [$eventId]
    );
    if (!$current) respond(404, ['error' => 'event not found']);

    // fields on calender_event
    $fields = [];
    $params = [];
    if (array_key_exists('start_time', $body)) {
        if (!validDate($body['start_time'])) respond(400, ['error' => 'bad start_time']);
        $fields[] = 'start_time = ?';
        $params[] = $body['start_time'];
    }
    if (array_key_exists('end_time', $body)) {
        if (!validDate($body['end_time'])) respond(400, ['error' => 'bad end_time']);
        $fields[] = 'end_time = ?';
        $params[] = $body['end_time'];
    }

    // fields on the page (title / description)
    $pageFields = [];
    $pageParams = [];
    if (array_key_exists('title', $body)) {
        $title = trim((string)$body['title']);
        if ($title === '' || mb_strlen($title) > 255) {
            respond(400, ['error' => 'title must be 1-255 characters']);
        }
        $pageFields[] = 'titel = ?';
        $pageParams[] = $title;
    }
    if (array_key_exists('content', $body)) {
        $pageFields[] = 'content = ?';
        $pageParams[] = $body['content'] === null ? null : json_encode($body['content']);
    }

    // validate the recurrence (if any) before we start changing things
    $changeRecurrence = array_key_exists('recurrence', $body);
    $newRecurrence = null;
    if ($changeRecurrence && $body['recurrence'] !== null) {
        if (!is_array($body['recurrence'])) {
            respond(400, ['error' => 'recurrence must be an object or null']);
        }
        $newRecurrence = validateRecurrence($body['recurrence']);
    }

    if (!$fields && !$pageFields && !$changeRecurrence) respond(400, ['error' => 'nothing to update']);

    // check the final times
    $newStart = $body['start_time'] ?? $current['start_time'];
    $newEnd   = $body['end_time'] ?? $current['end_time'];
    if ($newEnd <= $newStart) respond(400, ['error' => 'end_time must be after start_time']);

    $oldRecurrence = $current['FORrecurrence'] !== null ? (int)$current['FORrecurrence'] : null;

    try {
        $conn->begin_transaction();

        if ($changeRecurrence) {
            $newRecurrenceId = $newRecurrence ? insertRecurrence($conn, $newRecurrence) : null;
            $fields[] = 'FORrecurrence = ?';
            $params[] = $newRecurrenceId;
        }

        if ($fields) {
            $params[] = $eventId;
            run($conn, 'UPDATE calender_event SET ' . implode(', ', $fields) . ' WHERE ID = ?', $params);
        }

        if ($pageFields) {
            $pageParams[] = $eventId; // the page has the same id as the event
            run($conn, 'UPDATE page SET ' . implode(', ', $pageFields) . ' WHERE ID = ?', $pageParams);
        }

        if ($changeRecurrence) cleanupRecurrence($conn, $oldRecurrence);

        $conn->commit();
        respond(200, ['message' => 'event updated']);
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        respond(500, ['error' => 'could not update event']);
    }
}