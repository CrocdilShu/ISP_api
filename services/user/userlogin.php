<?php
// Part of user.php. Not a page of its own: it only defines functions.
if (!defined('USER_API')) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../helpers.php';

// A valid bcrypt hash of a random text. It is checked when the email does not
// exist, so a wrong email and a wrong password take equally long to answer.
const DUMMY_PASSWORD_HASH = '$2y$10$ierol0lKnAtitBH2SzVLFO5QImS0FYaQmsPZvoBhqjkkel5cWPHl6';

// ---------------------------------------------------------------
// POST loginUser
// body: { "email": "...", "password": "..." }
// Checks the employee's password (user.password_hash) and issues their own
// token. Send it as  X-User-Token  on every later call. This is separate from
// the company key (X-Session-Key), which is still needed too.
// ---------------------------------------------------------------
function loginUser(mysqli $conn): void {
    requireMethod('POST');
    $body = getBody();

    $email    = trim((string)($body['email'] ?? ''));
    $password = (string)($body['password'] ?? '');
    if ($email === '' || $password === '') {
        respond(400, ['error' => 'Email and password are required']);
    }

    $user = fetchOne(
        $conn,
        'SELECT u.ID, u.username, u.password_hash, u.FORrole, r.role_name
        FROM user u JOIN role r ON r.ID = u.FORrole
        WHERE u.email = ?',
        [$email]
    );

    $passwordOk = password_verify($password, $user['password_hash'] ?? DUMMY_PASSWORD_HASH);
    if (!$user || !$passwordOk) {
        // the same answer for "no such email" and "wrong password"
        respond(401, ['error' => 'Invalid email or password']);
    }

    try {
        $session = createUserSession($conn, (int)$user['ID']);
    } catch (mysqli_sql_exception $e) {
        respond(500, ['error' => 'Could not create the user session']);
    }

    respond(200, [
        'message'    => 'Logged in successfully',
        'user_token' => $session['token'],
        'expires_at' => $session['expires_at'],
        'user'       => [
            'user_id'   => (int)$user['ID'],
            'username'  => $user['username'],
            'role_id'   => (int)$user['FORrole'],
            'role_name' => $user['role_name'],
        ],
    ]);
}

// ---------------------------------------------------------------
// POST logoutUser
// Ends the session of the token in X-User-Token.
// ---------------------------------------------------------------
function logoutUser(mysqli $conn): void {
    requireMethod('POST');

    $token = getUserToken();
    if ($token === null) {
        respond(401, ['error' => 'User token required in header X-User-Token']);
    }

    run($conn, 'DELETE FROM user_session WHERE token_hash = ?', [hash('sha256', $token)]);
    respond(200, ['message' => 'Logged out successfully']);
}