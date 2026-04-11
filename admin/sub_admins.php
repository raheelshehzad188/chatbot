<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['admin_id']) || $_SESSION['admin_role'] != 'super_admin') {
    header('Location: login.php');
    exit;
}

$conn = getDBConnection();
$message = '';

// Handle add sub-admin
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_admin'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $email = $_POST['email'] ?? '';
    
    if (!empty($username) && !empty($password)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO admins (username, password, email, role) VALUES (?, ?, ?, 'sub_admin')");
        $stmt->bind_param("sss", $username, $hashed_password, $email);
        
        if ($stmt->execute()) {
            $new_admin_id = $conn->insert_id;
            // Create webhook token
            $webhook_token = bin2hex(random_bytes(32));
            $settings_stmt = $conn->prepare("INSERT INTO sub_admin_settings (admin_id, webhook_token) VALUES (?, ?)");
            $settings_stmt->bind_param("is", $new_admin_id, $webhook_token);
            $settings_stmt->execute();
            $settings_stmt->close();
            
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

// Get all sub-admins
$query = "SELECT a.*, s.webhook_token FROM admins a 
         LEFT JOIN sub_admin_settings s ON a.id = s.admin_id 
         WHERE a.role = 'sub_admin' ORDER BY a.created_at DESC";
$sub_admins = $conn->query($query)->fetch_all(MYSQLI_ASSOC);
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
        <a href="leads.php">Leads</a>
        <a href="settings.php">Settings</a>
    </div>
    <div class="container">
        <?php if ($message): ?>
            <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
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
                <button type="submit" name="add_admin">Add Sub-Admin</button>
            </form>
        </div>
        
        <h2 style="margin-bottom: 15px;">All Sub-Admins</h2>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Webhook Token</th>
                    <th>Webhook URL</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($sub_admins)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center;">No sub-admins found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($sub_admins as $admin): ?>
                        <tr>
                            <td><?php echo $admin['id']; ?></td>
                            <td><?php echo htmlspecialchars($admin['username']); ?></td>
                            <td><?php echo htmlspecialchars($admin['email']); ?></td>
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
                            <td><?php echo ucfirst($admin['status']); ?></td>
                            <td>
                                <a href="?delete=<?php echo $admin['id']; ?>" class="btn-danger" onclick="return confirm('Are you sure?')">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>

