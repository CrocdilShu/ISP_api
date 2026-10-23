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
    
    // Check standard Authorization Bearer header
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = trim($_SERVER['HTTP_AUTHORIZATION']);
        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return trim($matches[1]);
        }
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
    if (!$sessionKey) {
        return false;
    }

    // 1. Check if the token exists locally and is still within its 30-minute window
    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
        $localCheck = fetchOne(
            $GLOBALS['conn'],
            'SELECT 1 FROM root_user WHERE token = ? AND expire_date >= NOW()',
            [$sessionKey]
        );
        if (!$localCheck) {
            return false; // Local session expired or doesn't exist
        }
    }

    // 2. Verify with the external provider
    $data = verifySessionWithProvider($sessionKey);
    if (!$data) {
        return false;
    }

    $userId = isset($data['user_id']) ? (int)$data['user_id'] : null;
    $services = isset($data['services']) ? (int)$data['services'] : 0;

    // Check if the service bit matches
    if (($services & $requiredServiceBit) !== $requiredServiceBit) {
        return false;
    }

    // 3. Extend the session expiration in root_user by 30 min (rolling window)
    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
        touchProviderSession($GLOBALS['conn'], $sessionKey);
    }

    return true;
}
function getCompanySessionKey(mysqli $conn): ?string {
    $companyId = COMPANY_ID;
    $stmt = $conn->prepare(
        "SELECT token FROM root_user WHERE ID = ? AND expire_date > NOW() LIMIT 1"
    );
    $stmt->bind_param("i", $companyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row['token'] ?? null;
}