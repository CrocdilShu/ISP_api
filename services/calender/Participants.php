<?php
// Part of calender.php. Not a page of its own: it only defines functions.
if (!defined('CALENDER_API')) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/providerauth.php';
require_once __DIR__ . '/../helpers.php';

// Participants: getParticipants, addParticipants (send invites), getInvites, respondToInvite, editParticipants

// ---------------------------------------------------------------
// POST (or GET) getParticipants
// Returns active participants who have accepted invitations.
// ---------------------------------------------------------------
function getParticipants(mysqli $conn): void {
    $data = ($_SERVER['REQUEST_METHOD'] === 'POST') ? getBody() : $_GET;
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
// POST addParticipants (Sends Event Invitations)
// body: {"title": "Morning Sync", "creator_email": "alice@test.com", "participants": ["test@test.com"]}
// Creates 'pending' invites instead of forcing participants directly.
// ---------------------------------------------------------------
function addParticipants(mysqli $conn): void {
    requireMethod('POST');
    $body = getBody();

    $eventId = resolveEventId($conn, $body);
    $rawParticipants = $body['participants'] ?? $body['emails'] ?? $body['user_ids'] ?? [];

    if (empty($rawParticipants)) {
        respond(400, ['error' => 'participants[] or emails[] are required']);
    }

    // Verify the caller is the owner of the event
    requireOwner($conn, $eventId);

    $userIds = resolveParticipants($conn, $rawParticipants);
    requireUsersExist($conn, $userIds);

    $invited = [];
    try {
        $conn->begin_transaction();
        
        $eventPage = fetchOne($conn, 'SELECT FORuser FROM page WHERE ID = ?', [$eventId]);
        $ownerId = $eventPage ? (int)$eventPage['FORuser'] : 0;

        foreach ($userIds as $id) {
            // Do not invite the owner (owner is automatically joined)
            if ($id === $ownerId) {
                continue;
            }

            // Check if invite already exists
            $existing = fetchOne(
                $conn, 
                'SELECT ID, status FROM event_invite WHERE FORcalender_event = ? AND FORuser = ?', 
                [$eventId, $id]
            );

            if ($existing) {
                // If previously declined, reset to pending upon re-invitation
                if ($existing['status'] !== 'accepted') {
                    run($conn, 'UPDATE event_invite SET status = ? WHERE ID = ?', ['pending', $existing['ID']]);
                }
            } else {
                run(
                    $conn, 
                    'INSERT INTO event_invite (FORcalender_event, FORuser, status) VALUES (?, ?, ?)', 
                    [$eventId, $id, 'pending']
                );
            }
            $invited[] = $id;
        }
        $conn->commit();
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        respond(500, ['error' => 'Could not send event invitations']);
    }

    respond(200, [
        'message' => 'Invitations sent successfully',
        'invited_user_ids' => $invited
    ]);
}

// ---------------------------------------------------------------
// GET getInvites (POST also accepted)
// Retrieves all pending event invitations for the currently authenticated user.
// 
// Headers:
// - Requires valid user authentication token.
// 
// Returns:
// - An array of pending event invites, including event details, start/end times, 
//   parsed JSON content, and event owner information.
// ---------------------------------------------------------------
function getInvites(mysqli $conn): void {
    requireMethod('GET', 'POST');
    
    $userId = actingUserId($conn);
    if ($userId === 0) {
        respond(401, ['error' => 'Unauthorized or invalid user token']);
    }

    $rows = fetchAll(
        $conn,
        'SELECT i.ID AS invite_id, i.FORcalender_event AS event_id, i.status, i.created_at,
                pg.titel AS event_title, pg.content AS event_content, e.start_time, e.end_time,
                o.ID AS owner_id, o.username AS owner_username, o.email AS owner_email
            FROM event_invite i
            JOIN calender_event e ON e.ID = i.FORcalender_event
            JOIN page pg ON pg.ID = e.ID
            LEFT JOIN user o ON o.ID = pg.FORuser
            WHERE i.FORuser = ? AND i.status = "pending"
            ORDER BY e.start_time ASC',
        [$userId]
    );

    foreach ($rows as &$row) {
        $row['event_content'] = $row['event_content'] !== null ? json_decode($row['event_content'], true) : null;
    }
    unset($row);

    respond(200, ['invites' => $rows]);
}

// ---------------------------------------------------------------
// POST respondToInvite
// body: {"invite_id": x, "action": "accept"} or "decline"
// ---------------------------------------------------------------
function respondToInvite(mysqli $conn): void {
    requireMethod('POST', 'PUT');
    
    $userId = actingUserId($conn);
    if ($userId === 0) {
        respond(401, ['error' => 'Unauthorized or invalid user token']);
    }

    $body = getBody();
    $inviteId = (int)($body['invite_id'] ?? 0);
    $action = strtolower(trim((string)($body['action'] ?? $body['status'] ?? '')));

    if ($inviteId <= 0) {
        respond(400, ['error' => 'invite_id is required']);
    }

    if (!in_array($action, ['accept', 'accepted', 'decline', 'declined'], true)) {
        respond(400, ['error' => 'Action must be either "accept" or "decline"']);
    }

    $newStatus = in_array($action, ['accept', 'accepted'], true) ? 'accepted' : 'declined';

    // Verify the invite exists and belongs to the currently authenticated user (security check)
    $invite = fetchOne(
        $conn,
        'SELECT FORcalender_event FROM event_invite WHERE ID = ? AND FORuser = ?',
        [$inviteId, $userId]
    );

    if (!$invite) {
        respond(404, ['error' => 'Invitation not found or unauthorized']);
    }

    $eventId = (int)$invite['FORcalender_event'];

    try {
        $conn->begin_transaction();

        // Update the invitation status
        run($conn, 'UPDATE event_invite SET status = ? WHERE ID = ?', [$newStatus, $inviteId]);

        if ($newStatus === 'accepted') {
            if (!isParticipant($conn, $eventId, $userId)) {
                run($conn, 'INSERT INTO participant (FORcalender_event, FORuser) VALUES (?, ?)', [$eventId, $userId]);
            }
        } else {
            run($conn, 'DELETE FROM participant WHERE FORcalender_event = ? AND FORuser = ?', [$eventId, $userId]);
        }

        $conn->commit();
        respond(200, [
            'message' => $newStatus === 'accepted' ? 'Invitation accepted successfully' : 'Invitation declined successfully',
            'invite_id' => $inviteId,
            'event_id' => $eventId,
            'status' => $newStatus
        ]);
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        respond(500, ['error' => 'Could not process invitation response']);
    }
}

// ---------------------------------------------------------------
// PUT editParticipants (POST also accepted)
// Overrides active participants directly (Owner only).
// ---------------------------------------------------------------
function editParticipants(mysqli $conn): void {
    requireMethod('PUT', 'POST');
    $body = getBody();

    $eventId = resolveEventId($conn, $body);
    $rawParticipants = $body['participants'] ?? $body['emails'] ?? $body['user_ids'] ?? [];

    if (!is_array($rawParticipants)) {
        respond(400, ['error' => 'participants[] must be an array']);
    }

    $ownerId = requireOwner($conn, $eventId);
    $rawParticipants[] = $ownerId;

    $wanted = resolveParticipants($conn, $rawParticipants);
    requireUsersExist($conn, $wanted);

    try {
        $conn->begin_transaction();

        if ($wanted) {
            $inIds = implode(',', array_fill(0, count($wanted), '?'));
            run(
                $conn,
                "DELETE FROM participant WHERE FORcalender_event = ? AND (FORuser IS NULL OR FORuser NOT IN ($inIds))",
                array_merge([$eventId], $wanted)
            );
        } else {
            run($conn, 'DELETE FROM participant WHERE FORcalender_event = ?', [$eventId]);
        }

        foreach ($wanted as $id) {
            if (!isParticipant($conn, $eventId, $id)) {
                run($conn, 'INSERT INTO participant (FORcalender_event, FORuser) VALUES (?, ?)', [$eventId, $id]);
            }
            // Also sync/mark invite as accepted for these users
            run(
                $conn,
                'INSERT INTO event_invite (FORcalender_event, FORuser, status) VALUES (?, ?, "accepted") 
                    ON DUPLICATE KEY UPDATE status = "accepted"',
                [$eventId, $id]
            );
        }

        $conn->commit();
        respond(200, ['message' => 'Participants updated successfully']);
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        respond(500, ['error' => 'Could not update participants']);
    }
}