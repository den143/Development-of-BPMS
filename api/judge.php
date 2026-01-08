<?php
require_once __DIR__ . '/../app/core/guard.php';
requireLogin();
requireRole('Event Manager');
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/core/flash.php';
require_once __DIR__ . '/../app/core/csrf.php';

// Helper to ensure only 1 Chairman exists per event
function resetChairman($conn, $event_id) {
    $stmt = $conn->prepare("UPDATE event_judges SET is_chairman = 0 WHERE event_id = ?");
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
}

// --- 1. ADD or RESTORE JUDGE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    
    // CSRF Check
    $token = $_POST['csrf_token'] ?? '';
    if (!Csrf::verifyToken($token)) {
        Flash::set('error', 'Security token mismatch. Please try again.');
        header("Location: ../public/judges.php");
        exit();
    }

    $event_id = (int)$_POST['event_id'];
    $name     = trim($_POST['name']);
    $email    = trim($_POST['email']);
    $pass     = trim($_POST['password']);
    $is_chairman = isset($_POST['is_chairman']) ? 1 : 0;

    if (empty($name) || empty($email) || empty($pass)) {
        Flash::set('error', 'All fields are required');
        header("Location: ../public/judges.php");
        exit();
    }

    $conn->begin_transaction();
    try {
        // [FIX] If setting as Chairman, reset others first
        if ($is_chairman) {
            resetChairman($conn, $event_id);
        }

        // A. Check/Create User
        $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $check->bind_param("s", $email);
        $check->execute();
        $res = $check->get_result();
        
        $is_new_account = false;

        if ($res->num_rows > 0) {
            $judge_id = $res->fetch_assoc()['id'];
        } else {
            $hashed_pass = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (created_by, name, email, password, role, status) VALUES (?, ?, ?, ?, 'Judge', 'Active')");
            $creator = $_SESSION['user_id'];
            $stmt->bind_param("isss", $creator, $name, $email, $hashed_pass);
            $stmt->execute();
            $judge_id = $conn->insert_id;
            $is_new_account = true;
        }

        // B. Check Existing Link (Soft Deleted?)
        $linkCheck = $conn->prepare("SELECT id FROM event_judges WHERE event_id = ? AND judge_id = ?");
        $linkCheck->bind_param("ii", $event_id, $judge_id);
        $linkCheck->execute();
        $linkRes = $linkCheck->get_result();
        
        if ($linkRes->num_rows > 0) {
            // RESTORE existing link (Set Active + Not Deleted)
            $link_id = $linkRes->fetch_assoc()['id'];
            $update = $conn->prepare("UPDATE event_judges SET status='Active', is_deleted=0, is_chairman=? WHERE id=?");
            $update->bind_param("ii", $is_chairman, $link_id);
            $update->execute();
            $msg = "Judge restored successfully";
        } else {
            // CREATE new link (Explicitly is_deleted=0)
            $insert = $conn->prepare("INSERT INTO event_judges (event_id, judge_id, is_chairman, status, is_deleted) VALUES (?, ?, ?, 'Active', 0)");
            $insert->bind_param("iii", $event_id, $judge_id, $is_chairman);
            $insert->execute();
            $msg = "Judge added successfully";
        }

        // =============================================================
        //  SEND EMAIL INVITE (Using CustomMailer)
        // =============================================================
        require_once __DIR__ . '/../app/core/CustomMailer.php';

        // 1. Website Link
        $site_link = "http://" . $_SERVER['HTTP_HOST'] . "/bpms/public/index.php";

        // 2. Get Event Name for the email
        $evt_name = "the Pageant";
        $stmt_e = $conn->prepare("SELECT name FROM events WHERE id = ?");
        $stmt_e->bind_param("i", $event_id);
        $stmt_e->execute();
        if ($row = $stmt_e->get_result()->fetch_assoc()) {
            $evt_name = $row['name'];
        }

        // 3. Prepare Email
        $subject = "Official Invitation: Judge for " . htmlspecialchars($evt_name);
        
        if ($is_new_account) {
            $body = "
                <h2>Hello, " . htmlspecialchars($name) . "!</h2>
                <p>You have been selected to serve as an <b>Official Judge</b> for <b>" . htmlspecialchars($evt_name) . "</b>.</p>
                
                <div style='background:#f3f4f6; padding:15px; border-radius:8px; border:1px solid #ddd; margin:20px 0;'>
                    <strong>Your Login Credentials:</strong><br>
                    Email: <b>" . htmlspecialchars($email) . "</b><br>
                    Password: <b>" . htmlspecialchars($pass) . "</b>
                </div>

                <p>Please login to the Judge's Dashboard to view the scoring criteria and candidates:</p>
                <p><a href='$site_link' style='background:#F59E0B; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>Open Judge Dashboard</a></p>
            ";
        } else {
            $body = "
                <h2>Hello, " . htmlspecialchars($name) . "!</h2>
                <p>You have been selected to serve as an <b>Official Judge</b> for <b>" . htmlspecialchars($evt_name) . "</b>.</p>
                <p>Please login using your existing credentials.</p>
                <p><a href='$site_link' style='background:#F59E0B; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>Open Judge Dashboard</a></p>
            ";
        }

        // 4. Send
        sendCustomEmail($email, $subject, $body);
        // =============================================================

        $conn->commit();
        Flash::set('success', $msg);
        header("Location: ../public/judges.php");

    } catch (Exception $e) {
        $conn->rollback();
        Flash::set('error', 'Database Error');
        header("Location: ../public/judges.php");
    }
    exit();
}

