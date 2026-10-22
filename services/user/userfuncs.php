<?php
// Part of user.php. Not a page of its own: it only defines functions.
if (!defined('USER_API')) {
    http_response_code(403);
    exit;
}

// Functions: getUser, createNewUser, editUser, deleteUser
// Who makes calls is grabbed from users token

// role name => how powerful it is. A higher number may manage lower numbers.
const ROLE_LEVELS = ['user' => 1, 'admin' => 2, 'root_admin' => 3];

const DEFAULT_PFP = 'pictures/defpfp.png';

// ---------------------------------------------------------------
// helpers for this file
// ---------------------------------------------------------------
function roleLevel(?string $roleName): int {
    return ROLE_LEVELS[$roleName] ?? 0;
}

function loadUserWithRole(mysqli $conn, int $id): ?array {
    return fetchOne(
        $conn,
        'SELECT u.ID, u.email, u.username, u.FORrole, r.role_name
        FROM user u JOIN role r ON r.ID = u.FORrole
        WHERE u.ID = ?',
        [$id]
    );
}

// The employee making the request, as a user row.
// $required = true : stops with 401 if nobody is logged in
// $required = false: returns null if nobody is logged in
function actingUserRow(mysqli $conn, bool $required): ?array {
    $id = $required ? requireActingUser($conn) : actingUserId($conn);
    return $id > 0 ? loadUserWithRole($conn, $id) : null;
}

// May $acting change or delete $target (when it is not themselves)?
// root_admin: anyone. admin: only users with a lower role. user: nobody.
function canManageUser(array $acting, array $target): bool {
    $a = roleLevel($acting['role_name']);
    return $a === 3 || $a > roleLevel($target['role_name']);
}

function findRole(mysqli $conn, int $roleId): ?array {
    return fetchOne($conn, 'SELECT ID, role_name FROM role WHERE ID = ?', [$roleId]);
}

function rootAdminCount(mysqli $conn): int {
    return (int)fetchOne(
        $conn,
        "SELECT COUNT(*) AS n FROM user u JOIN role r ON r.ID = u.FORrole WHERE r.role_name = 'root_admin'"
    )['n'];
}

function emailTaken(mysqli $conn, string $email, int $exceptUserId = 0): bool {
    return fetchOne(
        $conn,
        'SELECT 1 FROM user WHERE email = ? AND ID <> ?',
        [$email, $exceptUserId]
    ) !== null;
}

// 8-72 characters (72 bytes is the limit of the bcrypt hash PHP uses)
function cleanPasswordInput($raw): string {
    if (!is_string($raw) || strlen($raw) < 8 || strlen($raw) > 72) {
        respond(400, ['error' => 'Password must be between 8 and 72 characters']);
    }
    return $raw;
}

// description / pfp_file: text up to 255 characters, or null (an empty text counts as null)
function cleanText255($raw, string $field): ?string {
    if ($raw === null) return null;
    if (!is_string($raw) || mb_strlen(trim($raw)) > 255) {
        respond(400, ['error' => "Field '$field' must be at most 255 characters, or null"]);
    }
    $text = trim($raw);
    return $text === '' ? null : $text;
}

