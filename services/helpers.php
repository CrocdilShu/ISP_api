<?php
// Part of calender.php. Not a page of its own: it only defines functions.
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

// the employee making the request (sent by the caller, see top of file)
function actingUserId(): int {
    if (!empty($_SERVER['HTTP_X_USER_ID'])) return (int)$_SERVER['HTTP_X_USER_ID'];
    if (!empty($_GET['user_id'])) return (int)$_GET['user_id'];
    $body = getBody();
    return (int)($body['user_id'] ?? 0);
}

function requireActingUser(mysqli $conn): int {
    $id = actingUserId();
    if ($id <= 0) respond(400, ['error' => 'user_id (the employee making the request) is required']);
    if (!fetchOne($conn, 'SELECT 1 FROM user WHERE ID = ?', [$id])) {
        respond(400, ['error' => 'unknown user_id']);
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