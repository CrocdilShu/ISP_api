<?php
define('USER_API', true);   // lets the part files know they are loaded through this file

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/providerauth.php';
require_once __DIR__ . '/userpic.php';

// Shared helpers
require_once __DIR__ . '/../helpers.php';

// User files that contains the funcs
require_once __DIR__ . '/userfuncs.php';
require_once __DIR__ . '/userlogin.php';
require_once __DIR__ . '/rootlogin.php';

header('Content-Type: application/json; charset=utf-8');

// make mysqli throw exceptions on errors (so try/catch works on every PHP version)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$action = $_GET['action'] ?? '';

// Handle action that dosnt need token to call
switch ($action) {
    case 'getUser':
        getUser($conn);
        exit;
    case 'loginUser':
        loginUser($conn);
        exit;
    case 'logoutUser':
        logoutUser($conn);
        exit;
}

// For all other actions, check authentication/service access first
if ($action !== 'rootLogin') {
    $rootUser = fetchOne(
        $conn, 
        'SELECT token FROM root_user WHERE ID = ? LIMIT 1', 
        [COMPANY_ID]
    );
    $storedSessionKey = $rootUser['token'] ?? null;

    $accountId = null;

    // 2. Validate the company's stored token against the provider's service bitmask
    if (!hasServiceAccess($storedSessionKey, SERVICE_CALENDAR, $accountId)) {
        respond(403, ['error' => 'Company session token is invalid or expired. Please run rootLogin.']);
    }
}

// Handle protected actions
switch ($action) {
    case 'createNewUser':   createNewUser($conn);   break;
    case 'editUser':        editUser($conn);        break;
    case 'deleteUser':      deleteUser($conn);      break;
    case 'rootLogin':       rootLogin($conn);       break;

    default:
        respond(404, ['error' => 'unknown action']);
        break;
}