// --- 2. UPDATE JUDGE (Edit) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    
    // CSRF Check
    $token = $_POST['csrf_token'] ?? '';
    if (!Csrf::verifyToken($token)) {
        Flash::set('error', 'Security token mismatch. Please try again.');
        header("Location: ../public/judges.php");
        exit();
    }

    $link_id  = (int)$_POST['link_id'];
    $judge_id = (int)$_POST['judge_id']; // User ID
    $name     = trim($_POST['name']);
    $email    = trim($_POST['email']);
    $pass     = trim($_POST['password']);
    $is_chairman = isset($_POST['is_chairman']) ? 1 : 0;
    
    // We need event_id to reset chairman, let's fetch it from the link_id
    $stmt_evt = $conn->prepare("SELECT event_id FROM event_judges WHERE id = ?");
    $stmt_evt->bind_param("i", $link_id);
    $stmt_evt->execute();
    $evtCheck = $stmt_evt->get_result();

    if ($evtCheck->num_rows === 0) {
        Flash::set('error', 'Invalid Judge ID');
        header("Location: ../public/judges.php");
        exit();
    }

    $event_id = $evtCheck->fetch_assoc()['event_id'];

    $conn->begin_transaction();
    try {
        // [FIX] If setting as Chairman, reset others first
        if ($is_chairman) {
            resetChairman($conn, $event_id);
        }

        // Update Link (Chairman status)
        $stmt1 = $conn->prepare("UPDATE event_judges SET is_chairman = ? WHERE id = ?");
        $stmt1->bind_param("ii", $is_chairman, $link_id);
        $stmt1->execute();

        // Update User Details (Name, Email, Password)
        if (!empty($pass)) {
            $hashed = password_hash($pass, PASSWORD_DEFAULT);
            $stmt2 = $conn->prepare("UPDATE users SET name = ?, email = ?, password = ? WHERE id = ?");
            $stmt2->bind_param("sssi", $name, $email, $hashed, $judge_id);
        } else {
            $stmt2 = $conn->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
            $stmt2->bind_param("ssi", $name, $email, $judge_id);
        }
        $stmt2->execute();

        $conn->commit();
        Flash::set('success', 'Judge updated successfully');
        header("Location: ../public/judges.php");

    } catch (Exception $e) {
        $conn->rollback();
        Flash::set('error', 'Update failed');
        header("Location: ../public/judges.php");
    }
    exit();
}

// --- 3. REMOVE / RESTORE / DELETE (SECURED) ---
// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['id'])) {
    
    // CSRF Check (Assuming forms in UI will be updated)
    // For now, if no token, we might break functionality if the frontend isn't updated simultaneously.
    // However, I will update frontend later. So I enforce it here.

    // Note: The UI for table actions usually uses Links/GET. If I switch to POST here, I MUST update the UI.
    // The previous `api/contestant.php` used GET for these.
    // This file `api/judge.php` seems to have been using POST for these actions already?
    // Let's check the original code... Yes, "Only accept POST requests" was in the comment, but `contestant.php` used GET.
    // Wait, the original `judge.php` checked `$_SERVER['REQUEST_METHOD'] === 'POST'`.
    // So `judges.php` likely has forms for these buttons. Good.

    $token = $_POST['csrf_token'] ?? '';
    if (!Csrf::verifyToken($token)) {
        Flash::set('error', 'Security token mismatch.');
        header("Location: ../public/judges.php");
        exit();
    }

    $link_id = (int)$_POST['id'];
    $action  = $_POST['action'];

    if ($action === 'delete') {
        // --- SOFT DELETE: Hide from UI ---
        $stmt = $conn->prepare("UPDATE event_judges SET is_deleted = 1 WHERE id = ?");
        $stmt->bind_param("i", $link_id);
        $view = 'archived';
        $msg = "Judge permanently removed from list.";

    } elseif ($action === 'restore') {
        // --- RESTORE: Set Active & Visible ---
        $stmt = $conn->prepare("UPDATE event_judges SET status = 'Active', is_deleted = 0 WHERE id = ?");
        $stmt->bind_param("i", $link_id);
        $view = 'archived';
        $msg = "Judge restored successfully.";

    } else {
        // --- ARCHIVE (Default 'remove') ---
        $stmt = $conn->prepare("UPDATE event_judges SET status = 'Inactive' WHERE id = ?");
        $stmt->bind_param("i", $link_id);
        $view = 'active';
        $msg = "Judge archived.";
    }
    
    if ($stmt->execute()) {
        Flash::set('success', $msg);
        header("Location: ../public/judges.php?view=$view");
    } else {
        Flash::set('error', 'Action failed');
        header("Location: ../public/judges.php");
    }
    exit();
}
