<?php
require_once __DIR__ . '/../app/core/guard.php';
requireLogin();
requireRole(['Event Manager', 'Contestant Manager']);
require_once __DIR__ . '/../app/config/database.php';

// --- HANDLE POST REQUESTS (Create OR Update) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $action = $_POST['action'] ?? 'create';
    $event_id = (int)$_POST['event_id'];
    $name     = trim($_POST['name']);
    $email    = trim($_POST['email']);
    
    // Details
    $age         = (int)$_POST['age'];
    $height      = trim($_POST['height']);
    $vital_stats = trim($_POST['vital_stats']);
    $hometown    = trim($_POST['hometown']);
    $motto       = trim($_POST['motto']);

    // --- CREATE NEW (Manual Add) ---
    if ($action === 'create') {
        $pass = trim($_POST['password']);
        
        // Photo is required for Create
        if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== 0) {
            header("Location: ../public/contestants.php?error=Photo is required");
            exit();
        }

        $photo_name = uploadPhoto($_FILES['photo']);
        if (!$photo_name) {
            header("Location: ../public/contestants.php?error=Photo upload failed");
            exit();
        }

        $conn->begin_transaction();
        try {
            $hashed_pass = password_hash($pass, PASSWORD_DEFAULT);
            
            // 1. Insert into Users
            $stmt1 = $conn->prepare("INSERT INTO users (name, email, password, role, status) VALUES (?, ?, ?, 'Contestant', 'Active')");
            $stmt1->bind_param("sss", $name, $email, $hashed_pass);
            $stmt1->execute();
            $user_id = $conn->insert_id;

            // 2. Insert into Details
            $stmt2 = $conn->prepare("INSERT INTO contestant_details (user_id, event_id, age, height, vital_stats, hometown, motto, photo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt2->bind_param("iiisssss", $user_id, $event_id, $age, $height, $vital_stats, $hometown, $motto, $photo_name);
            $stmt2->execute();
            
            // =========================================================
            //  SEND EMAIL (Added for Manual Add)
            // =========================================================
            require_once __DIR__ . '/../app/core/CustomMailer.php';
            
            // CHANGE THIS to your live domain
            $site_link = "http://YOUR-SUBDOMAIN.rf.gd/bpms/public/index.php"; 
            
            // Fetch Event Name
            $evt_name = "the Pageant";
            $e_query = $conn->query("SELECT name FROM events WHERE id = $event_id");
            if ($row = $e_query->fetch_assoc()) {
                $evt_name = $row['name'];
            }

            $subject = "Official Contestant Registration";
            $body = "
                <h2>Welcome, $name!</h2>
                <p>You have been officially registered as a contestant for <b>$evt_name</b>.</p>
                
                <div style='background:#f3f4f6; padding:15px; border-radius:8px; border:1px solid #ddd; margin:20px 0;'>
                    <strong>Your Login Credentials:</strong><br>
                    Email: <b>$email</b><br>
                    Password: <b>$pass</b>
                </div>

                <p>Please login to your dashboard to view the schedule:</p>
                <p>
                    <a href='$site_link' style='background:#F59E0B; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>
                        Login to Dashboard
                    </a>
                </p>
            ";
            
            // Send email but don't crash if it fails
            sendCustomEmail($email, $subject, $body);
            // =========================================================

            $conn->commit();
            header("Location: ../public/contestants.php?success=Contestant added successfully");

        } catch (Exception $e) {
            $conn->rollback();
            header("Location: ../public/contestants.php?error=Database Error: " . $e->getMessage());
        }
    } 

    // --- UPDATE EXISTING ---
    elseif ($action === 'update') {
        $id = (int)$_POST['contestant_id']; // This is user_id
        $pass = trim($_POST['password']);

        $conn->begin_transaction();
        try {
            // 1. Update User Table
            if (!empty($pass)) {
                $hashed_pass = password_hash($pass, PASSWORD_DEFAULT);
                $stmt1 = $conn->prepare("UPDATE users SET name=?, email=?, password=? WHERE id=?");
                $stmt1->bind_param("sssi", $name, $email, $hashed_pass, $id);
            } else {
                $stmt1 = $conn->prepare("UPDATE users SET name=?, email=? WHERE id=?");
                $stmt1->bind_param("ssi", $name, $email, $id);
            }
            $stmt1->execute();

            // 2. Handle Photo Update
            $photo_name = null;
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === 0) {
                $photo_name = uploadPhoto($_FILES['photo']);
                if ($photo_name) {
                    $stmt2 = $conn->prepare("UPDATE contestant_details SET event_id=?, age=?, height=?, vital_stats=?, hometown=?, motto=?, photo=? WHERE user_id=?");
                    $stmt2->bind_param("iisssssi", $event_id, $age, $height, $vital_stats, $hometown, $motto, $photo_name, $id);
                }
            }
            
            if (!$photo_name) {
                $stmt2 = $conn->prepare("UPDATE contestant_details SET event_id=?, age=?, height=?, vital_stats=?, hometown=?, motto=? WHERE user_id=?");
                $stmt2->bind_param("iissssi", $event_id, $age, $height, $vital_stats, $hometown, $motto, $id);
            }
            
            $stmt2->execute();
            $conn->commit();
            header("Location: ../public/contestants.php?success=Contestant updated");

        } catch (Exception $e) {
            $conn->rollback();
            header("Location: ../public/contestants.php?error=Update Failed");
        }
    }
    exit();
}

