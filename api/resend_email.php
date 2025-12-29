<?php
// bpms/api/resend_email.php
require_once __DIR__ . '/../app/core/guard.php';
requireLogin();
requireRole(['Event Manager', 'Contestant Manager']);
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/core/CustomMailer.php';

if (isset($_POST['user_id'])) {
    
    $user_id = (int)$_POST['user_id'];
    $action_type = $_POST['action_type'] ?? 'reset'; // Default to 'reset' if not specified
    
    // 1. Get User Details First (Needed for both actions)
    $query = $conn->query("SELECT name, email, role FROM users WHERE id = $user_id");
    $user = $query->fetch_assoc();
    
    if (!$user) {
        $redirect_url = $_SERVER['HTTP_REFERER'] ?? '../public/index.php';
        header("Location: $redirect_url" . (strpos($redirect_url, '?') ? '&' : '?') . "error=" . urlencode("User not found"));
        exit();
    }

    $site_link = "http://YOUR-SUBDOMAIN.rf.gd/bpms/public/index.php"; // CHANGE THIS IF NEEDED
    $msg = "";
    $status = "error";

    // --- OPTION A: REMINDER (Keep Password, Just Email) ---
    if ($action_type === 'reminder') {
        $subject = "Reminder: Your Access Credentials";
        $body = "
            <h2>Hello, {$user['name']}!</h2>
            <p>This is a reminder for your access to the <b>BPMS System</b>.</p>
            
            <div style='background:#f0f9ff; padding:15px; border-radius:8px; border:1px solid #bae6fd; margin:20px 0; color:#0369a1;'>
                <strong>Your Login Details:</strong><br>
                Email: <b>{$user['email']}</b><br>
                Password: <i>(Hidden for security. If you forgot it, ask Admin to reset.)</i>
            </div>

            <p>Click below to login:</p>
            <p><a href='$site_link' style='background:#0284c7; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>Login to Dashboard</a></p>
        ";

        if (sendCustomEmail($user['email'], $subject, $body)) {
            $msg = "Reminder email sent successfully!";
            $status = "success";
        } else {
            $msg = "Failed to send reminder email.";
        }
    }

    // --- OPTION B: RESET PASSWORD & SEND (Your Original Logic) ---
    elseif ($action_type === 'reset') {
        
        // 1. Generate New Password
        $new_pass = substr(str_shuffle("abcdefDEFGH23456789"), 0, 8);
        $hashed_pass = password_hash($new_pass, PASSWORD_DEFAULT);

        // 2. Update Database
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $hashed_pass, $user_id);
        
        if ($stmt->execute()) {
            
            // 3. Send Email
            $subject = "Security Alert: New Login Credentials";
            $body = "
                <h2>Hello, {$user['name']}!</h2>
                <p>Your login credentials for the BPMS System have been <b>reset</b> by the Event Manager.</p>
                
                <div style='background:#fffbeb; padding:15px; border-radius:8px; border:1px solid #fcd34d; margin:20px 0; color:#92400e;'>
                    <strong>New Credentials:</strong><br>
                    Email: <b>{$user['email']}</b><br>
                    Password: <b>$new_pass</b>
                </div>

                <p>Please login immediately using the link below:</p>
                <p><a href='$site_link' style='background:#F59E0B; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>Login Now</a></p>
            ";

            if (sendCustomEmail($user['email'], $subject, $body)) {
                $msg = "Password reset and email sent successfully!";
                $status = "success";
            } else {
                $msg = "Password reset, but Email Failed to send.";
            }

        } else {
            $msg = "Database Error during reset.";
        }
    }

    // Redirect back
    $redirect_url = $_SERVER['HTTP_REFERER'] ?? '../public/index.php';
    // Clean URL to avoid stacking parameters
    $redirect_url = strtok($redirect_url, '?');
    // If coming from organizers page, preserve view
    if (strpos($_SERVER['HTTP_REFERER'], 'organizers.php') !== false) {
        $redirect_url .= "?view=active"; 
    }
    
    header("Location: $redirect_url" . (strpos($redirect_url, '?') ? '&' : '?') . "$status=" . urlencode($msg));
    exit();
}
?>