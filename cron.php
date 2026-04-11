<?php
/**
 * Cron Job to Process Message Queue
 * 
 * This file should be called via cron job every minute:
 * * * * * * curl -s https://yourdomain.com/chatbot/cron.php?token=YOUR_SECRET_TOKEN > /dev/null 2>&1
 * 
 * Or using wget:
 * * * * * * wget -q -O - https://yourdomain.com/chatbot/cron.php?token=YOUR_SECRET_TOKEN > /dev/null 2>&1
 * 
 * Or directly via PHP:
 * * * * * * /usr/bin/php /path/to/chatbot/cron.php
 */

require_once 'config.php';
require_once 'functions.php';

// Security: Only allow access via CLI or with a secret token
$secret_token = 'admin123'; // CHANGE THIS to a secure random string
$provided_token = $_GET['token'] ?? '';

// Allow CLI access or token-based access
$isCLI = php_sapi_name() === 'cli';
$isValidToken = !empty($provided_token) && $provided_token === $secret_token;

if (!$isCLI && !$isValidToken) {
    http_response_code(403);
    die('Access denied. Use CLI or provide valid token via ?token=YOUR_TOKEN');
}

// Process message queue
$processed = processMessageQueue(10); // Process up to 10 messages per run

// Log result
if (php_sapi_name() === 'cli') {
    echo "Processed $processed messages from queue.\n";
} else {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success',
        'processed' => $processed,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>

