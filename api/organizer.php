<?php
require_once __DIR__ . '/../app/core/guard.php';
requireLogin();
requireRole('Event Manager');
require_once __DIR__ . '/../app/config/database.php';

// --- 1. ADD or RESTORE ORGANIZER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    
    $event_id = (int)$_POST['event_id'];
    $name     = trim($_POST['name']);
    $email    = trim($_POST['email']);
    $phone    = trim($_POST['phone']);
    $role     = trim($_POST['role']);
    $pass     = trim($_POST['password']);
    
    $allowed_roles = ['Judge Coordinator', 'Contestant Manager', 'Tabulator'];
    if (!in_array($role, $allowed_roles)) {
        header("Location: ../public/organizers.php?error=Invalid Role selected");
        exit();
    }

    $conn->begin_transaction();
    try {
        // [SECURITY] Check if this email belongs to an Event Manager
        $checkAdmin = $conn->prepare("SELECT id, role FROM users WHERE email = ?");
        $checkAdmin->bind_param("s", $email);
        $checkAdmin->execute();
        $adminRes = $checkAdmin->get_result();

        $is_new_account = false; // Flag to track if we should send password

        if ($adminRes->num_rows > 0) {
            // --- EXISTING USER CASE ---
            $existingUser = $adminRes->fetch_assoc();
            if ($existingUser['role'] === 'Event Manager') {
                throw new Exception("Security Alert: You cannot assign the Event Manager account as an Organizer.");
            }
            // Use existing user
            $user_id = $existingUser['id'];
            $is_new_account = false;
            
        } else {
            // --- NEW USER CASE ---
            if (empty($pass)) { throw new Exception("Password required for new accounts"); }
            $hashed_pass = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (created_by, name, email, phone, role, password, status) VALUES (?, ?, ?, ?, ?, ?, 'Active')");
            $creator = $_SESSION['user_id'];
            $stmt->bind_param("isssss", $creator, $name, $email, $phone, $role, $hashed_pass);
            $stmt->execute();
            $user_id = $conn->insert_id;
            $is_new_account = true;
        }

        // Check/Create Link
        $linkCheck = $conn->prepare("SELECT id FROM event_organizers WHERE event_id = ? AND user_id = ?");
        $linkCheck->bind_param("ii", $event_id, $user_id);
        $linkCheck->execute();
        $linkRes = $linkCheck->get_result();
        
        if ($linkRes->num_rows > 0) {
            // --- RESTORE EXISTING LINK ---
            $link_id = $linkRes->fetch_assoc()['id'];
            // [UPDATED] Ensure is_deleted is set to 0 when restoring
            $restore = $conn->prepare("UPDATE event_organizers SET status='Active', is_deleted=0 WHERE id=?");
            $restore->bind_param("i", $link_id);
            $restore->execute();
            $msg = "Organizer restored to this event";
        } else {
            // --- CREATE NEW LINK ---
            // [UPDATED] Explicitly set is_deleted to 0
            $insert = $conn->prepare("INSERT INTO event_organizers (event_id, user_id, status, is_deleted) VALUES (?, ?, 'Active', 0)");
            $insert->bind_param("ii", $event_id, $user_id);
            $insert->execute();
            $msg = "Organizer added successfully";
        }

        // =============================================================
        //  SEND EMAIL NOTIFICATION (Using CustomMailer)
        // =============================================================
        require_once __DIR__ . '/../app/core/CustomMailer.php';

        // 1. Website Link
        $site_link = "http://YOUR-SUBDOMAIN.rf.gd/bpms/public/index.php";

        // 2. Get Event Name
        $evt_name = "the Event";
        $e_query = $conn->query("SELECT name FROM events WHERE id = $event_id");
        if ($row = $e_query->fetch_assoc()) {
            $evt_name = $row['name'];
        }

        // 3. Build Email Content
        $subject = "Team Assignment: $role for $evt_name";
        
        if ($is_new_account) {
            // Message for NEW Accounts (Include Password)
            $body = "
                <h2>Welcome, $name!</h2>
                <p>You have been assigned as a <b>$role</b> for <b>$evt_name</b>.</p>
                
                <div style='background:#f3f4f6; padding:15px; border-radius:8px; border:1px solid #ddd; margin:20px 0;'>
                    <strong>Your Login Credentials:</strong><br>
                    Email: <b>$email</b><br>
                    Password: <b>$pass</b>
                </div>

                <p>Please login to your dashboard to start managing your tasks:</p>
                <p><a href='$site_link' style='background:#F59E0B; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>Login to Dashboard</a></p>
            ";
        } else {
            // Message for EXISTING Accounts (No Password shown)
            $body = "
                <h2>Welcome back, $name!</h2>
                <p>You have been assigned a new role as <b>$role</b> for the event: <b>$evt_name</b>.</p>
                <p>Since you already have an account, please login using your existing credentials.</p>
                <p><a href='$site_link' style='background:#F59E0B; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>Login to Dashboard</a></p>
            ";
        }

        // 4. Send
        sendCustomEmail($email, $subject, $body);
        // =============================================================

        $conn->commit();
        header("Location: ../public/organizers.php?success=" . urlencode($msg));

    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        // Handle Duplicate Entry specifically
        if ($e->getCode() == 1062) {
            header("Location: ../public/organizers.php?error=Email address is already in use by another user.");
        } else {
            header("Location: ../public/organizers.php?error=Database Error: " . urlencode($e->getMessage()));
        }
    } catch (Exception $e) {
        $conn->rollback();
        header("Location: ../public/organizers.php?error=" . urlencode($e->getMessage()));
    }
    exit();
}

