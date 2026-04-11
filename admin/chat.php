<?php
session_start();
require_once '../config.php';
require_once '../functions.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$conn = getDBConnection();
$admin_id = $_SESSION['admin_id'];
$admin_role = $_SESSION['admin_role'];
$phone = $_GET['phone'] ?? '';

if (empty($phone)) {
    header('Location: leads.php');
    exit;
}

// Verify lead belongs to this admin
if ($admin_role == 'sub_admin') {
    $check_query = "SELECT id FROM leads WHERE phone = ? AND sub_admin_id = ?";
    $stmt = $conn->prepare($check_query);
    $stmt->bind_param("si", $phone, $admin_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows == 0) {
        header('Location: leads.php');
        exit;
    }
    $stmt->close();
}

// Get lead info
$lead_query = "SELECT * FROM leads WHERE phone = ?";
$stmt = $conn->prepare($lead_query);
$stmt->bind_param("s", $phone);
$stmt->execute();
$lead = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Get chat history (filtered by sub_admin_id)
$chat_query = "SELECT * FROM chat_history WHERE phone = ? AND sub_admin_id = ? ORDER BY created_at ASC";
$stmt = $conn->prepare($chat_query);
$stmt->bind_param("si", $phone, $lead['sub_admin_id']);
$stmt->execute();
$chat_history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Handle send message
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_message'])) {
    $message = $_POST['message'] ?? '';
    if (!empty($message)) {
        // Get sub-admin settings
        $settings_query = "SELECT * FROM sub_admin_settings WHERE admin_id = ?";
        $stmt = $conn->prepare($settings_query);
        $stmt->bind_param("i", $lead['sub_admin_id']);
        $stmt->execute();
        $settings = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($settings && !empty($settings['whatsapp_api_token'])) {
            // Send message using API with sub-admin's token
            sendMessage($phone, $message, $settings['whatsapp_api_token'], $lead['sub_admin_id'], $lead['name']);
            
            // Save to chat history
            $insert_query = "INSERT INTO chat_history (sub_admin_id, phone, name, message, direction, created_at) VALUES (?, ?, ?, ?, 'outgoing', NOW())";
            $stmt = $conn->prepare($insert_query);
            $stmt->bind_param("isss", $lead['sub_admin_id'], $phone, $lead['name'], $message);
            $stmt->execute();
            $stmt->close();
            
            // Also log to message_logs
            $log_query = "INSERT INTO message_logs (sub_admin_id, phone, name, message, type, received_at) VALUES (?, ?, ?, ?, 'sent', NOW())";
            $log_stmt = $conn->prepare($log_query);
            $log_stmt->bind_param("isss", $lead['sub_admin_id'], $phone, $lead['name'], $message);
            $log_stmt->execute();
            $log_stmt->close();
            
            // Reload page
            header('Location: chat.php?phone=' . urlencode($phone));
            exit;
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat - <?php echo htmlspecialchars($phone); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            background: #f5f5f5;
        }
        .header {
            background: #667eea;
            color: white;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 { font-size: 24px; }
        .header a {
            color: white;
            text-decoration: none;
            padding: 8px 15px;
            background: rgba(255,255,255,0.2);
            border-radius: 5px;
        }
        .container {
            max-width: 800px;
            margin: 20px auto;
            padding: 0 20px;
        }
        .chat-container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            height: 600px;
            display: flex;
            flex-direction: column;
        }
        .chat-header {
            padding: 15px;
            border-bottom: 1px solid #eee;
            background: #f9f9f9;
        }
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
        }
        .message {
            margin-bottom: 15px;
            display: flex;
        }
        .message.incoming {
            justify-content: flex-start;
        }
        .message.outgoing {
            justify-content: flex-end;
        }
        .message-content {
            max-width: 70%;
            padding: 10px 15px;
            border-radius: 10px;
        }
        .message.incoming .message-content {
            background: #e5e5e5;
        }
        .message.outgoing .message-content {
            background: #667eea;
            color: white;
        }
        .message-time {
            font-size: 11px;
            color: #999;
            margin-top: 5px;
        }
        .chat-input {
            padding: 15px;
            border-top: 1px solid #eee;
            display: flex;
            gap: 10px;
        }
        .chat-input input {
            flex: 1;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        .chat-input button {
            padding: 10px 20px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }
        .chat-input button:hover {
            background: #5568d3;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Chat with <?php echo htmlspecialchars($lead['name'] ?: $phone); ?></h1>
        <a href="leads.php">Back to Leads</a>
    </div>
    <div class="container">
        <div class="chat-container">
            <div class="chat-header">
                <strong>Phone:</strong> <?php echo htmlspecialchars($phone); ?><br>
                <strong>Name:</strong> <?php echo htmlspecialchars($lead['name'] ?: 'N/A'); ?>
            </div>
            <div class="chat-messages" id="chatMessages">
                <?php if (empty($chat_history)): ?>
                    <div style="text-align: center; color: #999; padding: 20px;">No messages yet</div>
                <?php else: ?>
                    <?php foreach ($chat_history as $msg): ?>
                        <div class="message <?php echo $msg['direction']; ?>">
                            <div>
                                <div class="message-content">
                                    <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                                </div>
                                <div class="message-time">
                                    <?php echo date('Y-m-d H:i:s', strtotime($msg['created_at'])); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <form method="POST" class="chat-input">
                <input type="text" name="message" placeholder="Type your message..." required>
                <button type="submit" name="send_message">Send</button>
            </form>
        </div>
    </div>
    <script>
        // Auto scroll to bottom
        const chatMessages = document.getElementById('chatMessages');
        chatMessages.scrollTop = chatMessages.scrollHeight;
        
        // Auto refresh every 5 seconds
        setInterval(function() {
            location.reload();
        }, 5000);
    </script>
</body>
</html>

