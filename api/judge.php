<?php
require_once __DIR__ . '/../app/core/guard.php';
requireLogin();
requireRole('Event Manager');
require_once __DIR__ . '/../app/config/database.php';

// Helper to ensure only 1 Chairman exists per event
function resetChairman($conn, $event_id) {
    $stmt = $conn->prepare("UPDATE event_judges SET is_chairman = 0 WHERE event_id = ?");
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
}

// --- 1. ADD or RESTORE JUDGE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    
    $event_id = (int)$_POST['event_id'];
    $name     = trim($_POST['name']);
    $email    = trim($_POST['email']);
    $pass     = trim($_POST['password']);
    $is_chairman = isset($_POST['is_chairman']) ? 1 : 0;

    if (empty($name) || empty($email) || empty($pass)) {
        header("Location: ../public/judges.php?error=All fields are required");
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
        
        if ($res->num_rows > 0) {
            $judge_id = $res->fetch_assoc()['id'];
        } else {
            $hashed_pass = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (created_by, name, email, password, role, status) VALUES (?, ?, ?, ?, 'Judge', 'Active')");
            $creator = $_SESSION['user_id'];
            $stmt->bind_param("isss", $creator, $name, $email, $hashed_pass);
            $stmt->execute();
            $judge_id = $conn->insert_id;
        }

        // B. Check Existing Link (Soft Deleted?)
        $linkCheck = $conn->prepare("SELECT id FROM event_judges WHERE event_id = ? AND judge_id = ?");
        $linkCheck->bind_param("ii", $event_id, $judge_id);
        $linkCheck->execute();
        $linkRes = $linkCheck->get_result();
        
        if ($linkRes->num_rows > 0) {
            // RESTORE existing link
            $link_id = $linkRes->fetch_assoc()['id'];
            $update = $conn->prepare("UPDATE event_judges SET status='Active', is_chairman=? WHERE id=?");
            $update->bind_param("ii", $is_chairman, $link_id);
            $update->execute();
            $msg = "Judge restored successfully";
        } else {
            // CREATE new link
            $insert = $conn->prepare("INSERT INTO event_judges (event_id, judge_id, is_chairman, status) VALUES (?, ?, ?, 'Active')");
            $insert->bind_param("iii", $event_id, $judge_id, $is_chairman);
            $insert->execute();
            $msg = "Judge added successfully";
        }

        $conn->commit();
        header("Location: ../public/judges.php?success=" . urlencode($msg));

    } catch (Exception $e) {
        $conn->rollback();
        header("Location: ../public/judges.php?error=Database Error");
    }
    exit();
}

// --- 2. UPDATE JUDGE (Edit) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    
    $link_id  = (int)$_POST['link_id'];
    $judge_id = (int)$_POST['judge_id']; // User ID
    $name     = trim($_POST['name']);
    $email    = trim($_POST['email']);
    $pass     = trim($_POST['password']);
    $is_chairman = isset($_POST['is_chairman']) ? 1 : 0;
    
    // We need event_id to reset chairman, let's fetch it from the link_id
    $evtCheck = $conn->query("SELECT event_id FROM event_judges WHERE id = $link_id");
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
        header("Location: ../public/judges.php?success=Judge updated successfully");

    } catch (Exception $e) {
        $conn->rollback();
        header("Location: ../public/judges.php?error=Update failed");
    }
    exit();
}

// --- 3. REMOVE (Soft Delete) or RESTORE ---
if (isset($_GET['action']) && isset($_GET['id'])) {
    $link_id = (int)$_GET['id'];
    $action  = $_GET['action']; 

    $new_status = ($action === 'restore') ? 'Active' : 'Inactive';
    
    // If removing, we don't need to change chairman status (it just becomes an inactive chairman)
    // If restoring, we might want to ensure they don't come back as a 2nd chairman, but for now let's keep it simple.
    
    $stmt = $conn->prepare("UPDATE event_judges SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $new_status, $link_id);
    
    if ($stmt->execute()) {
        $view = ($action === 'remove') ? 'active' : 'archived'; 
        header("Location: ../public/judges.php?view=$view&success=Judge status updated");
    } else {
        header("Location: ../public/judges.php?error=Action failed");
    }
    exit();
}