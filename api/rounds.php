<?php
require_once __DIR__ . '/../app/core/guard.php';
requireLogin();
requireRole('Event Manager');
require_once __DIR__ . '/../app/config/database.php';

// HELPER: Validate "Funnel Logic"
function validateAdvancement($conn, $event_id, $current_order, $current_top_n) {
    // 1. Basic Sanity Check
    if ($current_top_n < 1) {
        return "Invalid Number: You must advance at least 1 contestant.";
    }

    // 2. Find the round immediately before this one
    $prev_order = $current_order - 1;
    if ($prev_order < 1) return true; 

    $stmt = $conn->prepare("SELECT contestants_to_advance, advancement_rule, title FROM rounds WHERE event_id = ? AND ordering = ?");
    $stmt->bind_param("ii", $event_id, $prev_order);
    $stmt->execute();
    $prev = $stmt->get_result()->fetch_assoc();

    if ($prev) {
        $prev_n = (int)$prev['contestants_to_advance'];
        
        // BLOCKER: If previous round was "Winner", you can't add more rounds!
        if ($prev['advancement_rule'] === 'winner') {
            return "Action Denied: The previous round '{$prev['title']}' already declared a Final Winner. You cannot add a round after the Finals.";
        }

        // Standard Funnel: New Top N must be strictly less than previous
        if ($current_top_n >= $prev_n) {
            return "Invalid Configuration: The number of winners ($current_top_n) must be LESS than the previous round '{$prev['title']}' ($prev_n).";
        }
    }
    return true;
}

// --- 1. ADD ROUND ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    
    $event_id = (int)$_POST['event_id'];
    $title    = trim($_POST['title']);
    $order    = (int)$_POST['ordering'];
    $rule     = $_POST['advancement_rule'];
    $advance  = (int)$_POST['contestants_to_advance'];

    if ($rule === 'winner') {
        $advance = 1;
    }

    if (empty($title)) {
        header("Location: ../public/rounds.php?error=Title is required");
        exit();
    }

    // [FIXED] Run Validation
    if ($rule === 'top_n') {
        $check = validateAdvancement($conn, $event_id, $order, $advance);
        if ($check !== true) {
            header("Location: ../public/rounds.php?error=" . urlencode($check));
            exit();
        }
    } 
    // Even if it is a 'winner' round, we check if the PREVIOUS round allows it
    else {
        // Just verify we aren't adding a winner round AFTER another winner round
        $check = validateAdvancement($conn, $event_id, $order, 1);
        if ($check !== true) {
            header("Location: ../public/rounds.php?error=" . urlencode($check));
            exit();
        }
    }

    // Prevent Duplicate Order
    $dupCheck = $conn->query("SELECT id FROM rounds WHERE event_id = $event_id AND ordering = $order");
    if ($dupCheck->num_rows > 0) {
        header("Location: ../public/rounds.php?error=Order #$order already exists.");
        exit();
    }

    $stmt = $conn->prepare("INSERT INTO rounds (event_id, title, ordering, advancement_rule, contestants_to_advance, status) VALUES (?, ?, ?, ?, ?, 'Pending')");
    $stmt->bind_param("isisi", $event_id, $title, $order, $rule, $advance);
    
    if ($stmt->execute()) {
        header("Location: ../public/rounds.php?success=Round added successfully");
    } else {
        header("Location: ../public/rounds.php?error=Failed to add round");
    }
    exit();
}

// --- 2. UPDATE ROUND ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    
    $round_id = (int)$_POST['round_id'];
    $evtCheck = $conn->query("SELECT event_id FROM rounds WHERE id = $round_id")->fetch_assoc();
    $event_id = $evtCheck['event_id'];

    $title    = trim($_POST['title']);
    $order    = (int)$_POST['ordering'];
    $rule     = $_POST['advancement_rule'];
    $advance  = (int)$_POST['contestants_to_advance'];

    if ($rule === 'winner') {
        $advance = 1;
    }

    // [FIXED] Run Validation on Update too
    $check = validateAdvancement($conn, $event_id, $order, $advance);
    if ($check !== true) {
        header("Location: ../public/rounds.php?error=" . urlencode($check));
        exit();
    }

    $stmt = $conn->prepare("UPDATE rounds SET title=?, ordering=?, advancement_rule=?, contestants_to_advance=? WHERE id=?");
    $stmt->bind_param("sisii", $title, $order, $rule, $advance, $round_id);

    if ($stmt->execute()) {
        header("Location: ../public/rounds.php?success=Round updated");
    } else {
        header("Location: ../public/rounds.php?error=Update failed");
    }
    exit();
}

