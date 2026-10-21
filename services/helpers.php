<?php
// Shared by every service (calender.php, user.php, ...). Not a page of its own:
// it only defines functions. Refuse to run if someone opens this file directly.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit;
}

// allowed values for recurrence.frequency (change to whatever you want to support)
const ALLOWED_FREQUENCIES = ['daily', 'weekly', 'monthly', 'yearly'];

// ---------------------------------------------------------------
// helpers
// ---------------------------------------------------------------
function respond(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// stops with 405 unless the request uses one of the given HTTP methods
function requireMethod(string ...$methods): void {
    if (!in_array($_SERVER['REQUEST_METHOD'], $methods, true)) {
        respond(405, ['error' => 'use ' . implode(' or ', $methods)]);
    }
}

function getBody(): array {
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function validDate(?string $value): bool {
    if (!$value) return false;
    $d = DateTime::createFromFormat('Y-m-d H:i:s', $value);
    return $d && $d->format('Y-m-d H:i:s') === $value;
}

// run a prepared query. Parameter types (i = int, s = string) are detected
// automatically. Returns the executed statement.
function run(mysqli $conn, string $sql, array $params = []): mysqli_stmt {
    $stmt = $conn->prepare($sql);
    if ($params) {
        $types = '';
        foreach ($params as $p) {
            $types .= is_int($p) ? 'i' : 's';
        }
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt;
}

function fetchAll(mysqli $conn, string $sql, array $params = []): array {
    return run($conn, $sql, $params)->get_result()->fetch_all(MYSQLI_ASSOC);
}

function fetchOne(mysqli $conn, string $sql, array $params = []): ?array {
    $row = run($conn, $sql, $params)->get_result()->fetch_assoc();
    return $row ?: null;
}

function eventExists(mysqli $conn, int $eventId): bool {
    return fetchOne($conn, 'SELECT 1 FROM calender_event WHERE ID = ?', [$eventId]) !== null;
}

function isParticipant(mysqli $conn, int $eventId, int $userId): bool {
    return fetchOne(
        $conn,
        'SELECT 1 FROM participant WHERE FORcalender_event = ? AND FORuser = ? LIMIT 1',
        [$eventId, $userId]
    ) !== null;
}

// ---------------------------------------------------------------
// Employee identity.
// There are TWO tokens in every call:
//   X-Session-Key   the shared company key from the provider (what services may be used)
//   X-User-Token    the employee's own token, issued by userLogin (who is calling)
// The token in the database is hashed
// ---------------------------------------------------------------

// Set the minutes it should be valid, when you call you update it with 30 minutes
const USER_SESSION_MINUTES = 30;

// the employee token from the X-User-Token header or Authorization Bearer, or null
function getUserToken(): ?string {
    // Check custom header X-User-Token
    if (!empty($_SERVER['HTTP_X_USER_TOKEN'])) {
        return trim($_SERVER['HTTP_X_USER_TOKEN']);
    }
    
    // Check standard Authorization Bearer header
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = trim($_SERVER['HTTP_AUTHORIZATION']);
        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return trim($matches[1]);
        }
    }
    return null;
}

// Issues a new token for an employee. Returns the token (only shows once) and when it expires.
function createUserSession(mysqli $conn, int $userId): array {
    // Delete expired sessions
    run($conn, 'DELETE FROM user_session WHERE expire_date < NOW()');

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);

    // Check if this user already has a row in user_session
    $existingSession = fetchOne($conn, 'SELECT ID FROM user_session WHERE FORuser = ?', [$userId]);

    if ($existingSession) {
        // Update the table row
        $sessionId = (int)$existingSession['ID'];
        run(
            $conn,
            'UPDATE user_session 
            SET token_hash = ?, last_used = NOW(), expire_date = DATE_ADD(NOW(), INTERVAL ? MINUTE) 
            WHERE ID = ?',
            [$tokenHash, USER_SESSION_MINUTES, $sessionId]
        );
    } else {
        // INSERT if employe havnt had session before
        run(
            $conn,
            'INSERT INTO user_session (token_hash, FORuser, last_used, expire_date)
            VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE))',
            [$tokenHash, $userId, USER_SESSION_MINUTES]
        );
        $sessionId = (int)$conn->insert_id;
    }

    // Fetch the updated expire_date to return to the client
    $row = fetchOne($conn, 'SELECT expire_date FROM user_session WHERE ID = ?', [$sessionId]);

    return ['token' => $token, 'expires_at' => $row['expire_date']];
}
// A valid token is renewed when making a call, it adds USER_SESSION_MINUTES from now.
function actingUserId(?mysqli $conn = null): int {
    static $cache = null;
    if ($cache !== null) return $cache;

    $conn ??= $GLOBALS['conn'] ?? null;
    $token = getUserToken();
    if ($token === null || $conn === null) return 0;

    $session = fetchOne(
        $conn,
        'SELECT ID, FORuser FROM user_session WHERE token_hash = ? AND expire_date > NOW()',
        [hash('sha256', $token)]
    );
    if (!$session) return $cache = 0;

    run(
        $conn,
        'UPDATE user_session SET last_used = NOW(), expire_date = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE ID = ?',
        [USER_SESSION_MINUTES, (int)$session['ID']]
    );
    return $cache = (int)$session['FORuser'];
}

