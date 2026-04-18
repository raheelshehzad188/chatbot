<?php
/**
 * AJAX endpoint for public test-chat UI (no WhatsApp queue, no lead insert).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

$token = isset($input['token']) ? trim((string) $input['token']) : '';
$message = isset($input['message']) ? trim((string) $input['message']) : '';

if ($token === '' || $message === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'token and message required']);
    exit;
}

$conn = getDBConnection();
$settings = load_sub_admin_settings_by_test_chat_token($conn, $token);
$conn->close();

if (!$settings) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Invalid or expired test link']);
    exit;
}

$phone = test_chat_sandbox_phone();
$reply = getReplyMessageWithFaqLayer($message, $phone, $settings, ['skip_pending_save' => true]);

echo json_encode([
    'ok' => true,
    'reply' => $reply,
], JSON_UNESCAPED_UNICODE);
