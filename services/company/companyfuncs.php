<?php
// Part of company.php. Not a page of its own: it only defines functions.
if (!defined('COMPANY_API')) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../helpers.php';

// ---------------------------------------------------------------
// POST addCompany
// Adds a new company/root_user with a username and a securely hashed password.
// ID is left empty/NULL here and synchronized via Provider verification later.
// ---------------------------------------------------------------
function addCompany(mysqli $conn): void {
    requireMethod('POST');

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $username = trim((string)($input['username'] ?? ''));
    $password = (string)($input['password'] ?? '');

    if ($username === '' || $password === '') {
        respond(400, ['error' => 'Username and password are required']);
    }

    // Check if user/username already exists in root_user
    $existing = fetchOne($conn, 'SELECT ID FROM root_user WHERE username = ?', [$username]);
    if ($existing) {
        respond(409, ['error' => 'Company with this username already exists']);
    }

    // Hash the password securely before storing
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    // Insert into root_user without explicit ID (ID gets set/synced upon provider login)
    $stmt = $conn->prepare('INSERT INTO root_user (username, password_hash) VALUES (?, ?)');
    $stmt->bind_param('ss', $username, $hashedPassword);
    $stmt->execute();
    $stmt->close();

    respond(201, [
        'success'  => true,
        'username' => $username,
        'message'  => 'Company added successfully. Provider ID will be synced upon verification.'
    ]);
}

// ---------------------------------------------------------------
// PUT / POST editCompany
// Edits an existing root_user's username and/or password.
// ---------------------------------------------------------------
function editCompany(mysqli $conn): void {
    requireMethod('PUT', 'POST');

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $username    = trim((string)($input['username'] ?? ''));
    $newUsername = trim((string)($input['new_username'] ?? ''));
    $password    = (string)($input['password'] ?? '');

    if ($username === '') respond(400, ['error' => 'username is required']);
    if (!fetchOne($conn, 'SELECT 1 FROM root_user WHERE username = ?', [$username])) {
        respond(404, ['error' => 'Company not found']);
    }

    $updates = []; $params = [];
    if ($newUsername !== '') {
        if (fetchOne($conn, 'SELECT 1 FROM root_user WHERE username = ?', [$newUsername])) {
            respond(409, ['error' => 'Username is already taken by another company']);
        }
        $updates[] = 'username = ?'; $params[] = $newUsername;
    }
    if ($password !== '') {
        $updates[] = 'password_hash = ?'; $params[] = password_hash($password, PASSWORD_DEFAULT);
    }
    if (!$updates) respond(400, ['error' => 'No fields provided to update']);

    $params[] = $username;
    run($conn, 'UPDATE root_user SET ' . implode(', ', $updates) . ' WHERE username = ?', $params);
    respond(200, ['success' => true, 'message' => 'Company updated successfully']);
}

// ---------------------------------------------------------------
// POST changeCompany
// Changes the active COMPANY_ID globally by looking up the username in root_user.
// ---------------------------------------------------------------
function changeCompany(mysqli $conn): void {
    requireMethod('POST');

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $username = trim((string)($input['username'] ?? ''));
    if ($username === '') {
        respond(400, ['error' => 'Username is required to change company']);
    }

    $company = fetchOne($conn, 'SELECT ID, username FROM root_user WHERE username = ?', [$username]);
    if (!$company) {
        respond(404, ['error' => 'Company not found for the given username']);
    }

    $configFile = __DIR__ . '/../../config/current_company.json';
    $configData = [
        'company_id' => $company['ID'] !== null ? (int)$company['ID'] : null,
        'username'   => $company['username'],
        'updated_at' => date('Y-m-d H:i:s')
    ];
    if (file_put_contents($configFile, json_encode($configData, JSON_PRETTY_PRINT)) === false) {
        respond(500, ['error' => 'Failed to update active company configuration']);
    }

    respond(200, [
        'success'    => true,
        'message'    => 'Active company updated. Session key is fetched on the next call if needed.',
        'company_id' => $configData['company_id'],
        'username'   => $company['username']
    ]);
}