<?php
// Enable Error Reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../app/core/guard.php';
requireLogin();
requireRole('Event Manager');
require_once __DIR__ . '/../app/config/database.php';

// HELPER: Check for Time Overlaps
function checkOverlap($conn, $event_id, $date, $start, $end, $exclude_id = null) {
    // Logic: (StartA < EndB) and (EndA > StartB)
    $sql = "SELECT title, start_time, end_time FROM activities 
            WHERE event_id = ? 
            AND activity_date = ? 
            AND is_deleted = 0 
            AND ((? < end_time) AND (? > start_time))";
    
    if ($exclude_id) {
        $sql .= " AND id != " . (int)$exclude_id;
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isss", $event_id, $date, $start, $end);
    $stmt->execute();
    return $stmt->get_result();
}

// --- 1. ADD ACTIVITY ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    
    $event_id = (int)$_POST['event_id'];
    $title    = trim($_POST['title']);
    $venue    = trim($_POST['venue']);
    $date     = $_POST['activity_date'];
    $start    = $_POST['start_time'];
    $end      = $_POST['end_time'];
    $desc     = trim($_POST['description']);
    $force    = isset($_POST['force_save']) ? (int)$_POST['force_save'] : 0; // Override warning

    // 1. Basic Validation
    if (strtotime($start) >= strtotime($end)) {
        header("Location: ../public/activities.php?error=End time must be after Start time.");
        exit();
    }

    // 2. Overlap Check (Only if not forced)
    if ($force === 0) {
        $conflicts = checkOverlap($conn, $event_id, $date, $start, $end);
        if ($conflicts->num_rows > 0) {
            // Found a conflict! Send data back to UI to show Warning Modal
            $c = $conflicts->fetch_assoc();
            $msg = "Warning: This overlaps with '{$c['title']}' ({$c['start_time']} - {$c['end_time']}).";
            // Encode post data to repopulate form
            $repopulate = http_build_query($_POST);
            header("Location: ../public/activities.php?warning=" . urlencode($msg) . "&" . $repopulate);
            exit();
        }
    }

    // 3. Save
    $stmt = $conn->prepare("INSERT INTO activities (event_id, title, venue, activity_date, start_time, end_time, description) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issssss", $event_id, $title, $venue, $date, $start, $end, $desc);
    
    if ($stmt->execute()) {
        header("Location: ../public/activities.php?success=Activity scheduled successfully");
    } else {
        header("Location: ../public/activities.php?error=Database error");
    }
    exit();
}

// --- 2. UPDATE ACTIVITY ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    $id       = (int)$_POST['activity_id'];
    $event_id = (int)$_POST['event_id'];
    $title    = trim($_POST['title']);
    $venue    = trim($_POST['venue']);
    $date     = $_POST['activity_date'];
    $start    = $_POST['start_time'];
    $end      = $_POST['end_time'];
    $desc     = trim($_POST['description']);
    $force    = isset($_POST['force_save']) ? (int)$_POST['force_save'] : 0;

    if (strtotime($start) >= strtotime($end)) {
        header("Location: ../public/activities.php?error=End time must be after Start time.");
        exit();
    }

    if ($force === 0) {
        $conflicts = checkOverlap($conn, $event_id, $date, $start, $end, $id);
        if ($conflicts->num_rows > 0) {
            $c = $conflicts->fetch_assoc();
            $msg = "Warning: This overlaps with '{$c['title']}' ({$c['start_time']} - {$c['end_time']}).";
            $repopulate = http_build_query($_POST);
            header("Location: ../public/activities.php?warning=" . urlencode($msg) . "&" . $repopulate);
            exit();
        }
    }

    $stmt = $conn->prepare("UPDATE activities SET title=?, venue=?, activity_date=?, start_time=?, end_time=?, description=? WHERE id=?");
    $stmt->bind_param("ssssssi", $title, $venue, $date, $start, $end, $desc, $id);
    
    if ($stmt->execute()) {
        header("Location: ../public/activities.php?success=Activity updated");
    } else {
        header("Location: ../public/activities.php?error=Update failed");
    }
    exit();
}

// --- 3. SOFT DELETE (ARCHIVE) ---
if (isset($_GET['action']) && $_GET['action'] === 'archive') {
    $id = (int)$_GET['id'];
    $conn->query("UPDATE activities SET is_deleted = 1 WHERE id = $id");
    header("Location: ../public/activities.php?success=Activity archived");
    exit();
}

// --- 4. RESTORE (UN-ARCHIVE) ---
if (isset($_GET['action']) && $_GET['action'] === 'restore') {
    $id = (int)$_GET['id'];
    $conn->query("UPDATE activities SET is_deleted = 0 WHERE id = $id");
    header("Location: ../public/activities.php?success=Activity restored");
    exit();
}
?>