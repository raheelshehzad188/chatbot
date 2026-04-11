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

// Handle update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_settings'])) {
    $api_provider = $_POST['api_provider'] ?? 'gemini';
    $gemini_key = $_POST['gemini_api_key'] ?? '';
    $chatgpt_key = $_POST['chatgpt_api_key'] ?? '';
    $whatsapp_token = $_POST['whatsapp_api_token'] ?? '';
    $starting_message = $_POST['starting_message'] ?? '';
    $system_instruction = $_POST['system_instruction'] ?? '';
    $message_interval = isset($_POST['message_interval']) ? (int)$_POST['message_interval'] : 60;
    
    // Validate message_interval (minimum 10 seconds, maximum 600 seconds)
    if ($message_interval < 10) $message_interval = 10;
    if ($message_interval > 600) $message_interval = 600;
    
    $update_stmt = $conn->prepare("UPDATE sub_admin_settings SET api_provider = ?, gemini_api_key = ?, chatgpt_api_key = ?, whatsapp_api_token = ?, starting_message = ?, system_instruction = ?, message_interval = ? WHERE admin_id = ?");
    $update_stmt->bind_param("ssssssii", $api_provider, $gemini_key, $chatgpt_key, $whatsapp_token, $starting_message, $system_instruction, $message_interval, $admin_id);
    
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
        <?php endif; ?>
        <a href="leads.php">Leads</a>
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
            <h2 style="margin-bottom: 20px;">API Configuration</h2>
            
            <div class="webhook-info">
                <strong>Your Webhook URL:</strong>
                <code><?php echo htmlspecialchars($webhook_url); ?></code>
                <div class="help-text">Use this URL in your WhatsApp webhook configuration</div>
            </div>
            
            <form method="POST" id="settingsForm">
                <div class="form-group">
                    <label>API Provider</label>
                    <select name="api_provider" id="api_provider" required>
                        <option value="gemini" <?php echo (($settings['api_provider'] ?? 'gemini') == 'gemini') ? 'selected' : ''; ?>>Gemini</option>
                        <option value="chatgpt" <?php echo (($settings['api_provider'] ?? 'gemini') == 'chatgpt') ? 'selected' : ''; ?>>ChatGPT</option>
                    </select>
                    <div class="help-text">Select which AI API to use for generating responses</div>
                </div>
                
                <div class="form-group api-key-group <?php echo (($settings['api_provider'] ?? 'gemini') == 'gemini') ? 'show' : ''; ?>" id="gemini_key_group">
                    <label>Gemini API Key</label>
                    <input type="text" name="gemini_api_key" value="<?php echo htmlspecialchars($settings['gemini_api_key'] ?? ''); ?>" placeholder="Enter your Gemini API Key">
                    <div class="help-text">Your Google Gemini API key for AI responses</div>
                </div>
                
                <div class="form-group api-key-group <?php echo (($settings['api_provider'] ?? 'gemini') == 'chatgpt') ? 'show' : ''; ?>" id="chatgpt_key_group">
                    <label>ChatGPT API Key</label>
                    <input type="text" name="chatgpt_api_key" value="<?php echo htmlspecialchars($settings['chatgpt_api_key'] ?? ''); ?>" placeholder="Enter your ChatGPT API Key (sk-...)">
                    <div class="help-text">Your OpenAI API key for ChatGPT responses</div>
                </div>
                
                <div class="form-group api-key-group <?php echo (($settings['api_provider'] ?? 'gemini') == 'chatgpt') ? 'show' : ''; ?>" id="system_instruction_group">
                    <label>System Instruction (for ChatGPT)</label>
                    <textarea name="system_instruction" placeholder="Enter system instruction for ChatGPT..."><?php echo htmlspecialchars($settings['system_instruction'] ?? ''); ?></textarea>
                    <div class="help-text">This instruction will be sent as the system message to ChatGPT. It defines the AI's role and behavior.</div>
                </div>
                
                <div class="form-group api-key-group <?php echo (($settings['api_provider'] ?? 'gemini') == 'gemini') ? 'show' : ''; ?>" id="starting_message_group">
                    <label>Starting Message (for Gemini)</label>
                    <textarea name="starting_message" placeholder="Enter the starting message that will be added to every AI prompt..."><?php echo htmlspecialchars($settings['starting_message'] ?? ''); ?></textarea>
                    <div class="help-text">This message will be prepended to every user message before sending to Gemini AI</div>
                </div>
                
                <div class="form-group">
                    <label>WhatsApp API Token</label>
                    <input type="text" name="whatsapp_api_token" value="<?php echo htmlspecialchars($settings['whatsapp_api_token'] ?? ''); ?>" placeholder="Enter your WhatsApp API Token">
                    <div class="help-text">Your WhatsApp API Bearer token for sending messages</div>
                </div>
                
                <div class="form-group">
                    <label>Message Interval (seconds)</label>
                    <input type="number" name="message_interval" value="<?php echo htmlspecialchars($settings['message_interval'] ?? 60); ?>" min="10" max="600" required>
                    <div class="help-text">Time interval between messages in seconds (minimum 10, maximum 600). Messages will be queued and sent one by one with this interval.</div>
                </div>
                
                <button type="submit" name="update_settings">Save Settings</button>
            </form>
            
            <script>
                document.getElementById('api_provider').addEventListener('change', function() {
                    const provider = this.value;
                    const geminiGroup = document.getElementById('gemini_key_group');
                    const chatgptGroup = document.getElementById('chatgpt_key_group');
                    const systemInstructionGroup = document.getElementById('system_instruction_group');
                    const startingMessageGroup = document.getElementById('starting_message_group');
                    
                    if (provider === 'gemini') {
                        geminiGroup.classList.add('show');
                        startingMessageGroup.classList.add('show');
                        chatgptGroup.classList.remove('show');
                        systemInstructionGroup.classList.remove('show');
                    } else {
                        chatgptGroup.classList.add('show');
                        systemInstructionGroup.classList.add('show');
                        geminiGroup.classList.remove('show');
                        startingMessageGroup.classList.remove('show');
                    }
                });
            </script>
        </div>
    </div>
</body>
</html>

