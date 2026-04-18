<?php
session_start();
require_once '../config.php';

// Check if logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$conn = getDBConnection();
$admin_id = $_SESSION['admin_id'];
$admin_role = $_SESSION['admin_role'];

// Get sub-admin ID for filtering
$sub_admin_id = $admin_role == 'super_admin' ? null : $admin_id;

// Get leads count
if ($admin_role == 'super_admin') {
    $leads_query = "SELECT COUNT(*) as total FROM leads";
    $stmt = $conn->prepare($leads_query);
} else {
    $leads_query = "SELECT COUNT(*) as total FROM leads WHERE sub_admin_id = ?";
    $stmt = $conn->prepare($leads_query);
    $stmt->bind_param("i", $admin_id);
}
$stmt->execute();
$leads_result = $stmt->get_result();
$leads_count = $leads_result->fetch_assoc()['total'];
$stmt->close();

// Get recent leads
if ($admin_role == 'super_admin') {
    $recent_query = "SELECT l.*, a.username as admin_name FROM leads l 
                     LEFT JOIN admins a ON l.sub_admin_id = a.id 
                     ORDER BY l.created_at DESC LIMIT 10";
    $stmt = $conn->prepare($recent_query);
} else {
    $recent_query = "SELECT l.*, a.username as admin_name FROM leads l 
                     LEFT JOIN admins a ON l.sub_admin_id = a.id 
                     WHERE l.sub_admin_id = ? ORDER BY l.created_at DESC LIMIT 10";
    $stmt = $conn->prepare($recent_query);
    $stmt->bind_param("i", $admin_id);
}
$stmt->execute();
$recent_leads = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$test_chat_url = '';
if ($admin_role !== 'super_admin') {
    require_once __DIR__ . '/../functions.php';
    faq_ensure_schema($conn);
    $tt = ensure_test_chat_token($conn, (int) $admin_id);
    $test_chat_url = base_url('chat_test.php?token=' . urlencode($tt));
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
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
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .stat-card h3 {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
        }
        .stat-card .number {
            font-size: 32px;
            color: #667eea;
            font-weight: bold;
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
        <h1>Admin Dashboard</h1>
        <div>
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['admin_username']); ?></span>
            <a href="logout.php">Logout</a>
        </div>
    </div>
    <div class="nav">
        <a href="dashboard.php">Dashboard</a>
        <?php if ($admin_role == 'super_admin'): ?>
            <a href="sub_admins.php">Sub Admins</a>
            <a href="categories.php">Categories</a>
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
        <div class="stats">
            <div class="stat-card">
                <h3>Total Leads</h3>
                <div class="number"><?php echo $leads_count; ?></div>
            </div>
            <?php if ($test_chat_url !== ''): ?>
            <div class="stat-card" style="display:flex;flex-direction:column;justify-content:center;gap:10px;">
                <h3>Test your bot</h3>
                <a href="<?php echo htmlspecialchars($test_chat_url); ?>" target="_blank" rel="noopener" class="btn" style="text-align:center;">Open test chat</a>
                <span style="font-size:12px;color:#666;">FAQ + AI preview (no WhatsApp)</span>
            </div>
            <?php endif; ?>
        </div>
        <h2 style="margin-bottom: 15px;">Recent Leads</h2>
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
                <?php if (empty($recent_leads)): ?>
                    <tr>
                        <td colspan="<?php echo $admin_role == 'super_admin' ? 6 : 5; ?>" style="text-align: center;">No leads found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recent_leads as $lead): ?>
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

