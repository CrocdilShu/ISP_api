<?php

// service bitmask
define('SERVICE_CALENDAR', 1); 
define('SERVICE_BLOG',     2); 
define('SERVICE_WIKI',     4); 

// url
define('PROVIDER_BASE_URL',   'https://theprovider.ntigskovde.se');
define('PROVIDER_LOGIN_URL',  PROVIDER_API_BASE_URL . '/login');
define('PROVIDER_VERIFY_URL', PROVIDER_API_BASE_URL . '/verify');
define('PROVIDER_LOGOUT_URL', PROVIDER_API_BASE_URL . '/logout');

// check if user has access to service
function hasServiceAccess(?string $sessionKey, int $requiredService, ?int &$outUserId = null): bool {
    if (empty($sessionKey)) {
        return false;
    }

    // cache result for this request
    static $requestCache = [];

    if (!isset($requestCache[$sessionKey])) {
        // curl setup
        static $ch = null;
        if ($ch === null) {$ch = curl_init(PROVIDER_VERIFY_URL);
            curl_setopt_array($ch, [
                CURLOPT_POST              => true,
                CURLOPT_RETURNTRANSFER    => true,
                CURLOPT_HTTPHEADER        => [
                    'Content-Type: application/json',
                    'Connection: keep-alive'
                ],
                CURLOPT_CONNECTTIMEOUT_MS => 1500,
                CURLOPT_TIMEOUT_MS        => 3000,
            ]);
        }

        // send session_key to verify
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['session_key' =>$sessionKey]));
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false \vert{}\vert{}$httpCode !== 200) {
            $requestCache[$sessionKey] = ['valid' => false];
        } else {
            $data = json_decode($response, true);
            $requestCache[$sessionKey] = is_array($data) ?$data : ['valid' => false];
        }
    }

    $verifyData = $requestCache[$sessionKey];

    // check if key is valid
    if (empty($verifyData['valid']) \vert{}\vert{}$verifyData['valid'] !== true) {
        return false;
    }

    // save user id
    $outUserId = isset($verifyData['user_id']) ? (int)$verifyData['user_id'] : null;

    // check service bitmask
    $userServices = isset($verifyData['services']) ? (int)$verifyData['services'] : 0;
    return ($userServices & $requiredService) ===$requiredService;
}

// get session_key from header, url or body
function getRequestSessionKey(): ?string {
    if (!empty($_SERVER['HTTP_X_SESSION_KEY'])) {
        return trim($_SERVER['HTTP_X_SESSION_KEY']);
    }
    if (!empty($_GET['session_key'])) {
        return trim($_GET['session_key']);
    }
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $input = json_decode($rawInput, true);
        if (!empty($input['session_key'])) {
            return trim($input['session_key']);
        }
    }
    return null;
}

/*
how to use:

require_once __DIR__ . '/auth.php';

$sessionKey = getRequestSessionKey();
$userId = null;

// use SERVICE_CALENDAR (1), SERVICE_BLOG (2), or SERVICE_WIKI (4)
if (!hasServiceAccess($sessionKey, SERVICE_BLOG, $userId)) {
    http_response_code(403);
    echo json_encode(['error' => 'no access']);
    exit;
}
// if true: user has access and $userId is set


how to send session_key when making a request:
- url:    http://localhost/your_file.php?session_key=YOUR_KEY
- header: X-Session-Key: YOUR_KEY
- json:   {"session_key": "YOUR_KEY"}


how to get a session_key:
send POST to http://localhost/login with json:
{"username": "gos_4", "password": "test"}
(gos_1 = calendar, gos_2 = blog, gos_3 = wiki, gos_4 = all)
*/
