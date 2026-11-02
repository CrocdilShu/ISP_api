<?php
// Part of company.php. Not a page of its own: it only defines functions.
if (!defined('COMPANY_API')) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../helpers.php';

// ---------------------------------------------------------------
// POST addCompany
// Adds a new company with a username and a securely hashed password.
// ---------------------------------------------------------------
function addCompany(mysqli $conn): void {
    requireMethod('POST');

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $username = trim((string)($input['username'] ?? ''));
    $password = (string)($input['password'] ?? '');

    if ($username === '' || $password === '') {
        respond(400, ['error' => 'Username and password are required']);
    }

    // Check if company/username already exists
    $existing = fetchOne($conn, 'SELECT ID FROM company WHERE username = ?', [$username]);
    if ($existing) {
        respond(409, ['error' => 'Company with this username already exists']);
    }

    // Hash the password securely before storing
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $conn->prepare('INSERT INTO company (username, password) VALUES (?, ?)');
    $stmt->bind_param('ss', $username, $hashedPassword);
    $stmt->execute();
    $companyId = $stmt->insert_id;
    $stmt->close();

    respond(201, [
        'success' => true,
        'company_id' => $companyId,
        'username' => $username
    ]);
}

// ---------------------------------------------------------------
// PUT / POST editCompany
// Edits an existing company's username and/or password.
// ---------------------------------------------------------------
function editCompany(mysqli $conn): void {
    requireMethod('PUT', 'POST');

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $companyId = (int)($input['company_id'] ?? $input['id'] ?? 0);
    $username = trim((string)($input['username'] ?? ''));
    $password = (string)($input['password'] ?? '');

    if ($companyId <= 0) {
        respond(400, ['error' => 'Valid company_id or id is required']);
    }

    // Verify company exists
    $company = fetchOne($conn, 'SELECT ID FROM company WHERE ID = ?', [$companyId]);
    if (!$company) {
        respond(404, ['error' => 'Company not found']);
    }

    $updates = [];
    $params = [];
    $types = '';

    if ($username !== '') {
        // Check uniqueness if username is changing
        $existing = fetchOne($conn, 'SELECT ID FROM company WHERE username = ? AND ID != ?', [$username, $companyId]);
        if ($existing) {
            respond(409, ['error' => 'Username is already taken by another company']);
        }
        $updates[] = 'username = ?';
        $params[] = $username;
        $types .= 's';
    }

    if ($password !== '') {
        // Hash new password if provided
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $updates[] = 'password = ?';
        $params[] = $hashedPassword;
        $types .= 's';
    }

    if (empty($updates)) {
        respond(400, ['error' => 'No fields provided to update']);
    }

    $params[] = $companyId;
    $types .= 'i';

    $sql = 'UPDATE company SET ' . implode(', ', $updates) . ' WHERE ID = ?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();

    respond(200, [
        'success' => true,
        'company_id' => $companyId,
        'message' => 'Company updated successfully'
    ]);
}

// ---------------------------------------------------------------
// POST changeCompany
// Changes the active COMPANY_ID globally by looking up the username.
// ---------------------------------------------------------------
function changeCompany(mysqli $conn): void {
    requireMethod('POST');

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $username = trim((string)($input['username'] ?? ''));

    if ($username === '') {
        respond(400, ['error' => 'Username is required to change company']);
    }

    // Find company based on username
    $company = fetchOne($conn, 'SELECT ID, username FROM company WHERE username = ?', [$username]);
    if (!$company) {
        respond(404, ['error' => 'Company not found for the given username']);
    }

    $companyId = (int)$company['ID'];

    // Svae the new ID in the json
    $configFile = __DIR__ . '/../../config/current_company.json';
    $configData = [
        'company_id' => $companyId,
        'username'   => $company['username'],
        'updated_at' => date('Y-m-d H:i:s')
    ];

    if (file_put_contents($configFile, json_encode($configData, JSON_PRETTY_PRINT)) === false) {
        respond(500, ['error' => 'Failed to update active company configuration']);
    }

    respond(200, [
        'success'    => true,
        'message'    => 'Active COMPANY_ID updated successfully',
        'company_id' => $companyId,
        'username'   => $company['username']
    ]);
}