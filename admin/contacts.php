<?php
session_start();
require_once '../config.php';
require_once '../functions.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$conn = getDBConnection();
$admin_id = (int) $_SESSION['admin_id'];
$admin_role = $_SESSION['admin_role'];
$message = '';
$didToggle = false;

/**
 * Comma-separated ignore list parser for this page.
 * Example stored value: 123456,654321,923001112233
 *
 * @param string $raw
 * @return array<int, string>
 */
function getIgnoreSuffixesFromComma($raw) {
    $tokens = explode(',', (string) $raw);
    $suffixes = [];
    foreach ($tokens as $token) {
        $token = trim($token);
        if ($token === '') {
            continue;
        }
        $digits = normalizePhoneDigits($token);
        if ($digits === '') {
            continue;
        }
        $suffix = strlen($digits) <= 6 ? $digits : substr($digits, -6);
        if ($suffix !== '') {
            $suffixes[$suffix] = true;
        }
    }
    return array_keys($suffixes);
}

// Backward-compatible: add ignore_numbers column if missing
if ($col = $conn->query("SHOW COLUMNS FROM sub_admin_settings LIKE 'ignore_numbers'")) {
    if ($col->num_rows === 0) {
        @$conn->query("ALTER TABLE sub_admin_settings ADD COLUMN ignore_numbers TEXT DEFAULT '' AFTER system_instruction");
    }
}

// Toggle ignore / unignore for a contact
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_ignore'])) {
    $lead_id = (int) ($_POST['lead_id'] ?? 0);
    $targetAction = $_POST['target_action'] ?? 'toggle'; // ignore | back | toggle
    if ($lead_id > 0) {
        if ($admin_role === 'super_admin') {
            $leadStmt = $conn->prepare("SELECT id, sub_admin_id, phone FROM leads WHERE id = ? LIMIT 1");
            $leadStmt->bind_param("i", $lead_id);
        } else {
            $leadStmt = $conn->prepare("SELECT id, sub_admin_id, phone FROM leads WHERE id = ? AND sub_admin_id = ? LIMIT 1");
            $leadStmt->bind_param("ii", $lead_id, $admin_id);
        }
        $leadStmt->execute();
        $lead = $leadStmt->get_result()->fetch_assoc();
        $leadStmt->close();

        if ($lead) {
            $targetSubAdmin = (int) $lead['sub_admin_id'];
            $phoneSuffix = getPhoneLastSix($lead['phone']);

            $setStmt = $conn->prepare("SELECT ignore_numbers FROM sub_admin_settings WHERE admin_id = ? LIMIT 1");
            $setStmt->bind_param("i", $targetSubAdmin);
            $setStmt->execute();
            $settingsRow = $setStmt->get_result()->fetch_assoc();
            $setStmt->close();

            // If settings row is missing for this owner, create it first so ignore list can be persisted.
            if (!$settingsRow) {
                $newWebhookToken = bin2hex(random_bytes(32));
                $insSettings = $conn->prepare("INSERT INTO sub_admin_settings (admin_id, webhook_token, ignore_numbers) VALUES (?, ?, '')");
                $insSettings->bind_param("is", $targetSubAdmin, $newWebhookToken);
                $insSettings->execute();
                $insSettings->close();
                $settingsRow = ['ignore_numbers' => ''];
            }

            $rawIgnore = $settingsRow['ignore_numbers'] ?? '';
            $suffixes = getIgnoreSuffixesFromComma($rawIgnore);

            if ($phoneSuffix !== '') {
                $alreadyIgnored = in_array($phoneSuffix, $suffixes, true);
                $shouldRemove = ($targetAction === 'back') || ($targetAction === 'toggle' && $alreadyIgnored);
                $shouldAdd = ($targetAction === 'ignore') || ($targetAction === 'toggle' && !$alreadyIgnored);
                if ($shouldRemove && $alreadyIgnored) {
                    $index = array_search($phoneSuffix, $suffixes, true);
                    unset($suffixes[$index]);
                    $message = "Number removed from ignore list.";
                } elseif ($shouldAdd && !$alreadyIgnored) {
                    $suffixes[] = $phoneSuffix;
                    $suffixes = array_values(array_unique($suffixes));
                    $message = "Number added to ignore list.";
                } else {
                    $message = "No changes needed.";
                }
                sort($suffixes);
                $newIgnore = implode(',', $suffixes);

                $upd = $conn->prepare("UPDATE sub_admin_settings SET ignore_numbers = ? WHERE admin_id = ?");
                $upd->bind_param("si", $newIgnore, $targetSubAdmin);
                $upd->execute();
                $upd->close();
                $didToggle = true;
            }
        }
    }
}

