<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$conn = getDBConnection();
require_once __DIR__ . '/../functions.php';
faq_ensure_schema($conn);
store_config_ensure_schema($conn);
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
    $test_tok = bin2hex(random_bytes(24));
    $insert_stmt = $conn->prepare("INSERT INTO sub_admin_settings (admin_id, webhook_token, test_chat_token) VALUES (?, ?, ?)");
    $insert_stmt->bind_param("iss", $admin_id, $webhook_token, $test_tok);
    $insert_stmt->execute();
    $insert_stmt->close();
    
    // Get settings again
    $stmt = $conn->prepare($settings_query);
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $settings = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$store_types = store_type_get_definitions($conn, true);
$store_type_fields_map = [];
foreach ($store_types as $stDef) {
    $slug = trim((string) ($stDef['slug'] ?? ''));
    if ($slug === '') {
        continue;
    }
    $store_type_fields_map[$slug] = store_type_get_fields($conn, $slug, true);
}
$first_store_type_slug = '';
if (!empty($store_types) && !empty($store_types[0]['slug'])) {
    $first_store_type_slug = (string) $store_types[0]['slug'];
}
$selected_store_type = trim((string) ($settings['store_type'] ?? ''));
if ($selected_store_type === '' || !isset($store_type_fields_map[$selected_store_type])) {
    $selected_store_type = $first_store_type_slug;
}
$stored_dynamic_config = json_decode((string) ($settings['store_type_config_json'] ?? ''), true);
if (!is_array($stored_dynamic_config)) {
    $stored_dynamic_config = [];
}

// Handle update (store owners: WhatsApp + prompts only — AI keys are platform-wide)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_settings'])) {
    $selected_store_type = trim((string) ($_POST['store_type'] ?? $selected_store_type));
    if ($selected_store_type === '' || !isset($store_type_fields_map[$selected_store_type])) {
        $message = "Please select a valid store type.";
    }
    $selected_fields = $store_type_fields_map[$selected_store_type] ?? [];
    $dynamic_config = [];
    foreach ($selected_fields as $f) {
        $fieldKey = trim((string) ($f['field_key'] ?? ''));
        if ($fieldKey === '') {
            continue;
        }
        $postKey = 'cfg_' . $fieldKey;
        $value = trim((string) ($_POST[$postKey] ?? ''));
        if (!empty($f['is_required']) && $value === '') {
            $message = "Required field missing: " . ($f['field_label'] ?? $fieldKey);
            break;
        }
        $dynamic_config[$fieldKey] = $value;
    }

    $whatsapp_token = $dynamic_config['whatsapp_api_token'] ?? ($_POST['whatsapp_api_token'] ?? ($settings['whatsapp_api_token'] ?? ''));
    $starting_message = $dynamic_config['starting_message'] ?? ($_POST['starting_message'] ?? ($settings['starting_message'] ?? ''));
    $system_instruction = $dynamic_config['system_instruction'] ?? ($_POST['system_instruction'] ?? ($settings['system_instruction'] ?? ''));
    $ignore_numbers = $_POST['ignore_numbers'] ?? ($settings['ignore_numbers'] ?? '');
    $message_interval = isset($_POST['message_interval']) ? (int)$_POST['message_interval'] : (int)($settings['message_interval'] ?? 60);
    $faq_strict_unknown = !empty($_POST['faq_strict_unknown']) ? 1 : 0;
    $unknown_question_reply = $_POST['unknown_question_reply'] ?? '';
    
    // Validate message_interval (minimum 10 seconds, maximum 600 seconds)
    if ($message_interval < 10) $message_interval = 10;
    if ($message_interval > 600) $message_interval = 600;
    
    if ($message === '') {
        $dynamic_json = json_encode($dynamic_config, JSON_UNESCAPED_UNICODE);
        $update_stmt = $conn->prepare("UPDATE sub_admin_settings SET store_type = ?, store_type_config_json = ?, whatsapp_api_token = ?, starting_message = ?, system_instruction = ?, ignore_numbers = ?, message_interval = ?, faq_strict_unknown = ?, unknown_question_reply = ? WHERE admin_id = ?");
        $update_stmt->bind_param("ssssssissi", $selected_store_type, $dynamic_json, $whatsapp_token, $starting_message, $system_instruction, $ignore_numbers, $message_interval, $faq_strict_unknown, $unknown_question_reply, $admin_id);
        
        if ($update_stmt->execute()) {
            $message = "Settings updated successfully!";
            // Refresh settings
            $stmt = $conn->prepare($settings_query);
            $stmt->bind_param("i", $admin_id);
            $stmt->execute();
            $settings = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $stored_dynamic_config = json_decode((string) ($settings['store_type_config_json'] ?? ''), true);
            if (!is_array($stored_dynamic_config)) {
                $stored_dynamic_config = [];
            }
        } else {
            $message = "Error updating settings";
        }
        $update_stmt->close();
    }
}