// ... (Keep DELETE, SET ACTIVE, and STOP ROUND exactly as they were in the previous file) ...
// --- 3. DELETE ROUND (With Safety Check) ---
if (isset($_GET['action']) && $_GET['action'] === 'delete') {
    $id = (int)$_GET['id'];
    
    // A. Check if round is Active
    $check = $conn->query("SELECT status FROM rounds WHERE id = $id");
    if ($check->fetch_assoc()['status'] === 'Active') {
        header("Location: ../public/rounds.php?error=Cannot delete an Active round. Please deactivate it first.");
        exit();
    }

    // B. Check if Scores exist for this round
    $scoreCheck = $conn->prepare("SELECT id FROM scores WHERE round_id = ? LIMIT 1");
    $scoreCheck->bind_param("i", $id);
    $scoreCheck->execute();
    
    if ($scoreCheck->get_result()->num_rows > 0) {
        header("Location: ../public/rounds.php?error=Cannot delete: Scores have already been submitted for this round.");
        exit();
    }

    // C. Proceed with Delete
    $conn->query("DELETE FROM rounds WHERE id = $id");
    header("Location: ../public/rounds.php?success=Round deleted");
    exit();
}

// --- 4. SET ACTIVE ROUND ---
if (isset($_GET['action']) && $_GET['action'] === 'set_active') {
    $round_id = (int)$_GET['id'];
    $event_id = (int)$_GET['event_id'];

    $conn->begin_transaction();
    try {
        // A. Set ALL rounds for this event to 'Pending'
        $reset = $conn->prepare("UPDATE rounds SET status = 'Pending' WHERE event_id = ? AND status = 'Active'");
        $reset->bind_param("i", $event_id);
        $reset->execute();

        // B. Set SELECTED round to 'Active'
        $set = $conn->prepare("UPDATE rounds SET status = 'Active' WHERE id = ?");
        $set->bind_param("i", $round_id);
        $set->execute();

        $conn->commit();
        header("Location: ../public/rounds.php?success=Round Activated");
    } catch (Exception $e) {
        $conn->rollback();
        header("Location: ../public/rounds.php?error=Failed to activate round");
    }
    exit();
}

// --- 5. STOP ROUND (Emergency Stop) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'stop_round') {
    $round_id = (int)$_POST['round_id'];
    $password = $_POST['password'];
    $manager_id = $_SESSION['user_id'];

    // A. Verify Password
    $u_stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $u_stmt->bind_param("i", $manager_id);
    $u_stmt->execute();
    $user = $u_stmt->get_result()->fetch_assoc();

    if (!password_verify($password, $user['password'])) {
        header("Location: ../public/rounds.php?error=Incorrect Password. Cannot stop round.");
        exit();
    }

    // B. Check for Scores (Safety Lock)
    // "As long as judges did not enter even 1 score"
    $s_check = $conn->prepare("SELECT id FROM scores WHERE round_id = ? LIMIT 1");
    $s_check->bind_param("i", $round_id);
    $s_check->execute();
    if ($s_check->get_result()->num_rows > 0) {
        header("Location: ../public/rounds.php?error=CANNOT STOP: Judges have already submitted scores. Hard reset required by IT.");
        exit();
    }

    // C. Stop the Round (Set to Pending)
    $stmt = $conn->prepare("UPDATE rounds SET status = 'Pending' WHERE id = ?");
    $stmt->bind_param("i", $round_id);
    $stmt->execute();

    header("Location: ../public/rounds.php?success=Round stopped successfully. It is now Pending.");
    exit();
}
?>