// Helper Function
function uploadPhoto($file) {
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png'];
    if (in_array($ext, $allowed)) {
        $new_name = "contestant_" . time() . "." . $ext;
        $target = __DIR__ . '/../public/assets/uploads/contestants/' . $new_name;
        if (move_uploaded_file($file['tmp_name'], $target)) {
            return $new_name;
        }
    }
    return false;
}

// --- HANDLE GET ACTIONS (Approve / Reject / Remove / Restore) ---
if (isset($_GET['action']) && isset($_GET['id'])) {
    
    $id = (int)$_GET['id'];
    $action = $_GET['action'];
    $my_id = $_SESSION['user_id'];

    // 1. Security Check
    $check_auth = $conn->prepare("
        SELECT u.id 
        FROM users u
        JOIN contestant_details cd ON u.id = cd.user_id
        JOIN events e ON cd.event_id = e.id
        WHERE u.id = ? AND e.user_id = ?
    ");
    $check_auth->bind_param("ii", $id, $my_id);
    $check_auth->execute();
    
    if ($check_auth->get_result()->num_rows === 0) {
        header("Location: ../public/contestants.php?error=Unauthorized Action");
        exit();
    }

    // 2. Determine New Status
    $new_status = '';
    $msg = '';

    if ($action === 'approve') {
        $new_status = 'Active';
        $msg = 'Application Approved';
    } elseif ($action === 'reject') {
        $new_status = 'Rejected';
        $msg = 'Application Rejected';
    } elseif ($action === 'remove') {
        $new_status = 'Inactive';
        $msg = 'Contestant Removed';
    } elseif ($action === 'restore') {
        $new_status = 'Active';
        $msg = 'Contestant Restored';
    }

    // 3. Execute Update
    if ($new_status) {
        $stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $new_status, $id);
        
        if ($stmt->execute()) {

            // EMAIL LOGIC FOR APPROVAL/REJECTION (Self-Registered)
            if ($action === 'approve' || $action === 'reject') {
                require_once __DIR__ . '/../app/core/CustomMailer.php';
                
                // CHANGE THIS to your live domain
                $site_link = "http://YOUR-SUBDOMAIN.rf.gd/bpms/public/index.php"; 

                $u_query = $conn->query("SELECT name, email FROM users WHERE id = $id");
                $u_data  = $u_query->fetch_assoc();
                
                if ($u_data) {
                    $to_email = $u_data['email'];
                    $to_name  = $u_data['name'];
                    
                    if ($action === 'approve') {
                        $subject = "Application Status: ACCEPTED";
                        $body = "<h2>Congratulations, $to_name!</h2><p>Your application has been <b>ACCEPTED</b>.</p><p><a href='$site_link'>Login to Dashboard</a></p>";
                        sendCustomEmail($to_email, $subject, $body);
                    } 
                    elseif ($action === 'reject') {
                        $subject = "Application Status Update";
                        $body = "<h2>Hello $to_name,</h2><p>Unfortunately, your application was <b>NOT ACCEPTED</b>.</p>";
                        sendCustomEmail($to_email, $subject, $body);
                    }
                }
            }

            // REDIRECT LOGIC
            $tab = 'active';
            if ($action === 'approve' || $action === 'reject') {
                $tab = 'pending';
            }
            
            header("Location: ../public/contestants.php?view=$tab&success=" . urlencode($msg));
        } else {
            header("Location: ../public/contestants.php?error=Action Failed");
        }
    }
    exit();
}
?>