// ---------------------------------------------------------------
// GET getUser
// optional: ?user_id=x  (or ?email=user@test.com) (get one specific user)
// optional: ?page_type=   blog | wiki | calendar  (the pages/events the users own)
// optional: ?title=M     (filters pages/events starting with M)
// optional: ?username=M  (filters users starting with M)
// ---------------------------------------------------------------
function getUser(mysqli $conn): void {
    requireMethod('GET');

    // optional login: decides what the caller may see
    $acting = actingUserRow($conn, false);
    $actingId = $acting ? (int)$acting['ID'] : 0;
    $isAdmin = $acting !== null && roleLevel($acting['role_name']) >= 2;

    $pageType = strtolower(trim((string)($_GET['page_type'] ?? '')));
    
    // Resolve user_id: accept explicit user_id or look up via email parameter
    $userId = (int)($_GET['user_id'] ?? 0);
    if ($userId <= 0 && !empty($_GET['email'])) {
        $lookupEmail = trim($_GET['email']);
        $userRow = fetchOne($conn, 'SELECT ID FROM user WHERE email = ?', [$lookupEmail]);
        if ($userRow) {
            $userId = (int)$userRow['ID'];
        } else {
            respond(404, ['error' => 'User not found by email']);
        }
    }

    $params = [];

    // Only getUser and nothing more: the users with ID and username (and email when allowed)
    if ($pageType === '') {
        $sql = 'SELECT u.ID, u.username, u.email FROM user u WHERE 1 = 1';
        
        if ($userId > 0) {
            $sql .= ' AND u.ID = ?';
            $params[] = $userId;
        }

        // Optional username prefix filter (e.g. ?username=M or ?name=M -> matches usernames starting with M)
        $usernamePrefix = trim((string)($_GET['username'] ?? $_GET['name'] ?? ''));
        if ($usernamePrefix !== '') {
            $sql .= ' AND u.username LIKE ?';
            $params[] = $usernamePrefix . '%';
        }

        $sql .= ' ORDER BY u.ID ASC';

        $users = fetchAll($conn, $sql, $params);
        foreach ($users as &$user) {
            if (!$isAdmin && (int)$user['ID'] !== $actingId) {
                unset($user['email']);
            }
        }
        unset($user);

        respond(200, ['users' => $users]);
    }

    // Depending on what page_type was asked for
    switch ($pageType) {
        case 'blog':
            $sql = 'SELECT u.ID AS user_id, u.username, pg.ID AS page_id, pg.titel AS title,
                        pg.content, pg.created_at, b.last_update, v.state AS visibility
                    FROM user u
                    JOIN page pg ON pg.FORuser = u.ID
                    JOIN page_blog b ON b.FORpage = pg.ID
                    JOIN visability v ON v.ID = b.FORvisability
                    WHERE 1 = 1';
            break;

        case 'wiki':
            $sql = 'SELECT u.ID AS user_id, u.username, pg.ID AS page_id, pg.titel AS title,
                        pg.content, pg.created_at, v.state AS visibility
                    FROM user u
                    JOIN page pg ON pg.FORuser = u.ID
                    JOIN page_wiki w ON w.FORpage = pg.ID
                    JOIN visability v ON v.ID = w.FORvisability
                    WHERE 1 = 1';
            break;

        case 'calendar':
            $sql = 'SELECT u.ID AS user_id, u.username, e.ID AS event_id, e.start_time, e.end_time,
                        e.created_at, pg.titel AS title, pg.content,
                        r.frequency, r.interval_step, r.end_date AS recurrence_end_date
                    FROM user u
                    JOIN page pg ON pg.FORuser = u.ID
                    JOIN calender_event e ON e.ID = pg.ID
                    LEFT JOIN recurrence r ON r.ID = e.FORrecurrence
                    WHERE 1 = 1';
            break;

        default:
            respond(400, ['error' => 'Invalid page_type. Choose from: blog, wiki, calendar']);
    }

    // Blog and wiki pages can be private: only the owner and admins may see those
    if (($pageType === 'blog' || $pageType === 'wiki') && !$isAdmin) {
        $sql .= " AND (v.state = 'public' OR pg.FORuser = ?)";
        $params[] = $actingId;
    }

    // Filter by resolved user_id if supplied
    if ($userId > 0) {
        $sql .= ' AND u.ID = ?';
        $params[] = $userId;
    }

    // Optional title prefix filter (e.g. ?title=M or ?titel=M -> matches titles starting with M)
    $titlePrefix = trim((string)($_GET['title'] ?? $_GET['titel'] ?? ''));
    if ($titlePrefix !== '') {
        $sql .= ' AND pg.titel LIKE ?';
        $params[] = $titlePrefix . '%';
    }

    $sql .= ' ORDER BY u.ID ASC';

    $results = fetchAll($conn, $sql, $params);

    // Decode the JSON content column
    foreach ($results as &$row) {
        $row['content'] = $row['content'] !== null ? json_decode($row['content'], true) : null;
    }
    unset($row);

    respond(200, [$pageType => $results]);
}