// --- 2. UPDATE ORGANIZER (Edit) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    
    $user_id = (int)$_POST['org_id'];
    $name  = trim($_POST['name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $role  = trim($_POST['role']);
    $pass  = trim($_POST['password']);

    try {
        // [CHECK 1] Security: Don't edit Event Managers
        $checkRole = $conn->query("SELECT role FROM users WHERE id = $user_id");
        if ($checkRole->fetch_assoc()['role'] === 'Event Manager') {
             throw new Exception("Cannot edit Event Manager accounts here.");
        }

        // [CHECK 2] Duplicate Email Check
        $dupCheck = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $dupCheck->bind_param("si", $email, $user_id);
        $dupCheck->execute();
        if ($dupCheck->get_result()->num_rows > 0) {
            throw new Exception("This email is already used by another account.");
        }

        // Update User
        if (!empty($pass)) {
            $hashed_pass = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET name=?, email=?, phone=?, role=?, password=? WHERE id=?");
            $stmt->bind_param("sssssi", $name, $email, $phone, $role, $hashed_pass, $user_id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET name=?, email=?, phone=?, role=? WHERE id=?");
            $stmt->bind_param("ssssi", $name, $email, $phone, $role, $user_id);
        }

        $stmt->execute();
        header("Location: ../public/organizers.php?success=Organizer updated");

    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() == 1062) {
             header("Location: ../public/organizers.php?error=Email is already taken.");
        } else {
             header("Location: ../public/organizers.php?error=Database error.");
        }
    } catch (Exception $e) {
        header("Location: ../public/organizers.php?error=" . urlencode($e->getMessage()));
    }
    exit();
}

// --- 3. REMOVE / RESTORE / DELETE ---
if (isset($_GET['action']) && isset($_GET['id'])) {
    $link_id = (int)$_GET['id'];
    $type = $_GET['action'];

    if ($type === 'delete') {
        // --- SOFT DELETE ---
        // Hide from UI completely (is_deleted = 1)
        $stmt = $conn->prepare("UPDATE event_organizers SET is_deleted = 1 WHERE id = ?");
        $stmt->bind_param("i", $link_id);
        
        $redirect_view = 'archived'; // Stay in archive view
        $msg = "Organizer removed permanently from list.";

    } elseif ($type === 'restore') {
        // --- RESTORE ---
        // Make Active AND visible (is_deleted = 0)
        $stmt = $conn->prepare("UPDATE event_organizers SET status = 'Active', is_deleted = 0 WHERE id = ?");
        $stmt->bind_param("i", $link_id);
        
        $redirect_view = 'archived'; // Stay in archive view to see it vanish (or go to active if preferred)
        $msg = "Organizer restored successfully.";

    } else {
        // --- ARCHIVE (Default 'remove') ---
        // Just set status to Inactive
        $stmt = $conn->prepare("UPDATE event_organizers SET status = 'Inactive' WHERE id = ?");
        $stmt->bind_param("i", $link_id);
        
        $redirect_view = 'active'; // Stay in active view
        $msg = "Organizer moved to archive.";
    }

    if ($stmt->execute()) {
        header("Location: ../public/organizers.php?view=$redirect_view&success=" . urlencode($msg));
    } else {
        header("Location: ../public/organizers.php?error=Action failed");
    }
    exit();
}
?>