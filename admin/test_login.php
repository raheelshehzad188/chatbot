<?php
require_once '../config.php';

// Test login functionality
echo "<h2>Login Test</h2>";

$username = 'superadmin';
$password = 'admin123';

$conn = getDBConnection();

// Check if admin exists
$stmt = $conn->prepare("SELECT id, username, password, role, status FROM admins WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $admin = $result->fetch_assoc();
    echo "<p><strong>Admin Found:</strong></p>";
    echo "<pre>";
    print_r($admin);
    echo "</pre>";
    
    echo "<p><strong>Password Verification:</strong></p>";
    $verify = password_verify($password, $admin['password']);
    echo "<p>password_verify('$password', hash): " . ($verify ? '<span style="color:green">TRUE</span>' : '<span style="color:red">FALSE</span>') . "</p>";
    
    if ($verify) {
        echo "<p style='color:green;'><strong>✓ Login should work!</strong></p>";
    } else {
        echo "<p style='color:red;'><strong>✗ Password hash mismatch!</strong></p>";
        echo "<p>Updating password hash...</p>";
        
        // Update password
        $new_hash = password_hash($password, PASSWORD_DEFAULT);
        $update_stmt = $conn->prepare("UPDATE admins SET password = ? WHERE username = ?");
        $update_stmt->bind_param("ss", $new_hash, $username);
        if ($update_stmt->execute()) {
            echo "<p style='color:green;'>Password updated successfully!</p>";
            echo "<p>New hash: $new_hash</p>";
        } else {
            echo "<p style='color:red;'>Error updating password</p>";
        }
        $update_stmt->close();
    }
} else {
    echo "<p style='color:red;'>Admin not found! Please run database.sql first.</p>";
}

$stmt->close();
$conn->close();
?>

