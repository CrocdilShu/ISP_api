<?php
// Part of calender.php. Not a page of its own: it only defines functions.
if (!defined('USER_API')) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/providerauth.php';
require_once __DIR__ . '/../helpers.php';

// ---------------------------------------------------------------
// POST rootLogin
// body: {
//       "username": "preseeded_admin",
//       "password": "plain_password"
// }
// Authenticates against pre-seeded local root users, requests a session 
// token and service bitmask from the external provider, and saves them locally.
// ---------------------------------------------------------------
function rootLogin(mysqli $conn): void {
    requireMethod('POST');
    $body = getBody();

    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');

    if ($username === '' || $password === '') {
        respond(400, ['error' => 'Username and password are required']);
    }

    // Check if the root_user exists
    $rootUser = fetchOne(
        $conn,
        'SELECT ID, username, password_hash FROM root_user WHERE username = ?',
        [$username]
    );

    // If user doesn't exist
    if (!$rootUser) {
        respond(404, ['error' => 'Root user not found']);
    }

    // Verify the password input with the predefined password_hash
    if (!password_verify($password, $rootUser['password_hash'])) {
        respond(401, ['error' => 'Invalid password']);
    }

    $userId = (int)$rootUser['ID'];

    // Send information back to the provider for auth
    $providerLoginUrl = 'https://theprovider.ntigskovde.se/login'; 
    
    $ch = curl_init($providerLoginUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'username' => $username,
            'password' => $password
        ]),
        CURLOPT_TIMEOUT => 5,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        respond(401, ['error' => 'Failed to authenticate with the provider']);
    }

    $providerData = json_decode($response, true);

    if (!is_array($providerData) || empty($providerData['session_key'])) {
        respond(401, ['error' => 'Provider did not return a valid session key']);
    }

    $sessionKey = $providerData['session_key'];
    $services   = (int)($providerData['services'] ?? 0);
    $expiresAt  = $providerData['expires_at'] ?? date('Y-m-d H:i:s', strtotime('+24 hours'));

    try {
        // Updates the root_users token, expiration date, and services bitmask
        run(
            $conn,
            'UPDATE root_user SET token = ?, expire_date = ? WHERE ID = ?',
            [$sessionKey, $expiresAt, $userId]
        );

        // Returns response 200
        respond(200, [
            'success' => true,
            'user_id' => $userId,
            'session_key' => $sessionKey,
            'services' => $services,
            'expires_at' => $expiresAt
        ]);

    } catch (mysqli_sql_exception $e) {
        respond(500, ['error' => 'Could not save session data locally']);
    }
}