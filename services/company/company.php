<?php
define('COMPANY_API', true);
define('USER_API', true); // lets the part files know they are loaded through this file

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/providerauth.php';

// Shared helpers
require_once __DIR__ . '/../helpers.php';

// User files that contains the funcs
require_once __DIR__ . '/../user/userfuncs.php';
require_once __DIR__ . '/companyfuncs.php';

header('Content-Type: application/json; charset=utf-8');

// make mysqli throw exceptions on errors
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$action = $_GET['action'] ?? '';

$acting = actingUserRow($conn, true);
if (roleLevel($acting['role_name']) !== 3) {
    respond(403, ['error' => 'Only root_admin can perform company management']);
}

switch ($action) {
    case 'addCompany':
        addCompany($conn, $acting);
        exit;
    case 'editCompany':
        editCompany($conn, $acting);
        exit;
    case 'changeCompany':
        changeCompany($conn, $acting);
        exit;
    default:
        respond(404, ['error' => 'unknown action']);
        break;
}