// ---------------------------------------------------------------
// POST createNewUser
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
        respond(400, ['error' => 'Username is required (max 255 characters)']);
    }

    $password    = cleanPasswordInput($body['password'] ?? null);
    $description = array_key_exists('description', $body) ? cleanText255($body['description'], 'description') : null;
    $pfpFile     = array_key_exists('pfp_file', $body) ? cleanText255($body['pfp_file'], 'pfp_file') : DEFAULT_PFP;

    // If no role was given it defaults to role.ID 1, which is user
    $forRole = isset($body['FORrole']) ? (int)$body['FORrole'] : 1;

    $firstUser = (int)fetchOne($conn, 'SELECT COUNT(*) AS n FROM user')['n'] === 0;

    if ($firstUser) {
        // Nobody exists yet who could act as admin: the first user becomes root_admin
        $role = fetchOne($conn, "SELECT ID, role_name FROM role WHERE role_name = 'root_admin'");
        if (!$role) {
            respond(500, ['error' => 'The role root_admin is missing in the database role table']);
        }
    } else {
        $acting = actingUserRow($conn, true);
        if (roleLevel($acting['role_name']) < 2) {
            respond(403, ['error' => 'Only admins can create users']);
        }

        $role = findRole($conn, $forRole);
        if (!$role) {
            respond(400, ['error' => 'Unknown role ID']);
        }
        // you can only hand out roles up to your own
        if (roleLevel($role['role_name']) > roleLevel($acting['role_name'])) {
            respond(403, ['error' => 'You cannot assign a role higher than your own']);
        }
    }

    if (emailTaken($conn, $email)) {
        respond(409, ['error' => 'Email address is already registered']);
    }

    try {
        run(
            $conn,
            'INSERT INTO user (email, password_hash, username, description, pfp_file, FORrole) VALUES (?, ?, ?, ?, ?, ?)',
            [$email, password_hash($password, PASSWORD_DEFAULT), $username, $description, $pfpFile, (int)$role['ID']]
        );
        respond(201, ['message' => 'User created successfully', 'user_id' => $conn->insert_id]);
    } catch (mysqli_sql_exception $e) {
        if ((int)$e->getCode() === 1062) {
            respond(409, ['error' => 'Email address is already registered']);
        }
        respond(500, ['error' => 'Could not create user']);
    }
}

// ---------------------------------------------------------------
// PUT editUser (POST also accepted)
// ---------------------------------------------------------------
function editUser(mysqli $conn): void {
    requireMethod('PUT', 'POST');
    $body = getBody();

    $userId = (int)($body['user_id'] ?? 0);
    if ($userId <= 0) {
        respond(400, ['error' => 'user_id is required']);
    }

    $acting = actingUserRow($conn, true);

    // Ensure user exists before trying to update
    $target = loadUserWithRole($conn, $userId);
    if (!$target) {
        respond(404, ['error' => 'User not found']);
    }

    $isSelf = (int)$acting['ID'] === $userId;
    if (!$isSelf && !canManageUser($acting, $target)) {
        respond(403, ['error' => 'You are not allowed to modify this user']);
    }

    $fields = [];
    $params = [];

    // Validate and update email
    if (array_key_exists('email', $body)) {
        $email = trim((string)$body['email']);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
            respond(400, ['error' => 'A valid email is required (max 255 characters)']);
        }
        if (emailTaken($conn, $email, $userId)) {
            respond(409, ['error' => 'Email address is already registered']);
        }
        $fields[] = 'email = ?';
        $params[] = $email;
    }

    // Validate and update username
    if (array_key_exists('username', $body)) {
        $username = trim((string)$body['username']);
        if ($username === '' || mb_strlen($username) > 255) {
            respond(400, ['error' => 'Username must be 1-255 characters']);
        }
        $fields[] = 'username = ?';
        $params[] = $username;
    }

    // Validate and hash new password if provided
    if (array_key_exists('password', $body)) {
        $fields[] = 'password_hash = ?';
        $params[] = password_hash(cleanPasswordInput($body['password']), PASSWORD_DEFAULT);
    }

    // Update description (allows null to clear)
    if (array_key_exists('description', $body)) {
        $fields[] = 'description = ?';
        $params[] = cleanText255($body['description'], 'description');
    }

    // Update profile picture file path (allows null to clear)
    if (array_key_exists('pfp_file', $body)) {
        $fields[] = 'pfp_file = ?';
        $params[] = cleanText255($body['pfp_file'], 'pfp_file');
    }

    // Update user role ID
    if (array_key_exists('FORrole', $body) && (int)$body['FORrole'] !== (int)$target['FORrole']) {
        if ($isSelf) {
            respond(403, ['error' => 'You cannot change your own role']);
        }
        if (roleLevel($acting['role_name']) < 2) {
            respond(403, ['error' => 'Only admins can change roles']);
        }
        $role = findRole($conn, (int)$body['FORrole']);
        if (!$role) {
            respond(400, ['error' => 'Invalid role ID']);
        }
        if (roleLevel($role['role_name']) > roleLevel($acting['role_name'])) {
            respond(403, ['error' => 'You cannot assign a role higher than your own']);
        }
        $fields[] = 'FORrole = ?';
        $params[] = (int)$role['ID'];
    }

    if (!$fields) {
        respond(400, ['error' => 'No fields provided to update']);
    }

    try {
        $params[] = $userId;
        run($conn, 'UPDATE user SET ' . implode(', ', $fields) . ' WHERE ID = ?', $params);

        respond(200, ['message' => 'User updated successfully']);
    } catch (mysqli_sql_exception $e) {
        if ((int)$e->getCode() === 1062) {
            respond(409, ['error' => 'Email address is already registered']);
        }
        respond(500, ['error' => 'Could not update user']);
    }
}