if ($didToggle) {
    header('Location: contacts.php?updated=1');
    exit;
}
if (isset($_GET['updated'])) {
    $message = 'Ignore list updated successfully.';
}

// Load contacts with per-sub-admin ignore list
if ($admin_role === 'super_admin') {
    $query = "SELECT l.id, l.sub_admin_id, l.phone, l.name, l.created_at, a.username AS admin_name, s.ignore_numbers
              FROM leads l
              LEFT JOIN admins a ON l.sub_admin_id = a.id
              LEFT JOIN sub_admin_settings s ON l.sub_admin_id = s.admin_id
              ORDER BY l.created_at DESC";
    $stmt = $conn->prepare($query);
} else {
    $query = "SELECT l.id, l.sub_admin_id, l.phone, l.name, l.created_at, a.username AS admin_name, s.ignore_numbers
              FROM leads l
              LEFT JOIN admins a ON l.sub_admin_id = a.id
              LEFT JOIN sub_admin_settings s ON l.sub_admin_id = s.admin_id
              WHERE l.sub_admin_id = ?
              ORDER BY l.created_at DESC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $admin_id);
}
$stmt->execute();
$contacts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contacts</title>
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
        .nav a {
            margin-right: 20px; text-decoration: none; color: #667eea; font-weight: bold;
        }
        .container { max-width: 1200px; margin: 20px auto; padding: 0 20px; }
        .message {
            padding: 12px; margin-bottom: 16px; border-radius: 5px;
            background: #d4edda; color: #155724;
        }
        table {
            width: 100%; background: white; border-collapse: collapse;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #667eea; color: white; }
        .btn {
            border: none; border-radius: 4px; padding: 6px 10px;
            color: white; cursor: pointer; font-size: 12px;
        }
        .btn-ignore { background: #dc3545; }
        .btn-unignore { background: #28a745; }
        .badge {
            display: inline-block; padding: 3px 8px; border-radius: 999px;
            font-size: 12px; font-weight: bold;
        }
        .badge-yes { background: #fdecea; color: #b00020; }
        .badge-no { background: #e8f5e9; color: #1b5e20; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Contacts</h1>
        <a href="dashboard.php">Back to Dashboard</a>
    </div>
    <div class="nav">
        <a href="dashboard.php">Dashboard</a>
        <?php if ($admin_role == 'super_admin'): ?>
            <a href="sub_admins.php">Sub Admins</a>
            <a href="platform_ai.php">Platform AI</a>
        <?php endif; ?>
        <a href="leads.php">Leads</a>
        <a href="contacts.php">Contacts</a>
        <a href="settings.php">Settings</a>
    </div>
    <div class="container">
        <?php if ($message): ?>
            <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <h2 style="margin: 20px 0;">All Contacts</h2>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Phone</th>
                    <th>Last 6</th>
                    <th>Name</th>
                    <?php if ($admin_role == 'super_admin'): ?>
                        <th>Sub Admin</th>
                    <?php endif; ?>
                    <th>Ignored</th>
                    <th>Created</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($contacts)): ?>
                    <tr>
                        <td colspan="<?php echo $admin_role == 'super_admin' ? 8 : 7; ?>" style="text-align:center;">No contacts found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($contacts as $c): ?>
                        <?php
                            $suffix = getPhoneLastSix($c['phone']);
                            $ignoreSuffixes = getIgnoreSuffixesFromComma($c['ignore_numbers'] ?? '');
                            $ignored = ($suffix !== '' && in_array($suffix, $ignoreSuffixes, true));
                        ?>
                        <tr>
                            <td><?php echo (int) $c['id']; ?></td>
                            <td><?php echo htmlspecialchars($c['phone']); ?></td>
                            <td><code><?php echo htmlspecialchars($suffix); ?></code></td>
                            <td><?php echo htmlspecialchars($c['name']); ?></td>
                            <?php if ($admin_role == 'super_admin'): ?>
                                <td><?php echo htmlspecialchars($c['admin_name'] ?? 'N/A'); ?></td>
                            <?php endif; ?>
                            <td>
                                <?php 
                                if ($ignored): ?>
                                    <span class="badge badge-yes">Yes</span>
                                <?php else: ?>
                                    <span class="badge badge-no">No</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('Y-m-d H:i', strtotime($c['created_at'])); ?></td>
                            <td>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="lead_id" value="<?php echo (int) $c['id']; ?>">
                                    <input type="hidden" name="target_action" value="<?php echo $ignored ? 'back' : 'ignore'; ?>">
                                    <button type="submit" name="toggle_ignore" value="1" class="btn <?php echo $ignored ? 'btn-unignore' : 'btn-ignore'; ?>">
                                        <?php echo $ignored ? 'Back' : 'Ignore'; ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>

