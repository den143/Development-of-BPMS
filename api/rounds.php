<?php
require_once __DIR__ . '/../app/core/guard.php';
requireLogin();
requireRole('Event Manager');
require_once __DIR__ . '/../app/config/database.php';

// HELPER: Validate "Funnel Logic"
function validateAdvancement($conn, $event_id, $current_order, $current_top_n) {
    if ($current_top_n < 1) return "Invalid Number: You must advance at least 1 contestant.";

    $prev_order = $current_order - 1;
    if ($prev_order < 1) return true; 

    $stmt = $conn->prepare("SELECT contestants_to_advance, advancement_rule, title FROM rounds WHERE event_id = ? AND ordering = ?");
    $stmt->bind_param("ii", $event_id, $prev_order);
    $stmt->execute();
    $prev = $stmt->get_result()->fetch_assoc();

    if ($prev) {
        $prev_n = (int)$prev['contestants_to_advance'];
        
        if ($prev['advancement_rule'] === 'winner') {
            return "Action Denied: The previous round '{$prev['title']}' already declared a Final Winner.";
        }
        if ($current_top_n >= $prev_n) {
            return "Invalid Configuration: Winners ($current_top_n) must be LESS than previous round ($prev_n).";
        }
    }
    return true;
}

// [NEW] HELPER: Strict Configuration Check
function validateRoundConfiguration($conn, $round_id) {
    // 1. Check if Segments sum to 100%
    $stmt = $conn->prepare("SELECT SUM(weight_percentage) as total FROM segments WHERE round_id = ?");
    $stmt->bind_param("i", $round_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $seg_total = (float)($res['total'] ?? 0);

    if ($seg_total !== 100.00) {
        return "Cannot Start: Total Segment Weight is $seg_total%. It must be exactly 100%.";
    }

    // 2. Check if EACH Segment has 100 Points of Criteria
    // We find any segment that DOES NOT sum to 100
    $sql = "
        SELECT s.title, SUM(c.max_score) as criteria_total 
        FROM segments s 
        LEFT JOIN criteria c ON s.id = c.segment_id 
        WHERE s.round_id = ? 
        GROUP BY s.id 
        HAVING criteria_total != 100.00 OR criteria_total IS NULL
    ";
    $stmt2 = $conn->prepare($sql);
    $stmt2->bind_param("i", $round_id);
    $stmt2->execute();
    $invalid_segments = $stmt2->get_result();

    if ($invalid_segments->num_rows > 0) {
        $bad_seg = $invalid_segments->fetch_assoc();
        $bad_score = (float)($bad_seg['criteria_total'] ?? 0);
        return "Cannot Start: Segment '{$bad_seg['title']}' has incomplete criteria ($bad_score/100 pts).";
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

    if ($rule === 'winner') $advance = 1;

    if (empty($title)) {
        header("Location: ../public/rounds.php?error=Title is required");
        exit();
    }

    if ($rule === 'top_n') {
        $check = validateAdvancement($conn, $event_id, $order, $advance);
        if ($check !== true) { header("Location: ../public/rounds.php?error=" . urlencode($check)); exit(); }
    } else {
        $check = validateAdvancement($conn, $event_id, $order, 1);
        if ($check !== true) { header("Location: ../public/rounds.php?error=" . urlencode($check)); exit(); }
    }

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

    if ($rule === 'winner') $advance = 1;

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

// --- 3. DELETE ROUND ---
if (isset($_GET['action']) && $_GET['action'] === 'delete') {
    $id = (int)$_GET['id'];
    
    $check = $conn->query("SELECT status FROM rounds WHERE id = $id");
    if ($check->fetch_assoc()['status'] === 'Active') {
        header("Location: ../public/rounds.php?error=Cannot delete an Active round.");
        exit();
    }

    $scoreCheck = $conn->prepare("SELECT id FROM scores WHERE round_id = ? LIMIT 1");
    $scoreCheck->bind_param("i", $id);
    $scoreCheck->execute();
    
    if ($scoreCheck->get_result()->num_rows > 0) {
        header("Location: ../public/rounds.php?error=Cannot delete: Scores exist.");
        exit();
    }

    $conn->query("DELETE FROM rounds WHERE id = $id");
    header("Location: ../public/rounds.php?success=Round deleted");
    exit();
}

// --- 4. SET ACTIVE ROUND (With Strict Validation) ---
if (isset($_GET['action']) && $_GET['action'] === 'set_active') {
    $round_id = (int)$_GET['id'];
    $event_id = (int)$_GET['event_id'];

    // [NEW] Run Configuration Validation
    $configCheck = validateRoundConfiguration($conn, $round_id);
    if ($configCheck !== true) {
        header("Location: ../public/rounds.php?error=" . urlencode($configCheck));
        exit();
    }

    $conn->begin_transaction();
    try {
        // Set all to Pending
        $reset = $conn->prepare("UPDATE rounds SET status = 'Pending' WHERE event_id = ? AND status = 'Active'");
        $reset->bind_param("i", $event_id);
        $reset->execute();

        // Set selected to Active
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

// --- 5. STOP ROUND ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'stop_round') {
    $round_id = (int)$_POST['round_id'];
    $password = $_POST['password'];
    $manager_id = $_SESSION['user_id'];

    $u_stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $u_stmt->bind_param("i", $manager_id);
    $u_stmt->execute();
    $user = $u_stmt->get_result()->fetch_assoc();

    if (!password_verify($password, $user['password'])) {
        header("Location: ../public/rounds.php?error=Incorrect Password.");
        exit();
    }

    $s_check = $conn->prepare("SELECT id FROM scores WHERE round_id = ? LIMIT 1");
    $s_check->bind_param("i", $round_id);
    $s_check->execute();
    if ($s_check->get_result()->num_rows > 0) {
        header("Location: ../public/rounds.php?error=CANNOT STOP: Scores exist.");
        exit();
    }

    $stmt = $conn->prepare("UPDATE rounds SET status = 'Pending' WHERE id = ?");
    $stmt->bind_param("i", $round_id);
    $stmt->execute();

    header("Location: ../public/rounds.php?success=Round stopped.");
    exit();
}
?>