<?php
// Enable Error Reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../app/core/guard.php';
requireLogin();
requireRole('Event Manager');
require_once __DIR__ . '/../app/config/database.php';

// --- 1. ADD AWARD ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    
    $event_id = (int)$_POST['event_id'];
    $title    = trim($_POST['title']);
    $desc     = trim($_POST['description']);
    $type     = $_POST['type']; // Major, Minor, Special
    $source   = $_POST['source_type']; // Manual, Segment, Round
    
    // Determine the ID based on source selection
    $source_id = null;
    if ($source === 'Segment') $source_id = (int)$_POST['segment_id'];
    if ($source === 'Round')   $source_id = (int)$_POST['round_id'];

    // 1. Duplicate Check
    $dup = $conn->prepare("SELECT id FROM awards WHERE event_id = ? AND title = ? AND is_deleted = 0");
    $dup->bind_param("is", $event_id, $title);
    $dup->execute();
    if ($dup->get_result()->num_rows > 0) {
        header("Location: ../public/awards.php?error=Award '$title' already exists.");
        exit();
    }

    $stmt = $conn->prepare("INSERT INTO awards (event_id, title, description, type, source_type, source_id) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issssi", $event_id, $title, $desc, $type, $source, $source_id);
    
    if ($stmt->execute()) {
        header("Location: ../public/awards.php?success=Award created successfully");
    } else {
        header("Location: ../public/awards.php?error=Database error");
    }
    exit();
}

// --- 2. UPDATE AWARD ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    
    $id       = (int)$_POST['award_id'];
    $title    = trim($_POST['title']);
    $desc     = trim($_POST['description']);
    $type     = $_POST['type'];
    $source   = $_POST['source_type'];
    
    $source_id = null;
    if ($source === 'Segment') $source_id = (int)$_POST['segment_id'];
    if ($source === 'Round')   $source_id = (int)$_POST['round_id'];

    $stmt = $conn->prepare("UPDATE awards SET title=?, description=?, type=?, source_type=?, source_id=? WHERE id=?");
    $stmt->bind_param("ssssii", $title, $desc, $type, $source, $source_id, $id);
    
    if ($stmt->execute()) {
        header("Location: ../public/awards.php?success=Award updated");
    } else {
        header("Location: ../public/awards.php?error=Update failed");
    }
    exit();
}

// --- 3. ARCHIVE (Soft Delete) ---
if (isset($_GET['action']) && $_GET['action'] === 'archive') {
    $id = (int)$_GET['id'];
    $conn->query("UPDATE awards SET is_deleted = 1 WHERE id = $id");
    header("Location: ../public/awards.php?success=Award archived");
    exit();
}

// --- 4. RESTORE ---
if (isset($_GET['action']) && $_GET['action'] === 'restore') {
    $id = (int)$_GET['id'];
    $conn->query("UPDATE awards SET is_deleted = 0 WHERE id = $id");
    header("Location: ../public/awards.php?success=Award restored");
    exit();
}
?>