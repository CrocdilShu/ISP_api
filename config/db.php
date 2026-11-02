<?php
$activeCompanyId = null;
$activeUsername  = null;
$configFile      = __DIR__ . '/current_company.json';

// 1. Check if the config file exists
if (!file_exists($configFile)) {
    throw new RuntimeException("Configuration file not found: {$configFile}");
}

// 2. Read and decode the JSON file
$configData = json_decode(file_get_contents($configFile), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    throw new RuntimeException("Invalid JSON in configuration file: " . json_last_error_msg());
}

// 3. Handle missing username error instead of falling back
if (empty($configData['username'])) {
    throw new RuntimeException("Required configuration key 'username' is missing or empty.");
}

$activeUsername = (string)$configData['username'];

if (!empty($configData['company_id'])) {
    $activeCompanyId = (int)$configData['company_id'];
}

define('COMPANY_USERNAME', $activeUsername);
define('COMPANY_ID', $activeCompanyId);   // can be null until the first provider login

// Go up one directory from __DIR__ to find the .env file in the parent folder
$envPath = dirname(__DIR__) . '/.env';
$env = parse_ini_file($envPath);

if ($env === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not read .env file at: ' . $envPath]);
    exit;
}

// getenv() to get the values
foreach ($env as $key => $value) {
    putenv("$key=$value");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
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