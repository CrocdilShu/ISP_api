<?php
// Part of calender.php. Not a page of its own: it only defines functions.
if (!defined('CALENDER_API')) {
    http_response_code(403);
    exit;
}

// Events:
// getEvents
// createNewEvent
// editEvent


// ---------------------------------------------------------------
// GET getEvents
// optional: ?from=&to=(Y-m-d H:i:s)
// ?user_id=x (events the ID is an participant of)
// returns ALL events of the company unless user_id is given.
// Repeating events are returned ONCE (first occurrence + rule),
// ---------------------------------------------------------------
function getEvents(mysqli $conn): void {
    requireMethod('GET');

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

    if (!empty($_GET['user_id'])) {
        $sql .= ' AND EXISTS (SELECT 1 FROM participant p
            WHERE p.FORcalender_event = e.ID AND p.FORuser = ?)';
        $params[] = (int)$_GET['user_id'];
    }
    if (!empty($_GET['from']) && validDate($_GET['from'])) {
        $sql .= ' AND (e.end_time >= ? OR (r.ID IS NOT NULL AND (r.end_date IS NULL OR r.end_date >= ?)))';
        $params[] = $_GET['from'];
        $params[] = $_GET['from'];
    }
    if (!empty($_GET['to']) && validDate($_GET['to'])) {
        $sql .= ' AND e.start_time <= ?';
        $params[] = $_GET['to'];
    }
    $sql .= ' ORDER BY e.start_time ASC';

    $events = fetchAll($conn, $sql, $params);
    foreach ($events as &$ev) {
        // Return in JSON to match database data type
        $ev['content'] = $ev['content'] !== null ? json_decode($ev['content'], true) : null;
    }
    unset($ev);

    respond(200, ['events' => $events]);
}

// ---------------------------------------------------------------
// POST createNewEvent
// body: {
//   "user_id": ,                       (employee creating it = the owner, required)
//   "title": "",                       (required, max 255 characters)
//   "content": {},                     (optional, any JSON, stored in page.content)
//   "start_time": "YYYY-MM-DD HH:MM:SS",
//   "end_time": "YYYY-MM-DD HH:MM:SS",
//   "recurrence": {"frequency":"","interval_step":,"end_date":},   (optional)
//   "participants": [x,x]               (optional, employee ids from the user table)
// }
// Owner of event counts as participant
// ---------------------------------------------------------------
function createNewEvent(mysqli $conn): void {
    requireMethod('POST');
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

    $ownerId = requireActingUser($conn);
    $participants = is_array($body['participants'] ?? null) ? $body['participants'] : [];
    $userIds = cleanUserIds(array_merge([$ownerId], $participants));
    requireUsersExist($conn, $userIds);

    try {
        $conn->begin_transaction();

        run($conn, 'INSERT INTO page (titel, content, FORuser) VALUES (?, ?, ?)', [$title, $content, $ownerId]);
        $pageId = $conn->insert_id;

        $recurrenceId = $recurrence ? insertRecurrence($conn, $recurrence) : null;

        // Page and calender event IDs are the same
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
// POST editEvent only for owner
// body: {"user_id":5, "event_id":1,
//        "title":"", "content":{}, "start_time":"", "end_time":"",
//        "recurrence": {"frequency":"daily","interval_step":2,"end_date":null}}
// only send the fields that should change
// "recurrence": null -> makes the event non-repeating
// "content": null -> clears the description
// ---------------------------------------------------------------
function editEvent(mysqli $conn): void {
    requireMethod('POST');
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

    // Validates start and end time 
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

    // Validates text fields
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

    // Validate requrrence
    $changeRecurrence = array_key_exists('recurrence', $body);
    $newRecurrence = null;
    if ($changeRecurrence && $body['recurrence'] !== null) {
        if (!is_array($body['recurrence'])) {
            respond(400, ['error' => 'recurrence must be an object or null']);
        }
        $newRecurrence = validateRecurrence($body['recurrence']);
    }

    if (!$fields && !$pageFields && !$changeRecurrence) respond(400, ['error' => 'nothing to update']);

    // Check what the final time is
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
            $pageParams[] = $eventId; // The event has the same ID as the ID in db
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