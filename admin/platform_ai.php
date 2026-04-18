<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['admin_id']) || $_SESSION['admin_role'] != 'super_admin') {
    header('Location: dashboard.php');
    exit;
}

/**
 * Create platform_ai_settings if migration was never run (avoids prepare() on missing table).
 */
function ensure_platform_ai_settings_table(mysqli $conn) {
    $sql = "CREATE TABLE IF NOT EXISTS platform_ai_settings (
        id INT PRIMARY KEY DEFAULT 1,
        api_provider ENUM('gemini', 'chatgpt') NOT NULL DEFAULT 'gemini',
        gemini_api_key VARCHAR(255) DEFAULT '',
        chatgpt_api_key VARCHAR(255) DEFAULT '',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!$conn->query($sql)) {
        return false;
    }
    $conn->query("INSERT INTO platform_ai_settings (id, api_provider, gemini_api_key, chatgpt_api_key)
        VALUES (1, 'gemini', '', '')
        ON DUPLICATE KEY UPDATE id = id");
    return true;
}

$conn = getDBConnection();
ensure_platform_ai_settings_table($conn);
$message = '';

$row = null;
$r = @$conn->query("SELECT api_provider, gemini_api_key, chatgpt_api_key FROM platform_ai_settings WHERE id = 1 LIMIT 1");
if ($r) {
    $row = $r->fetch_assoc();
}
if (!$row) {
    @$conn->query("INSERT INTO platform_ai_settings (id) VALUES (1) ON DUPLICATE KEY UPDATE id = id");
    $r = @$conn->query("SELECT api_provider, gemini_api_key, chatgpt_api_key FROM platform_ai_settings WHERE id = 1 LIMIT 1");
    if ($r) {
        $row = $r->fetch_assoc();
    }
}
if (!$row) {
    $row = ['api_provider' => 'gemini', 'gemini_api_key' => '', 'chatgpt_api_key' => ''];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_platform_ai'])) {
    $api_provider = $_POST['api_provider'] ?? 'gemini';
    if (!in_array($api_provider, ['gemini', 'chatgpt'], true)) {
        $api_provider = 'gemini';
    }
    $gemini_key = $_POST['gemini_api_key'] ?? '';
    $chatgpt_key = $_POST['chatgpt_api_key'] ?? '';

    $stmt = $conn->prepare("UPDATE platform_ai_settings SET api_provider = ?, gemini_api_key = ?, chatgpt_api_key = ? WHERE id = 1");
    if ($stmt === false) {
        $message = 'Database error: ' . htmlspecialchars($conn->error) . '. Ensure MySQL user can CREATE TABLE or run migration_platform_ai_settings.sql.';
    } else {
        $stmt->bind_param('sss', $api_provider, $gemini_key, $chatgpt_key);
        if ($stmt->execute()) {
            $message = 'Platform AI settings saved. All stores will use these keys and the selected provider.';
            $row['api_provider'] = $api_provider;
            $row['gemini_api_key'] = $gemini_key;
            $row['chatgpt_api_key'] = $chatgpt_key;
        } else {
            $message = 'Could not save: ' . htmlspecialchars($stmt->error);
        }
        $stmt->close();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Platform AI</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f5f5f5; }
        .header {
            background: #667eea; color: white; padding: 20px;
            display: flex; justify-content: space-between; align-items: center;
        }
        .header h1 { font-size: 24px; }
        .header a {
            color: white; text-decoration: none; padding: 8px 15px;
            background: rgba(255,255,255,0.2); border-radius: 5px;
        }
        .nav {
            background: white; padding: 15px 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .nav a { margin-right: 20px; text-decoration: none; color: #667eea; font-weight: bold; }
        .container { max-width: 800px; margin: 20px auto; padding: 0 20px; }
        .form-container {
            background: white; padding: 30px; border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: bold; color: #333; }
        .form-group input, .form-group select {
            width: 100%; padding: 12px; border: 1px solid #ddd;
            border-radius: 5px; font-size: 14px;
        }
        .help-text { font-size: 12px; color: #666; margin-top: 5px; }
        .info {
            background: #e7f1ff; border: 1px solid #b3d4fc; color: #004085;
            padding: 14px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; line-height: 1.5;
        }
        button {
            padding: 12px 30px; background: #667eea; color: white; border: none;
            border-radius: 5px; cursor: pointer; font-size: 16px;
        }
        button:hover { background: #5568d3; }
        .message {
            padding: 12px; margin-bottom: 20px; border-radius: 5px;
            background: #d4edda; color: #155724;
        }
        .api-key-group { display: none; }
        .api-key-group.show { display: block; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Platform AI (all stores)</h1>
        <a href="dashboard.php">Dashboard</a>
    </div>
    <div class="nav">
        <a href="dashboard.php">Dashboard</a>
        <a href="sub_admins.php">Sub Admins</a>
        <a href="categories.php">Categories</a>
        <a href="platform_ai.php">Platform AI</a>
        <a href="leads.php">Leads</a>
        <a href="contacts.php">Contacts</a>
        <a href="faq.php">FAQ</a>
        <a href="chatgpt_history.php">ChatGPT History</a>
        <a href="gemini_history.php">Gemini History</a>
        <a href="whatsapp_history.php">WhatsApp History</a>
        <a href="settings.php">Settings</a>
    </div>
    <div class="container">
        <?php if ($message): ?>
            <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="info">
            Store owners do <strong>not</strong> set Gemini or OpenAI keys. They only configure WhatsApp and their own prompts in <strong>Settings</strong>.
            This page applies to <strong>every</strong> sub-store (same provider + keys for all).
        </div>

        <div class="form-container">
            <h2 style="margin-bottom: 20px;">API provider &amp; keys</h2>
            <form method="POST" id="platformForm">
                <div class="form-group">
                    <label>Default AI provider (all stores)</label>
                    <select name="api_provider" id="api_provider" required>
                        <option value="gemini" <?php echo (($row['api_provider'] ?? 'gemini') === 'gemini') ? 'selected' : ''; ?>>Google Gemini</option>
                        <option value="chatgpt" <?php echo (($row['api_provider'] ?? '') === 'chatgpt') ? 'selected' : ''; ?>>OpenAI (ChatGPT)</option>
                    </select>
                    <div class="help-text">Which backend every webhook reply uses. Keys below are optional; you can fill only the one you use.</div>
                </div>

                <div class="form-group api-key-group <?php echo (($row['api_provider'] ?? 'gemini') === 'gemini') ? 'show' : ''; ?>" id="gemini_key_group">
                    <label>Gemini API key (shared)</label>
                    <input type="text" name="gemini_api_key" value="<?php echo htmlspecialchars($row['gemini_api_key'] ?? ''); ?>" placeholder="AIza...">
                    <div class="help-text">If empty, the app falls back to GEMINI_API_KEY in config.php when set.</div>
                </div>

                <div class="form-group api-key-group <?php echo (($row['api_provider'] ?? '') === 'chatgpt') ? 'show' : ''; ?>" id="chatgpt_key_group">
                    <label>OpenAI API key (shared)</label>
                    <input type="text" name="chatgpt_api_key" value="<?php echo htmlspecialchars($row['chatgpt_api_key'] ?? ''); ?>" placeholder="sk-...">
                    <div class="help-text">Required when provider is ChatGPT.</div>
                </div>

                <button type="submit" name="save_platform_ai" value="1">Save platform AI</button>
            </form>
            <script>
                document.getElementById('api_provider').addEventListener('change', function() {
                    var g = document.getElementById('gemini_key_group');
                    var c = document.getElementById('chatgpt_key_group');
                    if (this.value === 'gemini') {
                        g.classList.add('show');
                        c.classList.remove('show');
                    } else {
                        c.classList.add('show');
                        g.classList.remove('show');
                    }
                });
            </script>
        </div>
    </div>
</body>
</html>
