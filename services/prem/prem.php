<?php
define('PREM_API', true);   // lets the part files know they are loaded through this file

require_once __DIR__ . '/../../config/db.php';   // gives us the mysqli connection $conn
require_once __DIR__ . '/../../config/providerauth.php';           // your verify-token file

// the calendar code, split by topic
require_once __DIR__ . '/../helpers.php';        // shared helpers, validation, recurrence
require_once __DIR__ . '/premapi.php';         // getEvents, createNewEvent, editEvent

header('Content-Type: application/json; charset=utf-8');

// make mysqli throw exceptions on errors (so try/catch works on every PHP version)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ---------------------------------------------------------------
// auth + routing:  calender.php?action=getEvents
// $accountId is the shared company token's id from the provider.
// It only proves access; it is never an employee.
// ---------------------------------------------------------------
// 1. Fetch the stored company/root token from the local database
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

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'sendPrem':        sendPrem($conn);        break;
    case 'deletePrem':        deletePrem($conn);        break;
    default:
        respond(404, ['error' => 'unknown action']);
}