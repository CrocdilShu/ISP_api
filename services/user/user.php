<?php
define('USER_API', true);   // lets the part files know they are loaded through this file

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/providerauth.php';

// Shared helpers
require_once __DIR__ . '/../calender/helpers.php';

// User files that contains the funcs
require_once __DIR__ . '/userfuncs.php';

header('Content-Type: application/json; charset=utf-8');

// make mysqli throw exceptions on errors (so try/catch works on every PHP version)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$sessionKey = getRequestSessionKey();
$accountId = null;

if (!hasServiceAccess($sessionKey, SERVICE_CALENDAR, $accountId)) {
    respond(403, ['error' => 'no access']);
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'getUser':         getUser($conn);         break;
    case 'createNewUser':   createNewUser($conn);   break;
    case 'editUser':        editUser($conn);        break;
    case 'deleteUser':      deleteUser($conn);      break;
    default:
        respond(404, ['error' => 'unknown action']);
}