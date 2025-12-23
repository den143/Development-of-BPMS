<?php
require_once __DIR__ . '/../app/core/guard.php';
requireLogin();
requireRole(['Event Manager', 'Contestant Manager']); // Allow both to manage
require_once __DIR__ . '/../app/config/database.php';

// --- HANDLE POST REQUESTS (Manual Add) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. Capture Inputs
    $event_id = (int)$_POST['event_id'];
    $name     = trim($_POST['name']);
    $email    = trim($_POST['email']);
    $pass     = trim($_POST['password']); // Can be empty if we generate a default one? Let's require it.
    
    // Details
    $age         = (int)$_POST['age'];
    $height      = trim($_POST['height']);
    $vital_stats = trim($_POST['vital_stats']);
    $hometown    = trim($_POST['hometown']);
    $motto       = trim($_POST['motto']);

    if (empty($name) || empty($email) || empty($pass) || empty($event_id)) {
        header("Location: ../public/contestants.php?error=All fields are required");
        exit();
    }

    // 2. Check Email
    $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $check->bind_param("s", $email);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        header("Location: ../public/contestants.php?error=Email already exists");
        exit();
    }

    // 3. Handle Photo (Same logic as register, but simpler error handling for admin)
    $photo_name = "default_contestant.png";
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === 0) {
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png'];
        if (in_array($ext, $allowed)) {
            $new_name = "contestant_" . time() . "." . $ext;
            $target = __DIR__ . '/../public/assets/uploads/contestants/' . $new_name;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $target)) {
                $photo_name = $new_name;
            }
        }
    }

    // 4. Insert Transaction
    $conn->begin_transaction();
    try {
        $hashed_pass = password_hash($pass, PASSWORD_DEFAULT);
        
        // Manual Add = ACTIVE immediately
        $stmt1 = $conn->prepare("INSERT INTO users (name, email, password, role, status) VALUES (?, ?, ?, 'Contestant', 'Active')");
        $stmt1->bind_param("sss", $name, $email, $hashed_pass);
        $stmt1->execute();
        $user_id = $conn->insert_id;

        $stmt2 = $conn->prepare("INSERT INTO contestant_details (user_id, event_id, age, height, vital_stats, hometown, motto, photo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt2->bind_param("iiisssss", $user_id, $event_id, $age, $height, $vital_stats, $hometown, $motto, $photo_name);
        $stmt2->execute();

        $conn->commit();
        header("Location: ../public/contestants.php?success=Contestant added successfully");

    } catch (Exception $e) {
        $conn->rollback();
        header("Location: ../public/contestants.php?error=Database Error");
    }
    exit();
}

// --- HANDLE GET ACTIONS (Approve / Reject / Remove) ---
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $action = $_GET['action'];
    $my_id = $_SESSION['user_id'];

    // We verify that the contestant belongs to an EVENT owned by THIS manager
    // This prevents managers from deleting other managers' contestants
    // SQL: Check if users.id (contestant) -> contestant_details.event_id -> events.user_id == me
    
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

    // Perform Action
    $new_status = '';
    $msg = '';

    if ($action === 'approve') {
        $new_status = 'Active';
        $msg = 'Application Approved';
    } elseif ($action === 'reject') {
        $new_status = 'Rejected';
        $msg = 'Application Rejected';
    } elseif ($action === 'remove') {
        $new_status = 'Inactive'; // Soft Delete
        $msg = 'Contestant Removed';
    }

    if ($new_status) {
        $stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $new_status, $id);
        if ($stmt->execute()) {
            // Redirect back to the correct tab
            $tab = ($action === 'approve' || $action === 'reject') ? 'pending' : 'active';
            header("Location: ../public/contestants.php?view=$tab&success=" . urlencode($msg));
        } else {
            header("Location: ../public/contestants.php?error=Action Failed");
        }
    }
    exit();
}