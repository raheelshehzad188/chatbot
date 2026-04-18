<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$conn = getDBConnection();
$admin_id = $_SESSION['admin_id'];
$admin_role = $_SESSION['admin_role'];

// Get all leads
if ($admin_role == 'super_admin') {
    $query = "SELECT l.*, a.username as admin_name FROM leads l 
             LEFT JOIN admins a ON l.sub_admin_id = a.id 
             ORDER BY l.created_at DESC";
    $stmt = $conn->prepare($query);
} else {
    $query = "SELECT l.*, a.username as admin_name FROM leads l 
             LEFT JOIN admins a ON l.sub_admin_id = a.id 
             WHERE l.sub_admin_id = ? ORDER BY l.created_at DESC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $admin_id);
}
$stmt->execute();
$leads = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leads List</title>
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
        .btn {
            padding: 6px 12px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-size: 14px;
            display: inline-block;
        }
        .btn:hover {
            background: #5568d3;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Leads List</h1>
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
        <a href="faq.php">FAQ</a>
        <a href="settings.php">Settings</a>
    </div>
    <div class="container">
        <h2 style="margin: 20px 0;">All Leads</h2>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Phone</th>
                    <th>Name</th>
                    <?php if ($admin_role == 'super_admin'): ?>
                        <th>Sub Admin</th>
                    <?php endif; ?>
                    <th>Created At</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($leads)): ?>
                    <tr>
                        <td colspan="<?php echo $admin_role == 'super_admin' ? 6 : 5; ?>" style="text-align: center;">No leads found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($leads as $lead): ?>
                        <tr>
                            <td><?php echo $lead['id']; ?></td>
                            <td><?php echo htmlspecialchars($lead['phone']); ?></td>
                            <td><?php echo htmlspecialchars($lead['name']); ?></td>
                            <?php if ($admin_role == 'super_admin'): ?>
                                <td><?php echo htmlspecialchars($lead['admin_name'] ?? 'N/A'); ?></td>
                            <?php endif; ?>
                            <td><?php echo date('Y-m-d H:i', strtotime($lead['created_at'])); ?></td>
                            <td><a href="chat.php?phone=<?php echo urlencode($lead['phone']); ?>" class="btn">View Chat</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>

