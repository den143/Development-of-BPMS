<?php
// Enable Error Reporting for debugging (Remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../app/core/guard.php';
requireLogin();
requireRole('Event Manager');
require_once __DIR__ . '/../app/config/database.php';

// HELPER: Check if Round is Locked (Active or Completed)
// Prevents editing configuration while the round is live.
function checkRoundLock($conn, $round_id) {
    $stmt = $conn->prepare("SELECT status, title FROM rounds WHERE id = ?");
    $stmt->bind_param("i", $round_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    
    if ($res && ($res['status'] === 'Active' || $res['status'] === 'Completed')) {
        // Redirect back with specific error
        $status = $res['status'];
        $title = $res['title'];
        header("Location: ../public/criteria.php?round_id=$round_id&error=Action Denied: You cannot modify '$title' because it is currently $status.");
        exit();
    }
}

// --- 1. SEGMENT ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_segment') {
    
    $round_id = (int)$_POST['round_id'];
    
    // [SECURITY] Run Lock Check
    checkRoundLock($conn, $round_id);

    $title    = trim($_POST['title']);
    $desc     = trim($_POST['description']);
    $weight   = $_POST['weight_percentage']; 
    $order    = (int)$_POST['ordering'];

    // CHECK: Prepare Statement
    $stmt = $conn->prepare("INSERT INTO segments (round_id, title, description, weight_percentage, ordering) VALUES (?, ?, ?, ?, ?)");
    
    if (!$stmt) {
        die("Database Error: " . $conn->error);
    }

    $stmt->bind_param("issdi", $round_id, $title, $desc, $weight, $order);

    if ($stmt->execute()) {
        header("Location: ../public/criteria.php?round_id=$round_id&success=Segment added");
    } else {
        header("Location: ../public/criteria.php?round_id=$round_id&error=Failed to add segment: " . $stmt->error);
    }
    exit();
}

// --- 2. DELETE SEGMENT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_segment') {
    $id = (int)$_POST['segment_id'];
    $r_id = (int)$_POST['round_id'];
    
    // [SECURITY] Run Lock Check
    checkRoundLock($conn, $r_id);

    // [SAFETY] Check if judges have started scoring this segment
    $check = $conn->prepare("
        SELECT s.id FROM scores s 
        JOIN criteria c ON s.criteria_id = c.id 
        WHERE c.segment_id = ? 
        LIMIT 1
    ");
    $check->bind_param("i", $id);
    $check->execute();

    if ($check->get_result()->num_rows > 0) {
        header("Location: ../public/criteria.php?round_id=$r_id&error=Cannot delete: Judges have already started scoring this segment.");
        exit();
    }

    $conn->query("DELETE FROM segments WHERE id = $id");
    header("Location: ../public/criteria.php?round_id=$r_id&success=Segment deleted");
    exit();
}


// --- 3. CRITERIA ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_criteria') {
    
    $segment_id = (int)$_POST['segment_id'];
    $r_id       = (int)$_POST['round_id'];

    // [SECURITY] Run Lock Check
    checkRoundLock($conn, $r_id);

    $title      = trim($_POST['title']);
    $desc       = trim($_POST['description']); 
    $max_score  = $_POST['max_score'];
    $order      = (int)$_POST['ordering'];

    // CHECK: Prepare Statement
    $stmt = $conn->prepare("INSERT INTO criteria (segment_id, title, description, max_score, ordering) VALUES (?, ?, ?, ?, ?)");
    
    if (!$stmt) {
        die("Database Error: " . $conn->error);
    }

    $stmt->bind_param("issdi", $segment_id, $title, $desc, $max_score, $order);

    if ($stmt->execute()) {
        header("Location: ../public/criteria.php?round_id=$r_id&success=Criteria added");
    } else {
        header("Location: ../public/criteria.php?round_id=$r_id&error=Failed to add criteria: " . $stmt->error);
    }
    exit();
}

// --- 4. DELETE CRITERIA ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_criteria') {
    $id = (int)$_POST['criteria_id'];
    $r_id = (int)$_POST['round_id'];
    
    // [SECURITY] Run Lock Check
    checkRoundLock($conn, $r_id);

    // [SAFETY] Check if judges have scored this specific criteria
    $check = $conn->prepare("SELECT id FROM scores WHERE criteria_id = ? LIMIT 1");
    $check->bind_param("i", $id);
    $check->execute();

    if ($check->get_result()->num_rows > 0) {
        header("Location: ../public/criteria.php?round_id=$r_id&error=Cannot delete: Judges have already submitted scores for this criteria.");
        exit();
    }
    
    $conn->query("DELETE FROM criteria WHERE id = $id");
    header("Location: ../public/criteria.php?round_id=$r_id&success=Criteria deleted");
    exit();
}
?>