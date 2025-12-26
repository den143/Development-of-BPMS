<?php
// bpms/api/resend_email.php
require_once __DIR__ . '/../app/core/guard.php';
requireLogin();
requireRole(['Event Manager', 'Contestant Manager']);
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/core/CustomMailer.php';

if (isset($_POST['user_id']) && isset($_POST['role_type'])) {
    
    $user_id = (int)$_POST['user_id'];
    $role_type = $_POST['role_type']; // 'Contestant', 'Judge', 'Organizer'
    
    // 1. Generate a NEW Temporary Password (8 chars)
    $new_pass = substr(str_shuffle("abcdefDEFGH23456789"), 0, 8);
    $hashed_pass = password_hash($new_pass, PASSWORD_DEFAULT);

    // 2. Update Database with New Password
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    $stmt->bind_param("si", $hashed_pass, $user_id);
    
    if ($stmt->execute()) {
        
        // 3. Get User Details
        $query = $conn->query("SELECT name, email, role FROM users WHERE id = $user_id");
        $user = $query->fetch_assoc();
        
        // 4. Send Email
        $site_link = "http://YOUR-SUBDOMAIN.rf.gd/bpms/public/index.php"; // CHANGE THIS
        $subject = "Resending: Your Login Credentials";
        
        $body = "
            <h2>Hello, {$user['name']}!</h2>
            <p>Your login credentials for the BPMS System have been reset/resent by the Event Manager.</p>
            
            <div style='background:#f3f4f6; padding:15px; border-radius:8px; border:1px solid #ddd; margin:20px 0;'>
                <strong>New Credentials:</strong><br>
                Email: <b>{$user['email']}</b><br>
                Password: <b>$new_pass</b>
            </div>

            <p>Please login immediately using the link below:</p>
            <p><a href='$site_link' style='background:#F59E0B; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>Login Now</a></p>
        ";

        if (sendCustomEmail($user['email'], $subject, $body)) {
            $msg = "Email resent successfully! (Password reset)";
            $status = "success";
        } else {
            $msg = "Password reset, but Email Failed to send.";
            $status = "error";
        }

    } else {
        $msg = "Database Error";
        $status = "error";
    }

    // Redirect back to where they came from
    $redirect_url = $_SERVER['HTTP_REFERER'] ?? '../public/index.php';
    header("Location: $redirect_url" . (strpos($redirect_url, '?') ? '&' : '?') . "$status=" . urlencode($msg));
    exit();
}
?>