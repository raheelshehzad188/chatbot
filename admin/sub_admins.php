<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['admin_id']) || $_SESSION['admin_role'] != 'super_admin') {
    header('Location: login.php');
    exit;
}

$conn = getDBConnection();
require_once __DIR__ . '/../functions.php';
faq_ensure_schema($conn);
$message = '';
$edit_admin = null;
$edit_id = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;

// Handle update sub-admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_admin'])) {
    $edit_id_post = (int) ($_POST['edit_id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $email = $_POST['email'] ?? '';
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
    $password = $_POST['password'] ?? '';

    if ($edit_id_post <= 0 || $username === '') {
        $message = 'Invalid update request.';
    } else {
        $chk = $conn->prepare("SELECT id FROM admins WHERE id = ? AND role = 'sub_admin'");
        $chk->bind_param('i', $edit_id_post);
        $chk->execute();
        $exists = $chk->get_result()->num_rows > 0;
        $chk->close();

        if (!$exists) {
            $message = 'Sub-admin not found.';
        } else {
            $dup = $conn->prepare("SELECT id FROM admins WHERE username = ? AND id != ?");
            $dup->bind_param('si', $username, $edit_id_post);
            $dup->execute();
            if ($dup->get_result()->num_rows > 0) {
                $message = 'That username is already taken.';
                $dup->close();
            } else {
                $dup->close();
                $category_id = isset($_POST['category_id']) ? (int) $_POST['category_id'] : 0;
                if ($category_id <= 0) {
                    $category_id = null;
                }
                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    if ($category_id === null) {
                        $stmt = $conn->prepare("UPDATE admins SET username = ?, email = ?, status = ?, password = ?, category_id = NULL WHERE id = ? AND role = 'sub_admin'");
                        $stmt->bind_param('ssssi', $username, $email, $status, $hash, $edit_id_post);
                    } else {
                        $stmt = $conn->prepare("UPDATE admins SET username = ?, email = ?, status = ?, password = ?, category_id = ? WHERE id = ? AND role = 'sub_admin'");
                        $stmt->bind_param('ssssii', $username, $email, $status, $hash, $category_id, $edit_id_post);
                    }
                } else {
                    if ($category_id === null) {
                        $stmt = $conn->prepare("UPDATE admins SET username = ?, email = ?, status = ?, category_id = NULL WHERE id = ? AND role = 'sub_admin'");
                        $stmt->bind_param('sssi', $username, $email, $status, $edit_id_post);
                    } else {
                        $stmt = $conn->prepare("UPDATE admins SET username = ?, email = ?, status = ?, category_id = ? WHERE id = ? AND role = 'sub_admin'");
                        $stmt->bind_param('sssii', $username, $email, $status, $category_id, $edit_id_post);
                    }
                }
                if ($stmt->execute()) {
                    $stmt->close();
                    faq_rebuild_cache_file($edit_id_post);
                    $conn->close();
                    header('Location: sub_admins.php?updated=1');
                    exit;
                }
                $message = 'Could not update sub-admin.';
                $stmt->close();
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_admin']) && $message !== '') {
    $edit_id = (int) ($_POST['edit_id'] ?? 0);
}

// Handle add sub-admin
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_admin'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $email = $_POST['email'] ?? '';
    
    if (!empty($username) && !empty($password)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $category_id = isset($_POST['category_id']) ? (int) $_POST['category_id'] : 0;
        if ($category_id > 0) {
            $stmt = $conn->prepare("INSERT INTO admins (username, password, email, role, category_id) VALUES (?, ?, ?, 'sub_admin', ?)");
            $stmt->bind_param("sssi", $username, $hashed_password, $email, $category_id);
        } else {
            $stmt = $conn->prepare("INSERT INTO admins (username, password, email, role) VALUES (?, ?, ?, 'sub_admin')");
            $stmt->bind_param("sss", $username, $hashed_password, $email);
        }

        if ($stmt->execute()) {
            $new_admin_id = (int) $conn->insert_id;
            // Create webhook + test-chat token
            $webhook_token = bin2hex(random_bytes(32));
            $test_chat_token = bin2hex(random_bytes(24));
            $settings_stmt = $conn->prepare("INSERT INTO sub_admin_settings (admin_id, webhook_token, test_chat_token) VALUES (?, ?, ?)");
            $settings_stmt->bind_param("iss", $new_admin_id, $webhook_token, $test_chat_token);
            $settings_stmt->execute();
            $settings_stmt->close();

            faq_rebuild_cache_file($new_admin_id);

            $message = "Sub-admin added successfully! Webhook token: " . $webhook_token;
        } else {
            $message = "Error adding sub-admin";
        }
        $stmt->close();
    }
}

// Handle delete
if (isset($_GET['delete'])) {
    $delete_id = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM admins WHERE id = ? AND role = 'sub_admin'");
    $stmt->bind_param("i", $delete_id);
    $stmt->execute();
    $stmt->close();
    header('Location: sub_admins.php');
    exit;
}

if (isset($_GET['updated'])) {
    $message = 'Sub-admin updated successfully.';
}

// Row being edited (for form)
if ($edit_id > 0) {
    $est = $conn->prepare("SELECT id, username, email, status, category_id FROM admins WHERE id = ? AND role = 'sub_admin'");
    $est->bind_param('i', $edit_id);
    $est->execute();
    $edit_admin = $est->get_result()->fetch_assoc();
    $est->close();
    if (!$edit_admin) {
        $edit_id = 0;
    }
}

$category_options = [];
$cr = $conn->query("SELECT id, name FROM store_categories ORDER BY name ASC");
if ($cr) {
    while ($row = $cr->fetch_assoc()) {
        $category_options[] = $row;
    }
}

// Get all sub-admins
$query = "SELECT a.*, s.webhook_token, s.test_chat_token, c.name AS category_name FROM admins a 
         LEFT JOIN sub_admin_settings s ON a.id = s.admin_id 
         LEFT JOIN store_categories c ON a.category_id = c.id
         WHERE a.role = 'sub_admin' ORDER BY a.created_at DESC";
$sub_admins = $conn->query($query)->fetch_all(MYSQLI_ASSOC);
foreach ($sub_admins as &$sa) {
    if (empty($sa['test_chat_token']) && !empty($sa['id'])) {
        $sa['test_chat_token'] = ensure_test_chat_token($conn, (int) $sa['id']);
    }
}
unset($sa);
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sub Admins</title>
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
            max-width: 1200px;
            margin: 20px auto;
            padding: 0 20px;
        }
        .form-container {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .form-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        button {
            padding: 10px 20px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        button:hover {
            background: #5568d3;
        }
        .message {
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 5px;
            background: #d4edda;
            color: #155724;
        }
        table {
            width: 100%;
            background: white;
            border-collapse: collapse;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        th {
            background: #667eea;
            color: white;
        }
        .btn-danger {
            background: #dc3545;
            color: white;
            padding: 5px 10px;
            text-decoration: none;
            border-radius: 3px;
            font-size: 12px;
        }
        .btn-edit {
            background: #28a745;
            color: white;
            padding: 5px 10px;
            text-decoration: none;
            border-radius: 3px;
            font-size: 12px;
            margin-right: 6px;
        }
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        .webhook-url {
            font-size: 11px;
            color: #666;
            word-break: break-all;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Sub Admins Management</h1>
        <a href="dashboard.php">Back to Dashboard</a>
    </div>
    <div class="nav">
        <a href="dashboard.php">Dashboard</a>
        <a href="sub_admins.php">Sub Admins</a>
        <a href="categories.php">Categories</a>
        <a href="platform_ai.php">Platform AI</a>
        <a href="leads.php">Leads</a>
        <a href="contacts.php">Contacts</a>
        <a href="faq.php">FAQ</a>
        <a href="settings.php">Settings</a>
    </div>
    <div class="container">
        <?php if ($message): ?>
            <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($edit_admin): ?>
        <div class="form-container">
            <h2>Edit Sub-Admin: <?php echo htmlspecialchars($edit_admin['username']); ?></h2>
            <form method="POST" action="sub_admins.php?edit=<?php echo (int) $edit_admin['id']; ?>">
                <input type="hidden" name="edit_id" value="<?php echo (int) $edit_admin['id']; ?>">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" required value="<?php echo htmlspecialchars($edit_admin['username']); ?>">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" value="<?php echo htmlspecialchars($edit_admin['email'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="active" <?php echo (($edit_admin['status'] ?? '') === 'active') ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo (($edit_admin['status'] ?? '') === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Category</label>
                    <select name="category_id">
                        <option value="">— None —</option>
                        <?php foreach ($category_options as $c): ?>
                            <option value="<?php echo (int) $c['id']; ?>" <?php echo ((int) ($edit_admin['category_id'] ?? 0) === (int) $c['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>New password</label>
                    <input type="password" name="password" placeholder="Leave blank to keep current password" autocomplete="new-password">
                </div>
                <button type="submit" name="update_admin" value="1">Save changes</button>
                <a href="sub_admins.php" style="margin-left: 12px;">Cancel</a>
            </form>
        </div>
        <?php else: ?>
        <div class="form-container">
            <h2>Add New Sub-Admin</h2>
            <form method="POST">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" required>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required>
                </div>
                <div class="form-group">
                    <label>Email (Optional)</label>
                    <input type="email" name="email">
                </div>
                <div class="form-group">
                    <label>Category</label>
                    <select name="category_id">
                        <option value="">— None —</option>
                        <?php foreach ($category_options as $c): ?>
                            <option value="<?php echo (int) $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" name="add_admin">Add Sub-Admin</button>
            </form>
        </div>
        <?php endif; ?>
        
        <h2 style="margin-bottom: 15px;">All Sub-Admins</h2>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Category</th>
                    <th>Webhook Token</th>
                    <th>Webhook URL</th>
                    <th>Test bot</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($sub_admins)): ?>
                    <tr>
                        <td colspan="9" style="text-align: center;">No sub-admins found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($sub_admins as $admin): ?>
                        <tr>
                            <td><?php echo $admin['id']; ?></td>
                            <td><?php echo htmlspecialchars($admin['username']); ?></td>
                            <td><?php echo htmlspecialchars($admin['email']); ?></td>
                            <td><?php echo htmlspecialchars($admin['category_name'] ?? '—'); ?></td>
                            <td><code><?php echo htmlspecialchars($admin['webhook_token'] ?? 'N/A'); ?></code></td>
                            <td class="webhook-url">
                                <?php if ($admin['webhook_token']): ?>
                                    <?php 
                                    $webhook_url = base_url('webhook.php?token=' . $admin['webhook_token']);
                                    echo htmlspecialchars($webhook_url);
                                    ?>
                                <?php else: ?>
                                    N/A
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($admin['test_chat_token'])): ?>
                                    <?php $turl = base_url('chat_test.php?token=' . urlencode($admin['test_chat_token'])); ?>
                                    <a href="<?php echo htmlspecialchars($turl); ?>" target="_blank" rel="noopener" style="display:inline-block;padding:6px 12px;background:#28a745;color:#fff;text-decoration:none;border-radius:5px;font-size:13px;font-weight:bold;">Test bot</a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><?php echo ucfirst($admin['status']); ?></td>
                            <td>
                                <a href="?edit=<?php echo (int) $admin['id']; ?>" class="btn-edit">Edit</a>
                                <a href="?delete=<?php echo (int) $admin['id']; ?>" class="btn-danger" onclick="return confirm('Delete this sub-admin and all related data?')">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>

