<?php
// Part of calender.php. Not a page of its own: it only defines functions.
if (!defined('USER_API')) {
    http_response_code(403);
    exit;
}

$defpfp =   __DIR__ . '/../pictures/defpfp.png';
// Events: getUser, createNewUser, editUser, deleteUser

// ---------------------------------------------------------------
// GET getUser
// optional: ? user_id=x        (get specific user)
// optional: ? page_type        (blog, wiki, calendar)(get specific page)
// returns ALL users of the company unless user_id is given.
// ---------------------------------------------------------------
function getUser(mysqli $conn): void {
    requireMethod('GET');

    $pageType = $_GET['page_type'] ?? '';
    $userId = $_GET['user_id'] ?? null;
    $params = [];

    // If only getUser and nothing more, show all users with ID, emails and username
    if (empty($pageType)) {
        $sql = 'SELECT u.ID, u.username, u.email FROM user u WHERE 1 = 1';
        
        if (!empty($userId)) {
            $sql .= ' AND u.ID = ?';
            $params[] = (int)$userId;
        }
        $sql .= ' ORDER BY u.ID ASC';

        $users = fetchAll($conn, $sql, $params);
        respond(200, ['users' => $users]);
        return;
    }

    // Depending on what you had for page_type
    switch (strtolower($pageType)) {
        case 'blog':
            $sql = 'SELECT u.ID AS user_id, u.username, pg.ID AS page_id, pg.titel AS title, 
                        pg.content, pg.created_at, b.last_update, v.state AS visibility
                    FROM user u
                    JOIN page pg ON pg.FORuser = u.ID
                    JOIN page_blog b ON b.FORpage = pg.ID[cite: 1]
                    JOIN visability v ON v.ID = b.FORvisability[cite: 1]
                    WHERE 1 = 1';
            break;

        case 'wiki':
            $sql = 'SELECT u.ID AS user_id, u.username, pg.ID AS page_id, pg.titel AS title, 
                        pg.content, pg.created_at, v.state AS visibility
                    FROM user u
                    JOIN page pg ON pg.FORuser = u.ID[cite: 1]
                    JOIN page_wiki w ON w.FORpage = pg.ID[cite: 1]
                    JOIN visability v ON v.ID = w.FORvisability[cite: 1]
                    WHERE 1 = 1';
            break;
        case 'calendar':
            $sql = 'SELECT u.ID AS user_id, u.username, e.ID AS event_id, e.start_time, e.end_time, 
                        e.created_at, pg.titel AS title, pg.content,
                        r.frequency, r.interval_step, r.end_date AS recurrence_end_date
                    FROM user u
                    JOIN page pg ON pg.FORuser = u.ID[cite: 1]
                    JOIN calender_event e ON e.ID = pg.ID[cite: 1]
                    LEFT JOIN recurrence r ON r.ID = e.FORrecurrence[cite: 1]
                    WHERE 1 = 1';
            break;

        default:
            respond(400, ['error' => 'Invalid page_type. Choose from: blog, wiki, calendar.']);
            return;
    }

    // Filter by user_id if supplied
    if (!empty($userId)) {
        $sql .= ' AND u.ID = ?';
        $params[] = (int)$userId;
    }

    $sql .= ' ORDER BY u.ID ASC';

    $results = fetchAll($conn, $sql, $params);

    // Decode JSON content columns where applicable
    foreach ($results as &$row) {
        if (isset($row['content'])) {
            $row['content'] = $row['content'] !== null ? json_decode($row['content'], true) : null;
        }
    }
    unset($row);

    respond(200, [$pageType => $results]);
}

