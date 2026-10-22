<?php
// Part of calender.php. Not a page of its own: it only defines functions.
if (!defined('CALENDER_API')) {
    http_response_code(403);
    exit;
}

// Participants: getParticipants, addParticipants, editParticipants

// ---------------------------------------------------------------
// POST (or GET) getParticipants
// Body option A: {"title": "Morning Sync", "creator_email": "alice@test.com"}
// Body option B: {"event_id": 16}
// ---------------------------------------------------------------
function getParticipants(mysqli $conn): void {
    // Support both POST (body) and GET (query parameters) for maximum flexibility
    $data = ($_SERVER['REQUEST_METHOD'] === 'POST') ? getBody() : $_GET;

    // Automatically resolves the event ID using title/creator or event_id
    $eventId = resolveEventId($conn, $data);

    $rows = fetchAll(
        $conn,
        'SELECT p.ID AS participant_id, u.ID AS user_id, u.username, u.email, u.pfp_file
            FROM participant p
            LEFT JOIN user u ON u.ID = p.FORuser
            WHERE p.FORcalender_event = ?',
        [$eventId]
    );
    
    respond(200, ['participants' => $rows]);
}

// ---------------------------------------------------------------
// POST addParticipants
// body: {"title": "Morning Sync", "creator_email": "alice@test.com", "participants": [2, "bob@test.com"]}
// (or emails[] / user_ids[])
// ---------------------------------------------------------------
function addParticipants(mysqli $conn): void {
    requireMethod('POST');
    $body = getBody();

    $eventId = resolveEventId($conn, $body);
    
    // Accept participants, emails, or user_ids flexibly
    $rawParticipants = $body['participants'] ?? $body['emails'] ?? $body['user_ids'] ?? [];

    if (empty($rawParticipants)) {
        respond(400, ['error' => 'participants[] or emails[] are required']);
    }

    // Verify the caller is the owner of the event
    requireOwner($conn, $eventId);

    // Resolve mixed inputs (IDs and emails) via helper
    $userIds = resolveParticipants($conn, $rawParticipants);
    requireUsersExist($conn, $userIds);

    $added = [];
    try {
        $conn->begin_transaction();
        foreach ($userIds as $id) {
            if (isParticipant($conn, $eventId, $id)) continue; // skip duplicates
            run($conn, 'INSERT INTO participant (FORcalender_event, FORuser) VALUES (?, ?)', [$eventId, $id]);
            $added[] = $id;
        }
        $conn->commit();
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        respond(500, ['error' => 'could not add participants']);
    }

    respond(200, [
        'message' => 'participants added successfully',
        'added_user_ids' => $added
    ]);
}

// ---------------------------------------------------------------
// PUT editParticipants (POST also accepted)
// body: {"title": "Morning Sync", "creator_email": "alice@test.com", "participants": [2, "bob@test.com"]}
// replaces the whole participant list (the owner always stays).
// ---------------------------------------------------------------
function editParticipants(mysqli $conn): void {
    requireMethod('PUT', 'POST');
    $body = getBody();

    $eventId = resolveEventId($conn, $body);
    
    // Accept participants, emails, or user_ids flexibly
    $rawParticipants = $body['participants'] ?? $body['emails'] ?? $body['user_ids'] ?? [];

    if (!is_array($rawParticipants)) {
        respond(400, ['error' => 'participants[] must be an array']);
    }

    // 1. Get the owner's user ID so they are never accidentally removed
    $ownerId = requireOwner($conn, $eventId);

    // 2. Ensure the owner's ID is included in the list
    $rawParticipants[] = $ownerId;

    // 3. Resolve all IDs and emails cleanly via the helper
    $wanted = resolveParticipants($conn, $rawParticipants);
    requireUsersExist($conn, $wanted);

    try {
        $conn->begin_transaction();

        if ($wanted) {
            // Remove everyone not in the new list
            $inIds = implode(',', array_fill(0, count($wanted), '?'));
            run(
                $conn,
                "DELETE FROM participant WHERE FORcalender_event = ? AND (FORuser IS NULL OR FORuser NOT IN ($inIds))",
                array_merge([$eventId], $wanted)
            );
        } else {
            run($conn, 'DELETE FROM participant WHERE FORcalender_event = ?', [$eventId]);
        }

        // Add the missing ones
        foreach ($wanted as $id) {
            if (!isParticipant($conn, $eventId, $id)) {
                run($conn, 'INSERT INTO participant (FORcalender_event, FORuser) VALUES (?, ?)', [$eventId, $id]);
            }
        }

        $conn->commit();
        respond(200, ['message' => 'participants updated successfully']);
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        respond(500, ['error' => 'could not update participants']);
    }
}