// The employee making the request, or a 401. Use on all calls that needs to know who made the call
function requireActingUser(mysqli $conn): int {
    if (getUserToken() === null) {
        respond(401, ['error' => 'user token required (header X-User-Token)']);
    }
    $id = actingUserId($conn);
    if ($id <= 0) {
        respond(401, ['error' => 'invalid or expired user token']);
    }
    return $id;
}

// stops with 404/403 unless the acting employee owns the event; returns their id
function requireOwner(mysqli $conn, int $eventId): int {
    $actingId = requireActingUser($conn);
    $event = fetchOne(
        $conn,
        'SELECT pg.FORuser AS owner_id
        FROM calender_event e JOIN page pg ON pg.ID = e.ID
        WHERE e.ID = ?',
        [$eventId]
    );
    if (!$event) respond(404, ['error' => 'event not found']);
    if ((int)$event['owner_id'] !== $actingId) {
        respond(403, ['error' => 'only the owner of the event can do this']);
    }
    return $actingId;
}

// cleans a list of employee IDs (ints, unique, > 0) and checks that every one
// exists in the user table. Stops with 400 and the unknown IDs if not.
function cleanUserIds($raw): array {
    if (!is_array($raw)) return [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $raw), fn($i) => $i > 0)));
    return $ids;
}

function requireUsersExist(mysqli $conn, array $ids): void {
    if (!$ids) return;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $found = array_column(fetchAll($conn, "SELECT ID FROM user WHERE ID IN ($in)", $ids), 'ID');
    $missing = array_values(array_diff($ids, array_map('intval', $found)));
    if ($missing) {
        respond(400, ['error' => 'unknown user ids', 'unknown_user_ids' => $missing]);
    }
}

// validates a recurrence object from the request
// {"frequency":"weekly","interval_step":1,"end_date":"2026-12-31 23:59:59"}
// returns [frequency, interval_step, end_date] or stops with a 400 error
function validateRecurrence(array $rec): array {
    $frequency = strtolower(trim((string)($rec['frequency'] ?? '')));
    if (!in_array($frequency, ALLOWED_FREQUENCIES, true)) {
        respond(400, ['error' => 'frequency must be one of: ' . implode(', ', ALLOWED_FREQUENCIES)]);
    }

    $step = isset($rec['interval_step']) ? (int)$rec['interval_step'] : 1;
    if ($step < 1) respond(400, ['error' => 'interval_step must be 1 or higher']);

    $endDate = $rec['end_date'] ?? null;
    if ($endDate !== null && !validDate($endDate)) {
        respond(400, ['error' => 'recurrence end_date must be "Y-m-d H:i:s" or null']);
    }

    return [$frequency, $step, $endDate];
}

function insertRecurrence(mysqli $conn, array $validated): int {
    run($conn, 'INSERT INTO recurrence (frequency, interval_step, end_date) VALUES (?, ?, ?)', $validated);
    return $conn->insert_id;
}

// delete a recurrence row if no event uses it anymore
function cleanupRecurrence(mysqli $conn, ?int $recurrenceId): void {
    if (!$recurrenceId) return;
    run(
        $conn,
        'DELETE FROM recurrence WHERE ID = ?
         AND NOT EXISTS (SELECT 1 FROM calender_event WHERE FORrecurrence = ?)',
        [$recurrenceId, $recurrenceId]
    );
}