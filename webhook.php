<?php
require_once 'config.php';
require_once 'functions.php';

// Get webhook token from URL parameter
$webhook_token = $_GET['token'] ?? '';

if (empty($webhook_token)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Webhook token required']);
    exit;
}

// Get sub-admin settings by token
$conn = getDBConnection();
$stmt = $conn->prepare("SELECT s.*, a.id as admin_id FROM sub_admin_settings s 
                       INNER JOIN admins a ON s.admin_id = a.id 
                       WHERE s.webhook_token = ? AND a.status = 'active'");
$stmt->bind_param("s", $webhook_token);
$stmt->execute();
$result_settings = $stmt->get_result();
$settings = $result_settings->fetch_assoc();
$stmt->close();

if ($settings) {
    faq_ensure_schema($conn);
    $rid = (int) $settings['admin_id'];
    $ref = $conn->prepare("SELECT faq_strict_unknown, unknown_question_reply FROM sub_admin_settings WHERE admin_id = ? LIMIT 1");
    $ref->bind_param("i", $rid);
    $ref->execute();
    $extra = $ref->get_result()->fetch_assoc();
    $ref->close();
    if (is_array($extra)) {
        $settings['faq_strict_unknown'] = $extra['faq_strict_unknown'] ?? 0;
        $settings['unknown_question_reply'] = $extra['unknown_question_reply'] ?? '';
    }
}

if (!$settings) {
    $conn->close();
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid webhook token']);
    exit;
}

$sub_admin_id = $settings['admin_id'];

// Get POST data
$postData = file_get_contents('php://input');
$data = json_decode($postData, true);

// If JSON decode fails, try $_POST
if ($data === null) {
    $data = $_POST;
}

// Process the data through your function
$result = processIncomingData($data);


if ($result && isset($result['text']) && isset($result['number'])) {
    $text = $result['text'];
    $phone = $result['number'];
    $name = isset($result['name']) ? $result['name'] : '';
    
    // Clear reply.log file for new webhook request (with error handling)
    $logFile = __DIR__ . '/reply.log';
    @file_put_contents($logFile, '', LOCK_EX);
    
    // Validate phone number - only process if starts with 92 or +92
    $phoneClean = ltrim($phone, '+'); // Remove + if present for checking
    if (substr($phoneClean, 0, 2) !== '92') {
        // Invalid phone number format - return without processing
        http_response_code(200);
        echo json_encode(['status' => 'ignored', 'message' => 'Phone number does not start with 92 or +92']);
        exit;
    }

    // Ignore-list check (per sub-admin): match by last 6 digits
    // We check multiple possible sender numbers because some payloads have both
    // cleanedSenderPn and remoteJid variants.
    $ignoreListRaw = $settings['ignore_numbers'] ?? '';
    $ignoreCandidates = [$phone];
    if (isset($data['data']['messages']['key']['cleanedSenderPn'])) {
        $ignoreCandidates[] = $data['data']['messages']['key']['cleanedSenderPn'];
    }
    if (isset($data['data']['messages']['remoteJid'])) {
        $ignoreCandidates[] = explode('@', $data['data']['messages']['remoteJid'])[0];
    }
    if (isset($data['data']['messages']['key']['remoteJid'])) {
        $ignoreCandidates[] = explode('@', $data['data']['messages']['key']['remoteJid'])[0];
    }
    if (isset($data['messages']['key']['cleanedSenderPn'])) {
        $ignoreCandidates[] = $data['messages']['key']['cleanedSenderPn'];
    }
    if (isset($data['messages']['remoteJid'])) {
        $ignoreCandidates[] = explode('@', $data['messages']['remoteJid'])[0];
    }
    if (isset($data['messages']['key']['remoteJid'])) {
        $ignoreCandidates[] = explode('@', $data['messages']['key']['remoteJid'])[0];
    }
    $ignoreCandidates = array_values(array_unique(array_filter($ignoreCandidates)));

    $matchedIgnorePhone = '';
    foreach ($ignoreCandidates as $candidatePhone) {
        if (isIgnoredPhone($candidatePhone, $ignoreListRaw)) {
            $matchedIgnorePhone = $candidatePhone;
            break;
        }
    }

    if ($matchedIgnorePhone !== '') {
        $conn->close();
        http_response_code(200);
        echo json_encode([
            'status' => 'ignored',
            'message' => 'Number is in ignore list. Queue and reply skipped.',
            'reason' => 'ignore_list',
            'matched_phone' => $matchedIgnorePhone,
            'matched_last6' => getPhoneLastSix($matchedIgnorePhone),
            'queue_added' => false
        ]);
        exit;
    }
    
    // Check if incoming message contains "stop" command (case insensitive)
    $incomingTextLower = strtolower(trim($text));
    if ($incomingTextLower === 'stop' || $incomingTextLower === 'unsubscribe') {
        // User wants to stop - don't process or send any message
        $conn->close();
        http_response_code(200);
        echo json_encode(['status' => 'stopped', 'message' => 'User requested to stop']);
        exit;
    }
    
    // Check if phone number already exists in leads table for this sub-admin
    $existingLead = checkPhoneExists($phone, $sub_admin_id);
    
    $replyMessage = null;
    
    if ($existingLead) {
        // Phone exists - get reply from another function with sub-admin settings
        $replyMessage = getReplyMessageWithFaqLayer($text, $phone, $settings);
        
        // Check if reply contains "stop" (case insensitive) - if yes, don't send message
        $replyLower = strtolower($replyMessage);
        if (stripos($replyLower, 'stop') !== false) {
            // Reply contains "stop" - don't send message, just log it
            $replyMessage = 'stop';
            
            // Log that message was stopped
            logMessage([
                'sub_admin_id' => $sub_admin_id,
                'phone' => $phone,
                'name' => $name,
                'message' => 'Message stopped - AI reply contained "stop"',
                'type' => 'sent'
            ], 'sent');
        } else {
            // Add message to queue instead of sending directly
            addMessageToQueue($sub_admin_id, $phone, $name, $replyMessage, $settings);
            
            // Log queued message
            logMessage([
                'sub_admin_id' => $sub_admin_id,
                'phone' => $phone,
                'name' => $name,
                'message' => $replyMessage . ' (Queued)',
                'type' => 'sent'
            ], 'sent');
        }
    } else {
        // New phone number - insert into leads table with name and sub_admin_id
        insertLead($phone, $text, $sub_admin_id, $name);
        
        // Send welcome message to new lead (generated by AI)
        $welcomeMessage = getWelcomeMessage($name, $settings);
        
        // Check if welcome message contains "stop" - if yes, don't send
        $welcomeLower = strtolower($welcomeMessage);
        if (stripos($welcomeLower, 'stop') !== false) {
            // Welcome message contains "stop" - don't send
            $welcomeMessage = 'stop';
            
            // Log that welcome message was stopped
            logMessage([
                'sub_admin_id' => $sub_admin_id,
                'phone' => $phone,
                'name' => $name,
                'message' => 'Welcome message stopped - AI reply contained "stop"',
                'type' => 'sent'
            ], 'sent');
        } else {
            // Add welcome message to queue instead of sending directly
            addMessageToQueue($sub_admin_id, $phone, $name, $welcomeMessage, $settings);
            
            // Log queued welcome message
            logMessage([
                'sub_admin_id' => $sub_admin_id,
                'phone' => $phone,
                'name' => $name,
                'message' => $welcomeMessage . ' (Queued)',
                'type' => 'sent'
            ], 'sent');
        }
        
        // Set reply message for chat history
        $replyMessage = $welcomeMessage;
    }
    
    // Log received message (for both new and existing leads)
    logMessage([
        'sub_admin_id' => $sub_admin_id,
        'phone' => $phone,
        'name' => $name,
        'message' => $text,
        'type' => 'received'
    ], 'received');
    
    // Maintain chat history (include reply if exists)
    $result['reply'] = $replyMessage;
    $result['sub_admin_id'] = $sub_admin_id;
    saveChatHistory($phone, $text, $result);
    
    $conn->close();

    // Trigger queue processing immediately after webhook handling.
    // This helps instant replies when queue was empty and message got scheduled for now.
    $processed_now = processMessageQueue(5);
    
    // Return success response
    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'Processed',
        'queue_processed_now' => $processed_now
    ]);
} else {
    // Invalid data
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid data format']);
}
?>

