<?php

define('PROVIDER_BASE_URL', 'https://theprovider.ntigskovde.se');
define('PROVIDER_VERIFY_URL', PROVIDER_BASE_URL . '/verify');

define('SERVICE_CALENDAR', 1);
define('SERVICE_BLOG', 2);
define('SERVICE_WIKI', 4);



// Define session duration extension
const PROVIDER_SESSION_MINUTES = 30;

function getRequestSessionKey(): ?string {
    // Check query parameter
    if (!empty($_GET['session_key'])) {
        return trim($_GET['session_key']);
    }
    
    // Check custom header X-Session-Key
    if (!empty($_SERVER['HTTP_X_SESSION_KEY'])) {
        return trim($_SERVER['HTTP_X_SESSION_KEY']);
    }

    // Fallback redirect authorization
    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authHeader = trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return trim($matches[1]);
        }
    }

    // Check JSON body
    $input = json_decode(file_get_contents('php://input'), true);
    if (is_array($input) && !empty($input['session_key'])) {
        return trim($input['session_key']);
    }
    
    return null;
}

function verifySessionWithProvider(?string $sessionKey): ?array {
    if (!$sessionKey) {
        return null;
    }

    static $ch = null;
    if ($ch === null) {
        $ch = curl_init(PROVIDER_VERIFY_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST            => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Connection: keep-alive'
            ],
            CURLOPT_TIMEOUT        => 5,
        ]);
    }

    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['session_key' => $sessionKey]));
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (($response === false) or ($httpCode !== 200)) {
        return null;
    }

    $data = json_decode($response, true);
    if ((!is_array($data)) or empty($data['valid'])) {
        return null;
    }

    return $data;
}


// Updates the session expiration in the root_user table by 30 minutes from now.
function touchProviderSession(mysqli $conn, string $sessionKey): void {
    // Check if this token exists and hasn't already expired locally
    $existing = fetchOne(
        $conn, 
        'SELECT ID FROM root_user WHERE token = ? AND expire_date >= NOW()', 
        [$sessionKey]
    );

    if ($existing) {
        // Extend the expiration by 30 minutes from right now (rolling window)
        run(
            $conn,
            'UPDATE root_user 
                SET expire_date = DATE_ADD(NOW(), INTERVAL ? MINUTE) 
                WHERE token = ?',
            [PROVIDER_SESSION_MINUTES, $sessionKey]
        );
    }
}

function hasServiceAccess(?string $sessionKey, int $requiredServiceBit, ?int &$userId = null): bool {
    $conn = ($GLOBALS['conn'] instanceof mysqli) ? $GLOBALS['conn'] : null;

    // If there is not session key then run getCompanySessionKey to get a new one
    if (!$sessionKey && $conn) {
        $sessionKey = getCompanySessionKey($conn);
    }

    if (!$sessionKey) {
        return false;
    }

    // Check if the token is viable
    if ($conn) {
        $localCheck = fetchOne(
            $conn,
            'SELECT 1 FROM root_user WHERE token = ? AND expire_date >= NOW()',
            [$sessionKey]
        );
        
        // If its not viable then get new one
        if (!$localCheck) {
            $sessionKey = getCompanySessionKey($conn);
            if (!$sessionKey) {
                return false;
            }
        }
    }

    // Verify with provider
    $data = verifySessionWithProvider($sessionKey);
    if (!$data) {
        // If theprovider dosnt validate it
        if ($conn) {
            $sessionKey = getCompanySessionKey($conn);
            if (!$sessionKey) {
                return false;
            }
            $data = verifySessionWithProvider($sessionKey);
            if (!$data) {
                return false;
            }
        } else {
            return false;
        }
    }

    $userId = isset($data['user_id']) ? (int)$data['user_id'] : null;
    $services = isset($data['services']) ? (int)$data['services'] : 0;

    //  If the service match
    if (($services & $requiredServiceBit) !== $requiredServiceBit) {
        return false;
    }

    // 3. Extend the expiretime
    if ($conn) {
        touchProviderSession($conn, $sessionKey);
    }

    return true;
}

function getCompanySessionKey(mysqli $conn): ?string {
    $username = defined('COMPANY_USERNAME') ? COMPANY_USERNAME : '';
    if ($username === '') { error_log('GCSK: no COMPANY_USERNAME'); return null; }

    // 1. A valid stored token?
    $row = fetchOne(
        $conn,
        'SELECT token FROM root_user WHERE username = ? AND token IS NOT NULL AND expire_date > NOW() LIMIT 1',
        [$username]
    );
    if ($row) return $row['token'];

    // 2. No/expired token: log in at the provider
    $password = $_ENV['PROVIDER_API_PASS'] ?? $_SERVER['PROVIDER_API_PASS'] ?? '';
    if ($password === '') { error_log('GCSK: PROVIDER_API_PASS empty'); return null; }

    $ch = curl_init(PROVIDER_BASE_URL . '/login');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode(['username' => $username, 'password' => $password]),
        CURLOPT_TIMEOUT        => 5,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        error_log("GCSK: login failed user=$username http=$httpCode curl=$curlErr body=$response");
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['session_key']) || !isset($data['user_id'])) {
        error_log('GCSK: bad provider response: ' . $response);
        return null;
    }

    $expire = !empty($data['expires_at'])
        ? $data['expires_at']
        : date('Y-m-d H:i:s', strtotime('+' . PROVIDER_SESSION_MINUTES . ' minutes'));

    // 3. Store in that company's row. COALESCE keeps an ID that is already set.
    run(
        $conn,
        'UPDATE root_user SET ID = ?, token = ?, expire_date = ? WHERE username = ?',
        [(int)$data['user_id'], $data['session_key'], $expire, $username]
    );

    return $data['session_key'];
}