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

// Get filter parameters
$phone_filter = $_GET['phone'] ?? '';
$success_filter = $_GET['success'] ?? '';

// Build query based on role and filters
if ($admin_role == 'super_admin') {
    $query = "SELECT wm.*, a.username as admin_name FROM whatsapp_messages wm 
              LEFT JOIN admins a ON wm.sub_admin_id = a.id 
              WHERE 1=1";
    $params = [];
    $types = [];
    
    if (!empty($phone_filter)) {
        $query .= " AND wm.phone LIKE ?";
        $params[] = "%$phone_filter%";
        $types[] = "s";
    }
    
    if ($success_filter !== '') {
        $query .= " AND wm.success = ?";
        $params[] = $success_filter;
        $types[] = "i";
    }
    
    $query .= " ORDER BY wm.created_at DESC LIMIT 200";
    
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param(implode('', $types), ...$params);
    }
} else {
    $query = "SELECT wm.*, a.username as admin_name FROM whatsapp_messages wm 
              LEFT JOIN admins a ON wm.sub_admin_id = a.id 
              WHERE wm.sub_admin_id = ?";
    $params = [$admin_id];
    $types = ["i"];
    
    if (!empty($phone_filter)) {
        $query .= " AND wm.phone LIKE ?";
        $params[] = "%$phone_filter%";
        $types[] = "s";
    }
    
    if ($success_filter !== '') {
        $query .= " AND wm.success = ?";
        $params[] = $success_filter;
        $types[] = "i";
    }
    
    $query .= " ORDER BY wm.created_at DESC LIMIT 200";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param(implode('', $types), ...$params);
}

