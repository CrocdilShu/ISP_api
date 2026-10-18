<?php
define('CALENDER_API', true);   // lets the part files know they are loaded through this file

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/providerauth.php';

// Calender files that contains the funcs
require_once __DIR__ . '/services/calender/helpers.php';
require_once __DIR__ . '/services/calender/events.php';
require_once __DIR__ . '/services/calender/participants.php';
require_once __DIR__ . '/services/calender/suggestions.php';

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
    case 'getEvents':        getEvents($conn);        break;
    case 'getParticipants':  getParticipants($conn);  break;
    case 'createNewEvent':   createNewEvent($conn);   break;
    case 'editEvent':        editEvent($conn);        break;
    case 'addParticipants':  addParticipants($conn);  break;
    case 'editParticipants': editParticipants($conn); break;
    case 'getSuggestions':   getSuggestions($conn);   break;
    case 'addSuggestion':    addSuggestion($conn);    break;
    case 'editSuggestion':   editSuggestion($conn);   break;
    case 'deleteSuggestion': deleteSuggestion($conn); break;
    default:
        respond(404, ['error' => 'unknown action']);
}