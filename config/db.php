<?php
define('COMPANY_ID', 4);
// Go up one directory from __DIR__ to find the .env file in the parent folder
$envPath = dirname(__DIR__) . '/.env';
$env = parse_ini_file($envPath);

if ($env === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not read .env file at: ' . $envPath]);
    exit;
}

$host = $env['DB_HOST'] ?? 'localhost';
$user = $env['DB_USER'] ?? '';
$pass = $env['DB_PASS'] ?? '';
$db   = $env['DB_NAME'] ?? '';

$conn = new mysqli($host, $user, $pass, $db);


if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Databasanslutning misslyckades: ' . $conn->connect_error]);
    exit;
}

$conn->set_charset("utf8mb4");