<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$conn = getDBConnection();
$admin_id = $_SESSION['admin_id'];
$message = '';

// Backward-compatible: create ignore_numbers column if migration not yet run
if ($col = $conn->query("SHOW COLUMNS FROM sub_admin_settings LIKE 'ignore_numbers'")) {
    if ($col->num_rows === 0) {
        @$conn->query("ALTER TABLE sub_admin_settings ADD COLUMN ignore_numbers TEXT DEFAULT '' AFTER system_instruction");
    }
}

// Get current settings
$settings_query = "SELECT * FROM sub_admin_settings WHERE admin_id = ?";
$stmt = $conn->prepare($settings_query);
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$settings = $stmt->get_result()->fetch_assoc();
$stmt->close();

// If settings don't exist, create them
if (!$settings) {
    $webhook_token = bin2hex(random_bytes(32));
    $insert_stmt = $conn->prepare("INSERT INTO sub_admin_settings (admin_id, webhook_token) VALUES (?, ?)");
    $insert_stmt->bind_param("is", $admin_id, $webhook_token);
    $insert_stmt->execute();
    $insert_stmt->close();
    
    // Get settings again
    $stmt = $conn->prepare($settings_query);
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $settings = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Handle update (store owners: WhatsApp + prompts only — AI keys are platform-wide)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_settings'])) {
    $whatsapp_token = $_POST['whatsapp_api_token'] ?? '';
    $starting_message = $_POST['starting_message'] ?? '';
    $system_instruction = $_POST['system_instruction'] ?? '';
    $ignore_numbers = $_POST['ignore_numbers'] ?? '';
    $message_interval = isset($_POST['message_interval']) ? (int)$_POST['message_interval'] : 60;
    
    // Validate message_interval (minimum 10 seconds, maximum 600 seconds)
    if ($message_interval < 10) $message_interval = 10;
    if ($message_interval > 600) $message_interval = 600;
    
    $update_stmt = $conn->prepare("UPDATE sub_admin_settings SET whatsapp_api_token = ?, starting_message = ?, system_instruction = ?, ignore_numbers = ?, message_interval = ? WHERE admin_id = ?");
    $update_stmt->bind_param("ssssii", $whatsapp_token, $starting_message, $system_instruction, $ignore_numbers, $message_interval, $admin_id);
    
    if ($update_stmt->execute()) {
        $message = "Settings updated successfully!";
        // Refresh settings
        $stmt = $conn->prepare($settings_query);
        $stmt->bind_param("i", $admin_id);
        $stmt->execute();
        $settings = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } else {
        $message = "Error updating settings";
    }
    $update_stmt->close();
}

$conn->close();

// Get webhook URL
$webhook_url = base_url('webhook.php?token=' . ($settings['webhook_token'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings</title>
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
        .nav {
            background: white;
            padding: 15px 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .nav a {
            margin-right: 20px;
            text-decoration: none;
            color: #667eea;
            font-weight: bold;
        }
        .container {
            max-width: 800px;
            margin: 20px auto;
            padding: 0 20px;
        }
        .form-container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #333;
        }
        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            font-family: Arial, sans-serif;
        }
        .form-group textarea {
            min-height: 150px;
            resize: vertical;
        }
        .api-key-group {
            display: none;
        }
        .api-key-group.show {
            display: block;
        }
        .form-group .help-text {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        .webhook-info {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .webhook-info code {
            background: #e9e9e9;
            padding: 5px 10px;
            border-radius: 3px;
            word-break: break-all;
            display: block;
            margin-top: 5px;
        }
        button {
            padding: 12px 30px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
        }
        button:hover {
            background: #5568d3;
        }
        .message {
            padding: 12px;
            margin-bottom: 20px;
            border-radius: 5px;
            background: #d4edda;
            color: #155724;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Settings</h1>
        <a href="dashboard.php">Back to Dashboard</a>
    </div>
    <div class="nav">
        <a href="dashboard.php">Dashboard</a>
        <?php if ($_SESSION['admin_role'] == 'super_admin'): ?>
            <a href="sub_admins.php">Sub Admins</a>
            <a href="platform_ai.php">Platform AI</a>
        <?php endif; ?>
        <a href="leads.php">Leads</a>
        <a href="contacts.php">Contacts</a>
        <a href="chatgpt_history.php">ChatGPT History</a>
        <a href="gemini_history.php">Gemini History</a>
        <a href="whatsapp_history.php">WhatsApp History</a>
        <a href="settings.php">Settings</a>
    </div>
    <div class="container">
        <?php if ($message): ?>
            <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <div class="form-container">
            <h2 style="margin-bottom: 20px;">Store configuration</h2>

            <p style="margin-bottom: 16px; color: #555; font-size: 14px; line-height: 1.5;">
                Gemini / OpenAI keys and the <strong>which AI to use</strong> choice are set once for the whole platform by the super admin in
                <?php if ($_SESSION['admin_role'] == 'super_admin'): ?>
                    <a href="platform_ai.php"><strong>Platform AI</strong></a>.
                <?php else: ?>
                    <strong>Platform AI</strong> (super admin only).
                <?php endif; ?>
                Here you only set <strong>WhatsApp</strong> and your store prompts.
            </p>
            
            <div class="webhook-info">
                <strong>Your Webhook URL:</strong>
                <code><?php echo htmlspecialchars($webhook_url); ?></code>
                <div class="help-text">Use this URL in your WhatsApp webhook configuration</div>
            </div>
            
            <form method="POST" id="settingsForm">
                <div class="form-group">
                    <label>Starting message (Gemini / prepended context)</label>
                    <textarea name="starting_message" placeholder="Optional context prepended when the platform uses Gemini..."><?php echo htmlspecialchars($settings['starting_message'] ?? ''); ?></textarea>
                    <div class="help-text">Used when the platform AI provider is Gemini (or as extra context). Does not replace the platform API key.</div>
                </div>

                <div class="form-group">
                    <label>System instruction (ChatGPT)</label>
                    <textarea name="system_instruction" placeholder="Role and behavior when the platform uses OpenAI..."><?php echo htmlspecialchars($settings['system_instruction'] ?? ''); ?></textarea>
                    <div class="help-text">Used when the platform AI provider is ChatGPT. Per-store personality; keys stay on Platform AI.</div>
                </div>

                <div class="form-group">
                    <label>Ignore Numbers List (last 6 digits match)</label>
                    <textarea name="ignore_numbers" placeholder="e.g. 923001112233&#10;03001234567&#10;111222"><?php echo htmlspecialchars($settings['ignore_numbers'] ?? ''); ?></textarea>
                    <div class="help-text">Comma separated format recommended (example: 923001112233,03001234567,111222). If incoming WhatsApp number last 6 digits match this list, bot will not send any reply.</div>
                </div>
                
                <div class="form-group">
                    <label>WhatsApp API Token</label>
                    <input type="text" name="whatsapp_api_token" value="<?php echo htmlspecialchars($settings['whatsapp_api_token'] ?? ''); ?>" placeholder="Enter your WhatsApp API Token">
                    <div class="help-text">Your WhatsApp API Bearer token for sending messages (per store).</div>
                </div>
                
                <div class="form-group">
                    <label>Message Interval (seconds)</label>
                    <input type="number" name="message_interval" value="<?php echo htmlspecialchars($settings['message_interval'] ?? 60); ?>" min="10" max="600" required>
                    <div class="help-text">Time interval between messages in seconds (minimum 10, maximum 600). Messages will be queued and sent one by one with this interval.</div>
                </div>
                
                <button type="submit" name="update_settings">Save Settings</button>
            </form>
        </div>
    </div>
</body>
</html>