$stmt->execute();
$messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WhatsApp Messages History</title>
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
            max-width: 1400px;
            margin: 20px auto;
            padding: 0 20px;
        }
        .filters {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .filters form {
            display: flex;
            gap: 15px;
            align-items: end;
        }
        .filter-group {
            flex: 1;
        }
        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #333;
        }
        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        button {
            padding: 10px 20px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }
        button:hover {
            background: #5568d3;
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
        .message-cell {
            max-width: 300px;
            word-wrap: break-word;
        }
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }
        .status-success {
            background: #d4edda;
            color: #155724;
        }
        .status-failed {
            background: #f8d7da;
            color: #721c24;
        }
        .view-details {
            padding: 5px 10px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 3px;
            font-size: 12px;
        }
        .view-details:hover {
            background: #5568d3;
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
        }
        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 30px;
            border-radius: 8px;
            width: 90%;
            max-width: 800px;
            max-height: 80vh;
            overflow-y: auto;
        }
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close:hover {
            color: #000;
        }
        .detail-section {
            margin-bottom: 20px;
        }
        .detail-section h3 {
            margin-bottom: 10px;
            color: #667eea;
        }
        .detail-section pre {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>WhatsApp Messages History</h1>
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
        <a href="chatgpt_history.php">ChatGPT History</a>
        <a href="gemini_history.php">Gemini History</a>
        <a href="whatsapp_history.php">WhatsApp History</a>
        <a href="settings.php">Settings</a>
    </div>
    <div class="container">
        <div class="filters">
            <form method="GET">
                <div class="filter-group">
                    <label>Phone Number</label>
                    <input type="text" name="phone" value="<?php echo htmlspecialchars($phone_filter); ?>" placeholder="Search by phone...">
                </div>
                <div class="filter-group">
                    <label>Status</label>
                    <select name="success">
                        <option value="">All Status</option>
                        <option value="1" <?php echo $success_filter === '1' ? 'selected' : ''; ?>>Success</option>
                        <option value="0" <?php echo $success_filter === '0' ? 'selected' : ''; ?>>Failed</option>
                    </select>
                </div>
                <button type="submit">Filter</button>
                <a href="whatsapp_history.php" style="padding: 10px 20px; background: #6c757d; color: white; text-decoration: none; border-radius: 5px; display: inline-block;">Reset</a>
            </form>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Status</th>
                    <th>Phone</th>
                    <th>Name</th>
                    <?php if ($admin_role == 'super_admin'): ?>
                        <th>Sub Admin</th>
                    <?php endif; ?>
                    <th>Message</th>
                    <th>HTTP Code</th>
                    <th>Sent At</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($messages)): ?>
                    <tr>
                        <td colspan="<?php echo $admin_role == 'super_admin' ? '9' : '8'; ?>" style="text-align: center; padding: 30px;">
                            No WhatsApp messages found
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($messages as $msg): ?>
                        <tr>
                            <td><?php echo $msg['id']; ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $msg['success'] ? 'success' : 'failed'; ?>">
                                    <?php echo $msg['success'] ? 'Success' : 'Failed'; ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($msg['phone']); ?></td>
                            <td><?php echo htmlspecialchars($msg['name'] ?: 'N/A'); ?></td>
                            <?php if ($admin_role == 'super_admin'): ?>
                                <td><?php echo htmlspecialchars($msg['admin_name'] ?? 'N/A'); ?></td>
                            <?php endif; ?>
                            <td class="message-cell"><?php echo htmlspecialchars(substr($msg['message'], 0, 100) . (strlen($msg['message']) > 100 ? '...' : '')); ?></td>
                            <td><?php echo $msg['http_code'] ?? 'N/A'; ?></td>
                            <td><?php echo date('Y-m-d H:i:s', strtotime($msg['created_at'])); ?></td>
                            <td>
                                <a href="#" class="view-details" onclick="showDetails(<?php echo htmlspecialchars(json_encode($msg)); ?>); return false;">View Details</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Modal for details -->
    <div id="detailsModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <div id="modalBody"></div>
        </div>
    </div>
    
    <script>
        function showDetails(item) {
            const modal = document.getElementById('detailsModal');
            const body = document.getElementById('modalBody');
            
            let html = '<h2>WhatsApp Message Details</h2>';
            html += '<div class="detail-section">';
            html += '<h3>Basic Information</h3>';
            html += '<p><strong>ID:</strong> ' + item.id + '</p>';
            html += '<p><strong>Status:</strong> <span class="status-badge status-' + (item.success ? 'success' : 'failed') + '">' + (item.success ? 'Success' : 'Failed') + '</span></p>';
            html += '<p><strong>Phone:</strong> ' + item.phone + '</p>';
            html += '<p><strong>Name:</strong> ' + (item.name || 'N/A') + '</p>';
            html += '<p><strong>Sent At:</strong> ' + item.created_at + '</p>';
            html += '<p><strong>HTTP Code:</strong> ' + (item.http_code || 'N/A') + '</p>';
            if (item.error) {
                html += '<p><strong>Error:</strong> <span style="color: red;">' + item.error + '</span></p>';
            }
            html += '</div>';
            
            html += '<div class="detail-section">';
            html += '<h3>Message Content</h3>';
            html += '<pre>' + item.message + '</pre>';
            html += '</div>';
            
            if (item.request_payload) {
                html += '<div class="detail-section">';
                html += '<h3>Request Payload</h3>';
                html += '<pre>' + JSON.stringify(JSON.parse(item.request_payload), null, 2) + '</pre>';
                html += '</div>';
            }
            
            if (item.response_data) {
                html += '<div class="detail-section">';
                html += '<h3>Response Data</h3>';
                html += '<pre>' + JSON.stringify(JSON.parse(item.response_data), null, 2) + '</pre>';
                html += '</div>';
            }
            
            body.innerHTML = html;
            modal.style.display = 'block';
        }
        
        function closeModal() {
            document.getElementById('detailsModal').style.display = 'none';
        }
        
        window.onclick = function(event) {
            const modal = document.getElementById('detailsModal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        }
    </script>
</body>
</html>