// ---------------------------------------------------------------
// DELETE deleteUser (POST also accepted)
// ---------------------------------------------------------------
function deleteUser(mysqli $conn): void {
    requireMethod('DELETE', 'POST');
    $body = getBody();

    $email = trim((string)($body['email'] ?? $_GET['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(400, ['error' => 'A valid email is required']);
    }

    $acting = actingUserRow($conn, true);

    // Check if the user exists
    $target = fetchOne(
        $conn,
        'SELECT u.ID, u.email, u.FORrole, r.role_name
        FROM user u JOIN role r ON r.ID = u.FORrole
        WHERE u.email = ?',
        [$email]
    );
    if (!$target) {
        respond(404, ['error' => 'User not found']);
    }
    $userId = (int)$target['ID'];

    $isSelf = (int)$acting['ID'] === $userId;
    if (!$isSelf && !canManageUser($acting, $target)) {
        respond(403, ['error' => 'You are not allowed to delete this user']);
    }

    // Check what was asked for BEFORE anything is deleted
    $allowedTypes = ['calendar', 'calendar_event', 'wiki', 'blog', 'account', 'user'];
    $removeTypes = $body['remove'] ?? null;
    if ($removeTypes !== null) {
        if (!is_array($removeTypes) || !$removeTypes) {
            respond(400, ['error' => 'Remove must be a non-empty array of data types']);
        }
        $clean = [];
        foreach ($removeTypes as $type) {
            $type = is_string($type) ? strtolower(trim($type)) : '';
            if (!in_array($type, $allowedTypes, true)) {
                respond(400, ['error' => 'Unknown type in remove list', 'allowed' => $allowedTypes]);
            }
            $clean[] = $type;
        }
        $removeTypes = array_values(array_unique($clean));
    }

    $deletesAccount = $removeTypes === null
        || in_array('account', $removeTypes, true)
        || in_array('user', $removeTypes, true);

    if ($deletesAccount && $target['role_name'] === 'root_admin' && rootAdminCount($conn) <= 1) {
        respond(409, ['error' => 'The last root_admin cannot be deleted']);
    }

    try {
        $conn->begin_transaction();

        if ($removeTypes === null) {
            run($conn, 'DELETE FROM user WHERE ID = ?', [$userId]);
            $conn->commit();
            respond(200, ['message' => 'User and all associated data deleted', 'email' => $email]);
        }

        foreach ($removeTypes as $type) {
            switch ($type) {
                case 'calendar':
                case 'calendar_event':
                    run(
                        $conn,
                        'DELETE pg FROM page pg
                        JOIN calender_event e ON e.ID = pg.ID
                        WHERE pg.FORuser = ?',
                        [$userId]
                    );
                    break;

                case 'wiki':
                    run(
                        $conn,
                        'DELETE pg FROM page pg
                        JOIN page_wiki w ON w.FORpage = pg.ID
                        WHERE pg.FORuser = ?',
                        [$userId]
                    );
                    break;

                case 'blog':
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
                    run($conn, 'DELETE FROM user WHERE ID = ?', [$userId]);
                    break;
            }
        }

        $conn->commit();
        respond(200, [
            'message' => 'Requested user data removed successfully',
            'email' => $email,
            'removed_types' => $removeTypes
        ]);

    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        if ((int)$e->getCode() === 1451) {
            respond(409, ['error' => 'Cannot remove data because dependent records exist']);
        }
        respond(500, ['error' => 'Could not process data removal']);
    }
}