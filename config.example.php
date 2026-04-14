<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Detect environment (localhost or production)
$isLocalhost = (
    $_SERVER['HTTP_HOST'] == 'localhost' ||
    $_SERVER['HTTP_HOST'] == '127.0.0.1' ||
    strpos($_SERVER['HTTP_HOST'], 'localhost') !== false ||
    strpos($_SERVER['HTTP_HOST'], '.local') !== false
);

// Database configuration - Different for localhost and production
if ($isLocalhost) {
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'chatbot');
    define('BASE_PATH', '/chatbot/');
} else {
    define('DB_HOST', 'localhost');
    define('DB_USER', 'your_db_user');
    define('DB_PASS', 'your_db_password');
    define('DB_NAME', 'your_db_name');
    define('BASE_PATH', '/');
}

// API Keys Configuration — set your keys here
define('GEMINI_API_KEY', '');
define('WHATSAPP_API_TOKEN', '');
if (!defined('GEMINI_MODEL')) {
    define('GEMINI_MODEL', 'gemini-3-flash-preview');
}

function base_url($uri = '') {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ||
                 $_SERVER['SERVER_PORT'] == 443) ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $basePath = BASE_PATH;
    $uri = ltrim($uri, '/');
    $url = $protocol . '://' . $host . $basePath;
    if (!empty($uri)) {
        $url .= $uri;
    }
    return $url;
}

function getDBConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    return $conn;
}

function getPDOConnection() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function getGeminiApiUrl($apiKey, $model = null) {
    $finalModel = $model ?: (defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-3-flash-preview');
    return 'https://generativelanguage.googleapis.com/v1beta/models/'
        . $finalModel
        . ':generateContent?key='
        . rawurlencode($apiKey);
}

if (!defined('GEMINI_USE_FUNCTION_CALLING')) {
    define('GEMINI_USE_FUNCTION_CALLING', false);
}
