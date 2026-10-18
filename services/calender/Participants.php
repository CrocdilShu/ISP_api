<?php
// Part of calender.php. Not a page of its own: it only defines functions.
if (!defined('CALENDER_API')) {
    http_response_code(403);
    exit;
}

// Participants:
// getParticipants
// addParticipants
// editParticipants

// ---------------------------------------------------------------
// GET getParticipants
// ?user_id=x (returns specific user)
// ---------------------------------------------------------------
function getParticipants(mysqli $conn): void {
    requireMethod('GET');

    $eventId = (int)($_GET['event_id'] ?? 0);
    if ($eventId <= 0) respond(400, ['error' => 'event_id is required']);
    if (!eventExists($conn, $eventId)) respond(404, ['error' => 'event not found']);

    $rows = fetchAll(
        $conn,
        'SELECT p.ID AS participant_id, u.ID AS user_id, u.username, u.pfp_file
            FROM participant p
            LEFT JOIN user u ON u.ID = p.FORuser
            WHERE p.FORcalender_event = ?',
        [$eventId]
    );
    respond(200, ['participants' => $rows]);
}

// ---------------------------------------------------------------
// POST addParticipants
// body: {"user_id":x, "event_id":x, "user_ids":[x,x]}
// ---------------------------------------------------------------
function addParticipants(mysqli $conn): void {
    requireMethod('POST');
    $body = getBody();

    $eventId = (int)($body['event_id'] ?? 0);
    $userIds = cleanUserIds($body['user_ids'] ?? []);
    if ($eventId <= 0 || !$userIds) {
        respond(400, ['error' => 'event_id and user_ids[] are required']);
    }
    requireOwner($conn, $eventId);
    requireUsersExist($conn, $userIds);

    $added = [];
    try {
        $conn->begin_transaction();
        foreach ($userIds as $id) {
            if (isParticipant($conn, $eventId, $id)) continue; // skips duplicates
            run($conn, 'INSERT INTO participant (FORcalender_event, FORuser) VALUES (?, ?)', [$eventId, $id]);
            $added[] = $id;
        }
        $conn->commit();
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        respond(500, ['error' => 'could not add participants']);
    }

    respond(200, ['message' => 'participants added', 'added' => $added]);
}

// ---------------------------------------------------------------
// POST editParticipants
// body: {"user_id":x, "event_id":x, "user_ids":[x,x,x]}
// replaces the whole participant list with user_ids (the owner always stays).
// ---------------------------------------------------------------
function editParticipants(mysqli $conn): void {
    requireMethod('POST');
    $body = getBody();

    $eventId = (int)($body['event_id'] ?? 0);
    if ($eventId <= 0 || !isset($body['user_ids']) || !is_array($body['user_ids'])) {
        respond(400, ['error' => 'event_id and user_ids[] are required']);
    }
    $ownerId = requireOwner($conn, $eventId);

    // Owner of event allways stay in the list
    $wanted = cleanUserIds(array_merge([$ownerId], $body['user_ids']));
    requireUsersExist($conn, $wanted);

    try {
        $conn->begin_transaction();

        if ($wanted) {
            // Removes all the participants that isnt in the new list
            $in = implode(',', array_fill(0, count($wanted), '?'));
            run(
                $conn,
                "DELETE FROM participant WHERE FORcalender_event = ? AND (FORuser IS NULL OR FORuser NOT IN ($in))",
                array_merge([$eventId], $wanted)
            );
        } else {
            run($conn, 'DELETE FROM participant WHERE FORcalender_event = ?', [$eventId]);
        }

        // add the new participants
        foreach ($wanted as $id) {
            if (!isParticipant($conn, $eventId, $id)) {
                run($conn, 'INSERT INTO participant (FORcalender_event, FORuser) VALUES (?, ?)', [$eventId, $id]);
            }
        }

        $conn->commit();
        respond(200, ['message' => 'participants updated']);
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        respond(500, ['error' => 'could not update participants']);
    }
}