$test_chat_token = ensure_test_chat_token($conn, $admin_id);

$conn->close();

// Get webhook URL
$webhook_url = base_url('webhook.php?token=' . ($settings['webhook_token'] ?? ''));
$test_chat_url = base_url('chat_test.php?token=' . urlencode($test_chat_token));

$knownColumnFallbackKeys = [
    'whatsapp_api_token',
    'starting_message',
    'system_instruction',
    'ignore_numbers',
    'message_interval',
    'unknown_question_reply',
];
$store_type_frontend = [];
foreach ($store_types as $stDef) {
    $slug = trim((string) ($stDef['slug'] ?? ''));
    if ($slug === '') {
        continue;
    }
    $fields = $store_type_fields_map[$slug] ?? [];
    $entry = [
        'title' => (string) ($stDef['title'] ?? $slug),
        'details' => (string) ($stDef['details'] ?? ''),
        'fields' => [],
    ];
    foreach ($fields as $f) {
        $key = trim((string) ($f['field_key'] ?? ''));
        if ($key === '') {
            continue;
        }
        $value = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cfg_' . $key]) && $selected_store_type === $slug) {
            $value = (string) $_POST['cfg_' . $key];
        } elseif ($selected_store_type === $slug && array_key_exists($key, $stored_dynamic_config)) {
            $value = (string) $stored_dynamic_config[$key];
        } elseif (in_array($key, $knownColumnFallbackKeys, true) && isset($settings[$key])) {
            $value = (string) $settings[$key];
        }
        $options = [];
        $optRaw = trim((string) ($f['options_json'] ?? ''));
        if ($optRaw !== '') {
            $decodedOptions = json_decode($optRaw, true);
            if (is_array($decodedOptions)) {
                $options = $decodedOptions;
            }
        }
        $entry['fields'][] = [
            'key' => $key,
            'label' => (string) ($f['field_label'] ?? $key),
            'type' => (string) ($f['field_type'] ?? 'text'),
            'placeholder' => (string) ($f['placeholder'] ?? ''),
            'help' => (string) ($f['help_text'] ?? ''),
            'required' => !empty($f['is_required']),
            'options' => $options,
            'value' => $value,
        ];
    }
    $store_type_frontend[$slug] = $entry;
}
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
            <a href="store_types.php">Store Types</a>
            <a href="platform_ai.php">Platform AI</a>
        <?php endif; ?>
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

            <div class="webhook-info" style="margin-top:16px;">
                <strong>Test bot (preview only)</strong>
                <div class="help-text" style="margin-bottom:10px;">Open a chat screen to try your FAQ + AI without sending WhatsApp. Link is unique to your store — do not share publicly.</div>
                <code style="word-break:break-all;"><?php echo htmlspecialchars($test_chat_url); ?></code>
                <div style="margin-top:12px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
                    <a href="<?php echo htmlspecialchars($test_chat_url); ?>" target="_blank" rel="noopener" style="display:inline-block;padding:10px 18px;background:#28a745;color:#fff;text-decoration:none;border-radius:6px;font-weight:bold;">Open test chat</a>
                    <button type="button" id="copyTestLink" style="padding:10px 16px;background:#6c757d;color:#fff;border:none;border-radius:6px;cursor:pointer;">Copy link</button>
                </div>
            </div>
            
            <form method="POST" id="settingsForm">
                <div class="form-group">
                    <label>Store Type</label>
                    <select name="store_type" id="storeTypeSelect" required>
                        <?php if (empty($store_types)): ?>
                            <option value="">No store type configured</option>
                        <?php else: ?>
                            <?php foreach ($store_types as $st): ?>
                                <?php $slug = (string) ($st['slug'] ?? ''); ?>
                                <option value="<?php echo htmlspecialchars($slug); ?>" <?php echo $selected_store_type === $slug ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string) ($st['title'] ?? $slug)); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <div class="help-text">Store type decides which configuration fields are shown below.</div>
                </div>

                <div class="webhook-info" id="storeTypeDetails" style="display:none;"></div>
                <div id="dynamicStoreTypeFields"></div>

                <div class="form-group">
                    <label>Ignore Numbers List (last 6 digits match)</label>
                    <textarea name="ignore_numbers" placeholder="e.g. 923001112233&#10;03001234567&#10;111222"><?php echo htmlspecialchars($settings['ignore_numbers'] ?? ''); ?></textarea>
                    <div class="help-text">Comma separated format recommended (example: 923001112233,03001234567,111222). If incoming WhatsApp number last 6 digits match this list, bot will not send any reply.</div>
                </div>
                
                <div class="form-group">
                    <label>Message Interval (seconds)</label>
                    <input type="number" name="message_interval" value="<?php echo htmlspecialchars($settings['message_interval'] ?? 60); ?>" min="10" max="600" required>
                    <div class="help-text">Time interval between messages in seconds (minimum 10, maximum 600). Messages will be queued and sent one by one with this interval.</div>
                </div>

                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;font-weight:bold;">
                        <input type="checkbox" name="faq_strict_unknown" value="1" <?php echo !empty($settings['faq_strict_unknown']) ? 'checked' : ''; ?>>
                        FAQ strict mode (unknown = no AI)
                    </label>
                    <div class="help-text">When enabled: customer messages that <strong>do not</strong> match your <a href="faq.php">FAQ</a> and do not look like a product/order link are <strong>not</strong> sent to AI. They are saved under FAQ → Pending so you can reply later; the customer gets the message below. Product/price/order-style messages still use AI.</div>
                </div>

                <div class="form-group">
                    <label>Reply when question is unknown (strict mode)</label>
                    <textarea name="unknown_question_reply" rows="3" placeholder="e.g. Shukriya — hamari team jald jawab de gi."><?php echo htmlspecialchars($settings['unknown_question_reply'] ?? ''); ?></textarea>
                    <div class="help-text">Sent to the customer when strict mode is on and their text did not match any FAQ entry. Leave empty for a short default English message.</div>
                </div>
                
                <button type="submit" name="update_settings">Save Settings</button>
            </form>
        </div>
    </div>
    <script>
    (function () {
        var storeTypeSelect = document.getElementById('storeTypeSelect');
        var dynamicContainer = document.getElementById('dynamicStoreTypeFields');
        var storeTypeDetails = document.getElementById('storeTypeDetails');
        var storeTypeSchema = <?php echo json_encode($store_type_frontend, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

        function escHtml(v) {
            return String(v || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function renderField(field) {
            var fieldName = 'cfg_' + field.key;
            var required = field.required ? ' required' : '';
            var requiredMark = field.required ? ' <span style="color:#c62828;">*</span>' : '';
            var help = field.help ? '<div class="help-text">' + escHtml(field.help) + '</div>' : '';
            var ph = escHtml(field.placeholder || '');
            var value = field.value == null ? '' : String(field.value);
            var input = '';
            if (field.type === 'textarea') {
                input = '<textarea name="' + escHtml(fieldName) + '" placeholder="' + ph + '"' + required + '>' + escHtml(value) + '</textarea>';
            } else if (field.type === 'select') {
                var opts = Array.isArray(field.options) ? field.options : [];
                input = '<select name="' + escHtml(fieldName) + '"' + required + '>';
                input += '<option value="">Select</option>';
                for (var i = 0; i < opts.length; i++) {
                    var opt = opts[i];
                    var ov = '';
                    var ol = '';
                    if (typeof opt === 'string') {
                        ov = opt;
                        ol = opt;
                    } else if (opt && typeof opt === 'object') {
                        ov = String(opt.value || '');
                        ol = String(opt.label || ov);
                    }
                    var sel = ov === value ? ' selected' : '';
                    input += '<option value="' + escHtml(ov) + '"' + sel + '>' + escHtml(ol) + '</option>';
                }
                input += '</select>';
            } else {
                var typ = ['text', 'number', 'password'].indexOf(field.type) >= 0 ? field.type : 'text';
                input = '<input type="' + escHtml(typ) + '" name="' + escHtml(fieldName) + '" value="' + escHtml(value) + '" placeholder="' + ph + '"' + required + '>';
            }
            return '<div class="form-group"><label>' + escHtml(field.label) + requiredMark + '</label>' + input + help + '</div>';
        }

        function renderStoreTypeFields() {
            if (!storeTypeSelect || !dynamicContainer) return;
            var slug = storeTypeSelect.value || '';
            var cfg = storeTypeSchema[slug];
            if (!cfg) {
                dynamicContainer.innerHTML = '';
                if (storeTypeDetails) {
                    storeTypeDetails.style.display = 'none';
                }
                return;
            }
            if (storeTypeDetails) {
                if (cfg.details && String(cfg.details).trim() !== '') {
                    storeTypeDetails.style.display = 'block';
                    storeTypeDetails.innerHTML = '<strong>' + escHtml(cfg.title || slug) + '</strong><div class="help-text" style="margin-top:8px;">' + escHtml(cfg.details) + '</div>';
                } else {
                    storeTypeDetails.style.display = 'none';
                }
            }
            var fields = Array.isArray(cfg.fields) ? cfg.fields : [];
            var html = '';
            for (var i = 0; i < fields.length; i++) {
                html += renderField(fields[i]);
            }
            dynamicContainer.innerHTML = html;
        }

        if (storeTypeSelect) {
            storeTypeSelect.addEventListener('change', renderStoreTypeFields);
            renderStoreTypeFields();
        }

        var btn = document.getElementById('copyTestLink');
        if (!btn) return;
        var url = <?php echo json_encode($test_chat_url, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        btn.addEventListener('click', function () {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function () { btn.textContent = 'Copied!'; setTimeout(function () { btn.textContent = 'Copy link'; }, 2000); });
            } else {
                prompt('Copy this URL:', url);
            }
        });
    })();
    </script>
</body>
</html>

