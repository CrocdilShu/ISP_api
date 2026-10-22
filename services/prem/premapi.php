<?php

if (!defined('PREM_API')) {
    http_response_code(403);
    exit;
}

// ---------------------------------------------------------------
// POST sendPrem
// Simulates provisioning access to services.
// Requires X-User-Token header. Only allows 'root_admin'.
// Body: {
//          "services":"xxxx"
//       }
// blog, wiki, calend(a/e)r
// ---------------------------------------------------------------
function sendPrem(mysqli $conn): void {
    requireMethod('POST');

    // 1. Looks at user token if valid
    $userId = actingUserId($conn);
    if ($userId === 0) {
        respond(401, ['error' => 'unauthorized or invalid user token']);
    }

    // 2. Verifies that the user is a root_admin
    $userRole = fetchOne(
        $conn,
        'SELECT r.role_name FROM user u JOIN role r ON r.ID = u.FORrole WHERE u.ID = ?',
        [$userId]
    );

    if (($userRole['role_name'] ?? '') !== 'root_admin') {
        respond(403, ['error' => 'forbidden: only root_admin can provision services']);
    }

    // 3. Parses body
    $body = getBody();
    $service = trim((string)($body['service'] ?? ''));

    // 4. Validates the requested service, accepting both spellings of calendar
    $allowedServices = ['wiki', 'blog', 'calendar', 'calender'];

    if (!in_array($service, $allowedServices, true)) {
        respond(400, [
            'error' => 'invalid service. Allowed services are: wiki, blog, calendar'
        ]);
    }

    if ($service === 'calender') {
        $service = 'calendar';
    }

    // 5. Response
    respond(200, [
        'status' => 'success',
        'message' => "Successfully provisioned access to the {$service} service.",
        'service' => $service,
        'activated_at' => date('Y-m-d H:i:s')
    ]);
}

// ---------------------------------------------------------------
// POST deletePrem
// Simulates removing access to services.
// Requires X-User-Token header. Only allows 'root_admin'.
// ---------------------------------------------------------------
function deletePrem(mysqli $conn): void {
    requireMethod('POST');

    // 1. Looks at user token if valid
    $userId = actingUserId($conn);
    if ($userId === 0) {
        respond(401, ['error' => 'unauthorized or invalid user token']);
    }

    // 2. Verifies that the user is a root_admin
    $userRole = fetchOne(
        $conn,
        'SELECT r.role_name FROM user u JOIN role r ON r.ID = u.FORrole WHERE u.ID = ?',
        [$userId]
    );

    if (($userRole['role_name'] ?? '') !== 'root_admin') {
        respond(403, ['error' => 'forbidden: only root_admin can provision services']);
    }

    // 3. Parses body
    $body = getBody();
    $service = trim((string)($body['service'] ?? ''));

    // 4. Validates the requested service
    $allowedServices = ['wiki', 'blog', 'calendar', 'calender'];

    if (!in_array($service, $allowedServices, true)) {
        respond(400, [
            'error' => 'invalid service. Allowed services are: wiki, blog, calendar'
        ]);
    }

    if ($service === 'calender') {
        $service = 'calendar';
    }

    // 5. Response
    respond(200, [
        'status' => 'success',
        'message' => "Successfully deleted access to the {$service} service.",
        'service' => $service,
        'canceled_at' => date('Y-m-d H:i:s')
    ]);
}