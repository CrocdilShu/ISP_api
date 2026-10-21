<?php
define('CALENDER_API', true);   // lets the part files know they are loaded through this file

require_once __DIR__ . '/../../config/db.php';   // gives us the mysqli connection $conn
require_once __DIR__ . '/../../config/providerauth.php';           // your verify-token file

// the calendar code, split by topic
require_once __DIR__ . '/../helpers.php';        // shared helpers, validation, recurrence
require_once __DIR__ . '/events.php';         // getEvents, createNewEvent, editEvent
require_once __DIR__ . '/Participants.php';   // getParticipants, addParticipants, editParticipants
require_once __DIR__ . '/suggestions.php';    // getSuggestions, addSuggestion, editSuggestion, deleteSuggestion

header('Content-Type: application/json; charset=utf-8');

// make mysqli throw exceptions on errors (so try/catch works on every PHP version)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ---------------------------------------------------------------
// Two tokens identify every call:
//   X-Session-Key   the shared company key from the provider: which services may be used
//   X-User-Token    the employee's own token (issued by userLogin): who is calling
// Every event is a page: page.titel / page.content hold the title and description
// and page.FORuser is the OWNER. calender_event.ID is both its primary key and a
// foreign key to page.ID, so an event and its page share the same id.
// Only the owner can edit the event and invite/remove participants.
// Reading is open to anyone with the company key.
// Suggestions are rows in the `comment` table (comment.FORpage = event id).
// ---------------------------------------------------------------

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
    case 'getEvents':        getEvents($conn);        break;
    case 'getParticipants':  getParticipants($conn);  break;
    case 'createNewEvent':   createNewEvent($conn);   break;
    case 'editEvent':        editEvent($conn);        break;
    case 'addParticipants':  addParticipants($conn);  break;
    case 'editParticipants': editParticipants($conn); break;
    case 'getSuggestions':   getSuggestions($conn);   break;
    case 'createSuggestion': addSuggestion($conn);    break;
    case 'addSuggestion':    addSuggestion($conn);    break;
    case 'editSuggestion':   editSuggestion($conn);   break;
    case 'deleteSuggestion': deleteSuggestion($conn); break;
    default:
        respond(404, ['error' => 'unknown action']);
}