<?php

define('PROVIDER_BASE_URL', 'https://theprovider.ntigskovde.se');
define('PROVIDER_VERIFY_URL', PROVIDER_BASE_URL . '/verify');

define('SERVICE_CALENDAR', 1);
define('SERVICE_BLOG', 2);
define('SERVICE_WIKI', 4);

function getRequestSessionKey(): ?string {
    if (!empty($_GET['session_key'])) {
        return trim($_GET['session_key']);
    }
    if (!empty($_SERVER['HTTP_X_SESSION_KEY'])) {
        return trim($_SERVER['HTTP_X_SESSION_KEY']);
    }
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
            CURLOPT_POST           => true,
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

function hasServiceAccess(?string $sessionKey, int $requiredServiceBit, ?int &$userId = null): bool {
    $data = verifySessionWithProvider($sessionKey);
    if (!$data) {
        return false;
    }

    $userId = isset($data['user_id']) ? (int)$data['user_id'] : null;
    $services = isset($data['services']) ? (int)$data['services'] : 0;

    return (($services & $requiredServiceBit) === $requiredServiceBit);
}