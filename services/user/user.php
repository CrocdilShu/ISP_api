<?php
define('USER_API', true);   // lets the part files know they are loaded through this file

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/providerauth.php';

// Shared helpers
require_once __DIR__ . '/../helpers.php';

// User files that contains the funcs
require_once __DIR__ . '/userfuncs.php';
require_once __DIR__ . '/userlogin.php';

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

// Get valid token OR trigger the auto login
$sessionKey = getCompanySessionKey($conn);

// Verify directly with provider without forcing a specific service bit like calendar
$sessionData = verifySessionWithProvider($sessionKey);
if (!$sessionData) {
    // Try refreshing via auto-login if the token expired
    $sessionKey = getCompanySessionKey($conn);
    $sessionData = verifySessionWithProvider($sessionKey);
    if (!$sessionData) {
        respond(403, ['error' => 'Automated company session authentication failed.']);
    }
}
$accountId = isset($sessionData['user_id']) ? (int)$sessionData['user_id'] : null;

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