// ---------------------------------------------------------------
// POST createNewUser
// body: {
//      "email": "user@example.com",     (required, max 255 chars)
//      "username": "username",          (required, max 255 chars)
//      "password": "plain_password",    (required)
//      "description": "...",            (optional)
//      "pfp_file": "...",               (optional)
//      "FORrole": x                     (optional, defaults to 1 for standard user)
// }
// ---------------------------------------------------------------
function createNewUser(mysqli $conn): void {
    requireMethod('POST');
    $body = getBody();

    // Validate email
    $email = trim((string)($body['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
        respond(400, ['error' => 'A valid email is required (max 255 characters)']);
    }

    // Validate username
    $username = trim((string)($body['username'] ?? ''));
    if ($username === '' || mb_strlen($username) > 255) {
        respond(400, ['error' => 'username is required (max 255 characters)']);
    }

    // Validate password
    $password = (string)($body['password'] ?? '');
    if ($password === '') {
        respond(400, ['error' => 'password is required']);
    }

    // Optional fields
    $description = isset($body['description']) ? trim((string)$body['description']) : null;
    $pfpFile = isset($body['pfp_file']) ? trim((string)$body['pfp_file']) : $defpfp;
    
    // Handles roles, if no role was given it defaults to role.ID 1 which is user
    $forRole = isset($body['FORrole']) ? (int)$body['FORrole'] : 1;

    // Securely hash the password before saving
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    try {
        $sql = 'INSERT INTO user (email, password_hash, username, description, pfp_file, FORrole) VALUES (?, ?, ?, ?, ?, ?)';
        run($conn, $sql, [$email, $passwordHash, $username, $description, $pfpFile, $forRole]);

        $userId = $conn->insert_id;

        respond(201, ['message' => 'user created', 'user_id' => $userId]);
    } catch (mysqli_sql_exception $e) {
        // Error handle for duplicate emails
        if ($conn->errno === 1062) {
            respond(409, ['error' => 'Email address is already registered']);
        }
        respond(500, ['error' => 'Could not create user']);
    }
}

// ---------------------------------------------------------------
// PUT editUser (POST also accepted)
// body: {
//       "user_id": 5,
//       "email": "new@example.com",          (optional)
//       "username": "new_username",          (optional)
//       "password": "new_plain_password",    (optional)
//       "description": "...",                (optional)
//       "pfp_file": "...",                   (optional)
//       "FORrole": 2                         (optional)
// }
// only send the fields you want to change
// "description": null -> clears the description
// "pfp_file": null    -> clears the profile picture
// ---------------------------------------------------------------
function editUser(mysqli $conn): void {
    requireMethod('PUT', 'POST');
    $body = getBody();

    $userId = (int)($body['user_id'] ?? 0);
    if ($userId <= 0) {
        respond(400, ['error' => 'user_id is required']);
    }

    // Ensure user exists before trying to update
    $current = fetchOne(
        $conn,
        'SELECT ID, email FROM user WHERE ID = ?',
        [$userId]
    );
    if (!$current) {
        respond(404, ['error' => 'user not found']);
    }

    $fields = [];
    $params = [];

    // Validate and update email
    if (array_key_exists('email', $body)) {
        $email = trim((string)$body['email']);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
            respond(400, ['error' => 'a valid email is required (max 255 characters)']);
        }
        $fields[] = 'email = ?';
        $params[] = $email;
    }

    // Validate and update username
    if (array_key_exists('username', $body)) {
        $username = trim((string)$body['username']);
        if ($username === '' || mb_strlen($username) > 255) {
            respond(400, ['error' => 'username must be 1-255 characters']);
        }
        $fields[] = 'username = ?';
        $params[] = $username;
    }

    // Validate and hash new password if provided
    if (array_key_exists('password', $body)) {
        $password = (string)$body['password'];
        if ($password === '') {
            respond(400, ['error' => 'password cannot be empty if provided']);
        }
        $fields[] = 'password_hash = ?';
        $params[] = password_hash($password, PASSWORD_DEFAULT);
    }

    // Update description (allows null to clear)
    if (array_key_exists('description', $body)) {
        $fields[] = 'description = ?';
        $params[] = $body['description'] !== null ? trim((string)$body['description']) : null;
    }

    // Update profile picture file path (allows null to clear)
    if (array_key_exists('pfp_file', $body)) {
        $fields[] = 'pfp_file = ?';
        $params[] = $body['pfp_file'] !== null ? trim((string)$body['pfp_file']) : null;
    }

    // Update user role ID
    if (array_key_exists('FORrole', $body)) {
        $forRole = (int)$body['FORrole'];
        if ($forRole <= 0) {
            respond(400, ['error' => 'invalid role ID']);
        }
        $fields[] = 'FORrole = ?';
        $params[] = $forRole;
    }

    if (!$fields) {
        respond(400, ['error' => 'nothing to update']);
    }

    try {
        $params[] = $userId;
        run($conn, 'UPDATE user SET ' . implode(', ', $fields) . ' WHERE ID = ?', $params);

        respond(200, ['message' => 'user updated']);
    } catch (mysqli_sql_exception $e) {
        // Handle unique constraint violation like email
        if ($conn->errno === 1062) {
            respond(409, ['error' => 'email address is already registered']);
        }
        respond(500, ['error' => 'could not update user']);
    }
}

// ---------------------------------------------------------------
// DELETE deleteUser (POST also accepted)
// body: {
//       "email": "user@example.com",
//       "remove": ["calendar", "wiki"]     (optional: specify what to remove)
// }
// 
// - If "remove" is omitted, deletes the entire user and all data.
// - Available removal types: "calendar", "wiki", "blog", "account" (or "user")
//   Example: keep page_type by removing them from the remove array.
// ---------------------------------------------------------------
function deleteUser(mysqli $conn): void {
    requireMethod('DELETE', 'POST');
    $body = getBody();

    $email = trim((string)($body['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(400, ['error' => 'A valid email is required']);
    }

    // Check if the user exists
    $user = fetchOne(
        $conn,
        'SELECT ID, email FROM user WHERE email = ?',
        [$email]
    );
    if (!$user) {
        respond(404, ['error' => 'user not found']);
    }

    $userId = (int)$user['ID'];
    $removeTypes = $body['remove'] ?? null;

    try {
        $conn->begin_transaction();

        // If no specific types are given, delete the user entirely (cascades all pages/events)
        if ($removeTypes === null) {
            run($conn, 'DELETE FROM user WHERE ID = ?', [$userId]);
            $conn->commit();
            respond(200, ['message' => 'user and all associated data deleted', 'email' => $email]);
            return;
        }

        if (!is_array($removeTypes)) {
            respond(400, ['error' => 'remove must be an array of data types']);
        }

        // Process selective removal based on requested types
        foreach ($removeTypes as $type) {
            switch (strtolower(trim($type))) {
                case 'calendar':
                case 'calendar_event':
                    // Delete calendar events and their underlying base pages for this user
                    run(
                        $conn,
                        'DELETE pg FROM page pg 
                        JOIN calender_event e ON e.ID = pg.ID 
                        WHERE pg.FORuser = ?',
                        [$userId]
                    );
                    break;

                case 'wiki':
                    // Delete wiki pages and their underlying base pages for this user
                    run(
                        $conn,
                        'DELETE pg FROM page pg 
                        JOIN page_wiki w ON w.FORpage = pg.ID 
                        WHERE pg.FORuser = ?',
                        [$userId]
                    );
                    break;

                case 'blog':
                    // Delete blog pages and their underlying base pages for this user
                    run(
                        $conn,
                        'DELETE pg FROM page pg 
                        JOIN page_blog b ON b.FORpage = pg.ID 
                        WHERE pg.FORuser = ?',
                        [$userId]
                    );
                    break;

                case 'account':
                case 'user':
                    // Delete the user account itself (cascades any remaining pages/relations)
                    run($conn, 'DELETE FROM user WHERE ID = ?', [$userId]);
                    break;
            }
        }

        $conn->commit();
        respond(200, [
            'message' => 'requested user data removed successfully', 
            'email' => $email, 
            'removed_types' => $removeTypes
        ]);

    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        // Handle foreign key constraint violations if any remain
        if ($e->getCode() === 1451 || $conn->errno === 1451) {
            respond(409, ['error' => 'Cannot remove data because dependent records exist']);
        }
        respond(500, ['error' => 'Could not process data removal']);
    }
}